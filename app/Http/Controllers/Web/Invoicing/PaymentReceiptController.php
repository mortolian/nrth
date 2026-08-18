<?php

namespace App\Http\Controllers\Web\Invoicing;

use App\Domain\Invoicing\Actions\SendPaymentReceiptAction;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\Payment;
use App\Domain\Invoicing\Services\PaymentReceiptPdfService;
use App\Http\Controllers\Controller;
use App\Support\DownloadFilename;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PaymentReceiptController extends Controller
{
    public function __construct(
        private readonly PaymentReceiptPdfService $paymentReceiptPdfService,
    ) {}

    public function download(Invoice $invoice, Payment $payment): StreamedResponse|RedirectResponse
    {
        return $this->streamReceipt($invoice, $payment, asAttachment: true);
    }

    public function preview(Invoice $invoice, Payment $payment): StreamedResponse|RedirectResponse
    {
        return $this->streamReceipt($invoice, $payment, asAttachment: false);
    }

    public function send(Request $request, Invoice $invoice, Payment $payment, SendPaymentReceiptAction $sendPaymentReceiptAction): RedirectResponse
    {
        $this->authorizeTeam('invoices.manage', $request);
        $this->assertPaymentBelongsToInvoice($invoice, $payment);

        try {
            $sendPaymentReceiptAction->execute($payment);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('Payment receipt send failed', [
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->back()
                ->with('error', 'The payment receipt could not be sent. Please try again or contact support.');
        }

        return redirect()
            ->back()
            ->with('success', __('Payment receipt queued for delivery.'));
    }

    private function streamReceipt(Invoice $invoice, Payment $payment, bool $asAttachment): StreamedResponse|RedirectResponse
    {
        $this->authorizeTeam('invoices.view');
        $this->assertPaymentBelongsToInvoice($invoice, $payment);

        try {
            $media = $this->paymentReceiptPdfService->generate($payment);
        } catch (Throwable $e) {
            Log::warning('Payment receipt PDF '.($asAttachment ? 'download' : 'preview').' failed', [
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->back()
                ->with('error', 'The payment receipt could not be generated. Please try again or contact support.');
        }

        $disk = Storage::disk($media->disk);
        $path = $media->getPathRelativeToRoot();
        $stream = $disk->readStream($path);
        $fileName = DownloadFilename::sanitize(
            (string) ($media->file_name ?: ('receipt-'.$invoice->number.'-'.$payment->id.'.pdf')),
            'receipt.pdf'
        );
        $headers = [
            'Content-Type' => $media->mime_type ?: 'application/pdf',
        ];

        if ($asAttachment) {
            return response()->streamDownload(function () use ($stream): void {
                if (is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }
            }, $fileName, $headers);
        }

        $safeName = DownloadFilename::sanitize($fileName, 'receipt.pdf');
        $headers['Content-Disposition'] = 'inline; filename="'.$safeName.'"';
        $headers['Cache-Control'] = 'private, max-age=0, must-revalidate';

        return response()->stream(function () use ($stream): void {
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, $headers);
    }

    private function assertPaymentBelongsToInvoice(Invoice $invoice, Payment $payment): void
    {
        abort_unless($invoice->team_id === auth()->user()->current_team_id, 403);
        abort_unless((int) $payment->invoice_id === (int) $invoice->id, 404);
        abort_unless((int) $payment->team_id === (int) $invoice->team_id, 403);
    }
}
