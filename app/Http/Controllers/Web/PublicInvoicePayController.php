<?php

namespace App\Http\Controllers\Web;

use App\Domain\Invoicing\Actions\StartInvoiceOnlinePaymentSessionAction;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Services\InvoicePdfService;
use App\Http\Controllers\Controller;
use App\Support\InvoiceOnlinePaymentProviders;
use App\Support\Iso4217Currencies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PublicInvoicePayController extends Controller
{
    public function show(Request $request, string $token): Response|RedirectResponse
    {
        $invoice = $this->invoiceForToken($token);
        if ($invoice === null) {
            abort(404);
        }

        $this->abortUnlessPaymentPagesEnabled($invoice);

        if ($invoice->viewed_at === null && ! in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Void], true)) {
            $invoice->forceFill(['viewed_at' => now()])->save();
        }

        $invoice->loadMissing(['team', 'client', 'lineItems']);
        $team = $invoice->team;

        $issuer = $team !== null
            ? $team->issuerForInvoicingDocuments()
            : [
                'name' => (string) config('app.name'),
                'address' => null,
                'email' => null,
                'phone' => null,
                'website' => null,
                'registration_number' => null,
                'vat_number' => null,
            ];

        $totalCents = (int) $invoice->getRawOriginal('total_cents');
        $paidCents = (int) $invoice->getRawOriginal('amount_paid_cents');
        $amountDueCents = max(0, $totalCents - $paidCents);

        return Inertia::render('Public/InvoicePay', [
            'issuer' => $issuer,
            'checkout_url' => URL::route('public.invoice.checkout', ['token' => $token]),
            'flash_error' => $request->session()->pull('error'),
            'invoice' => [
                'number' => $invoice->number,
                'status' => $invoice->status->value,
                'reference' => $invoice->reference,
                'issue_date' => optional($invoice->issue_date)->toDateString(),
                'due_date' => optional($invoice->due_date)->toDateString(),
                'currency' => Iso4217Currencies::normalize((string) ($invoice->currency ?? 'ZAR')),
                'subtotal_cents' => (int) $invoice->getRawOriginal('subtotal_cents'),
                'vat_amount_cents' => (int) $invoice->getRawOriginal('vat_amount_cents'),
                'total_cents' => $totalCents,
                'amount_paid_cents' => $paidCents,
                'amount_due_cents' => $amountDueCents,
                'notes' => $invoice->notes,
                'footer' => $invoice->footer,
                'client' => [
                    'name' => $invoice->client?->name,
                    'email' => $invoice->client?->email,
                ],
                'line_items' => $invoice->lineItems->map(fn ($item) => [
                    'description' => $item->description,
                    'quantity' => (float) $item->quantity,
                    'unit_price_cents' => (int) $item->unit_price_cents,
                    'vat_rate' => (float) $item->vat_rate,
                    'vat_amount_cents' => (int) $item->vat_amount_cents,
                    'total_cents' => (int) $item->total_cents,
                ])->values()->all(),
            ],
            'charges_vat' => $team?->chargesVat() ?? false,
            'online_payment_providers' => InvoiceOnlinePaymentProviders::enabledForInvoice($invoice),
            'pdf_url' => route('public.invoice.pdf', ['token' => $token]),
            'flash_online_payment' => $request->query('online_payment'),
        ]);
    }

    public function checkout(
        Request $request,
        string $token,
        StartInvoiceOnlinePaymentSessionAction $startOnlinePayment,
    ): BaseResponse|Response {
        $invoice = $this->invoiceForToken($token);
        if ($invoice === null) {
            abort(404);
        }

        $this->abortUnlessPaymentPagesEnabled($invoice);

        $team = $invoice->team ?? $invoice->loadMissing('team')->team;
        if ($team === null) {
            abort(404);
        }

        $payload = $request->validate([
            'provider' => ['required', 'string', Rule::in(['stripe', 'payfast'])],
        ]);

        $payBase = URL::route('public.invoice.pay', ['token' => $token]);
        $checkoutReturnUrls = [
            'stripe_success' => $payBase.'?online_payment=success&session_id={CHECKOUT_SESSION_ID}',
            'stripe_cancel' => $payBase.'?online_payment=cancelled',
            'payfast_return' => $payBase.'?online_payment=success',
            'payfast_cancel' => $payBase.'?online_payment=cancelled',
        ];

        $result = $startOnlinePayment->execute(
            $team,
            $invoice,
            (string) $payload['provider'],
            $checkoutReturnUrls,
        );

        if ($result['type'] === 'stripe_redirect') {
            return Inertia::location($result['url']);
        }

        return Inertia::render('Invoicing/Invoices/PayFastRedirect', [
            'action' => $result['action'],
            'fields' => $result['fields'],
        ]);
    }

    public function pdf(string $token, InvoicePdfService $invoicePdfService): StreamedResponse|RedirectResponse
    {
        $invoice = $this->invoiceForToken($token);
        if ($invoice === null) {
            abort(404);
        }

        $this->abortUnlessPaymentPagesEnabled($invoice);

        try {
            $media = $invoicePdfService->generate($invoice->fresh());
        } catch (Throwable $e) {
            Log::warning('Public invoice PDF failed', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->route('public.invoice.pay', ['token' => $token])
                ->with('error', __('The PDF could not be generated. Please try again later.'));
        }

        $disk = Storage::disk($media->disk);
        $path = $media->getPathRelativeToRoot();
        $stream = $disk->readStream($path);

        return response()->streamDownload(function () use ($stream): void {
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, $media->file_name, [
            'Content-Type' => $media->mime_type ?: 'application/pdf',
        ]);
    }

    private function invoiceForToken(string $token): ?Invoice
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }

        $invoice = Invoice::queryWithoutTeamScope()
            ->where('public_token', $token)
            ->first();

        if ($invoice === null) {
            return null;
        }

        if (in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Void], true)) {
            return null;
        }

        return $invoice;
    }

    private function abortUnlessPaymentPagesEnabled(Invoice $invoice): void
    {
        $invoice->loadMissing('team');
        if (! InvoiceOnlinePaymentProviders::paymentPagesEnabledForTeam($invoice->team)) {
            abort(404);
        }
    }
}
