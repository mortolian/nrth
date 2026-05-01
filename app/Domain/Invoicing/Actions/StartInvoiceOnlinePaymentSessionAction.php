<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\OnlinePaymentSessionStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceOnlinePaymentSession;
use App\Models\Team;
use App\Support\Iso4217Currencies;
use App\Support\PayFastSignature;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Stripe\Checkout\Session;
use Stripe\StripeClient;

class StartInvoiceOnlinePaymentSessionAction
{
    /**
     * Optional checkout return URLs (e.g. public pay page). Keys: stripe_success, stripe_cancel, payfast_return, payfast_cancel.
     * Stripe success URL must accept Stripe’s `{CHECKOUT_SESSION_ID}` placeholder in the query string.
     *
     * @param  array<string, string>|null  $checkoutReturnUrls
     * @return array{type: 'stripe_redirect', url: string}|array{type: 'payfast_form', action: string, fields: array<string, string>}
     */
    public function execute(Team $team, Invoice $invoice, string $provider, ?array $checkoutReturnUrls = null): array
    {
        $provider = strtolower(trim($provider));

        if ($invoice->team_id !== $team->id) {
            throw ValidationException::withMessages([
                'invoice' => __('Invoice does not belong to this team.'),
            ]);
        }

        if (! in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Partial, InvoiceStatus::Overdue], true)) {
            throw ValidationException::withMessages([
                'invoice' => __('This invoice cannot be paid online in its current state.'),
            ]);
        }

        $totalCents = (int) $invoice->getRawOriginal('total_cents');
        $paidCents = (int) $invoice->getRawOriginal('amount_paid_cents');
        $amountDue = max(0, $totalCents - $paidCents);
        if ($amountDue < 1) {
            throw ValidationException::withMessages([
                'invoice' => __('Nothing is due on this invoice.'),
            ]);
        }

        $currency = Iso4217Currencies::normalize((string) ($invoice->currency ?? 'ZAR'));
        $settings = $team->mergedCompanySettings();
        /** @var array<string, mixed> $gateways */
        $gateways = is_array($settings['payment_gateways'] ?? null) ? $settings['payment_gateways'] : [];

        return match ($provider) {
            'stripe' => $this->startStripe($team, $invoice, $amountDue, $currency, $gateways, $checkoutReturnUrls),
            'payfast' => $this->startPayFast($team, $invoice, $amountDue, $currency, $gateways, $checkoutReturnUrls),
            default => throw ValidationException::withMessages([
                'provider' => __('This payment provider is not supported yet.'),
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $gateways
     * @param  array<string, string>|null  $checkoutReturnUrls
     * @return array{type: 'stripe_redirect', url: string}
     */
    private function startStripe(Team $team, Invoice $invoice, int $amountDueCents, string $currency, array $gateways, ?array $checkoutReturnUrls): array
    {
        /** @var array<string, mixed> $cfg */
        $cfg = is_array($gateways['stripe'] ?? null) ? $gateways['stripe'] : [];
        if (! ($cfg['enabled'] ?? false)) {
            throw ValidationException::withMessages([
                'provider' => __('Stripe is not enabled for this company.'),
            ]);
        }
        $secret = isset($cfg['secret_key']) && is_string($cfg['secret_key']) ? trim($cfg['secret_key']) : '';
        if ($secret === '') {
            throw ValidationException::withMessages([
                'provider' => __('Stripe secret key is not configured.'),
            ]);
        }

        $session = InvoiceOnlinePaymentSession::query()->create([
            'team_id' => $team->id,
            'invoice_id' => $invoice->id,
            'provider' => 'stripe',
            'status' => OnlinePaymentSessionStatus::Pending,
            'amount_cents' => $amountDueCents,
            'currency' => $currency,
            'metadata' => [
                'invoice_number' => $invoice->number,
            ],
        ]);

        $stripe = new StripeClient(['api_key' => $secret]);

        $defaultShow = URL::route('invoicing.invoices.show', ['invoice' => $invoice->id]);
        $successUrl = $checkoutReturnUrls['stripe_success'] ?? ($defaultShow.'?online_payment=success&session_id={CHECKOUT_SESSION_ID}');
        $cancelUrl = $checkoutReturnUrls['stripe_cancel'] ?? ($defaultShow.'?online_payment=cancelled');

        $checkout = $stripe->checkout->sessions->create([
            'mode' => 'payment',
            'client_reference_id' => (string) $session->id,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'team_id' => (string) $team->id,
                'invoice_id' => (string) $invoice->id,
                'nrth_session_id' => (string) $session->id,
            ],
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($currency),
                    'product_data' => [
                        'name' => 'Invoice '.$invoice->number,
                    ],
                    'unit_amount' => $amountDueCents,
                ],
                'quantity' => 1,
            ]],
        ]);

        if (! $checkout instanceof Session || ! is_string($checkout->url) || $checkout->url === '') {
            throw ValidationException::withMessages([
                'provider' => __('Could not start Stripe Checkout.'),
            ]);
        }

        $session->forceFill([
            'provider_checkout_id' => $checkout->id,
        ])->save();

        return ['type' => 'stripe_redirect', 'url' => $checkout->url];
    }

    /**
     * @param  array<string, mixed>  $gateways
     * @param  array<string, string>|null  $checkoutReturnUrls
     * @return array{type: 'payfast_form', action: string, fields: array<string, string>}
     */
    private function startPayFast(Team $team, Invoice $invoice, int $amountDueCents, string $currency, array $gateways, ?array $checkoutReturnUrls): array
    {
        if ($currency !== 'ZAR') {
            throw ValidationException::withMessages([
                'provider' => __('PayFast only supports invoices in ZAR.'),
            ]);
        }

        /** @var array<string, mixed> $cfg */
        $cfg = is_array($gateways['payfast'] ?? null) ? $gateways['payfast'] : [];
        if (! ($cfg['enabled'] ?? false)) {
            throw ValidationException::withMessages([
                'provider' => __('PayFast is not enabled for this company.'),
            ]);
        }

        $merchantId = isset($cfg['merchant_id']) && is_string($cfg['merchant_id']) ? trim($cfg['merchant_id']) : '';
        $merchantKey = isset($cfg['merchant_key']) && is_string($cfg['merchant_key']) ? trim($cfg['merchant_key']) : '';
        $passphrase = isset($cfg['passphrase']) && is_string($cfg['passphrase']) ? trim($cfg['passphrase']) : '';

        if ($merchantId === '' || $merchantKey === '') {
            throw ValidationException::withMessages([
                'provider' => __('PayFast merchant credentials are not configured.'),
            ]);
        }

        $session = InvoiceOnlinePaymentSession::query()->create([
            'team_id' => $team->id,
            'invoice_id' => $invoice->id,
            'provider' => 'payfast',
            'status' => OnlinePaymentSessionStatus::Pending,
            'amount_cents' => $amountDueCents,
            'currency' => $currency,
            'metadata' => [
                'invoice_number' => $invoice->number,
            ],
        ]);

        $amountMajor = number_format($amountDueCents / 100, 2, '.', '');
        $mPaymentId = 'nrth-'.$session->id.'-'.bin2hex(random_bytes(4));

        $session->forceFill([
            'provider_checkout_id' => $mPaymentId,
        ])->save();

        $notifyUrl = URL::route('webhooks.payfast', ['team' => $team->id]);
        $defaultShow = URL::route('invoicing.invoices.show', ['invoice' => $invoice->id]);
        $returnUrl = $checkoutReturnUrls['payfast_return'] ?? ($defaultShow.'?online_payment=success');
        $cancelUrl = $checkoutReturnUrls['payfast_cancel'] ?? ($defaultShow.'?online_payment=cancelled');

        $invoice->loadMissing('client');
        $settings = $team->mergedCompanySettings();
        $email = trim((string) ($invoice->client?->email ?? ''));
        if ($email === '') {
            $email = trim((string) ($settings['company_email'] ?? ''));
        }
        if ($email === '') {
            throw ValidationException::withMessages([
                'invoice' => __('Add a client email or company email in settings before using PayFast.'),
            ]);
        }

        $fields = [
            'merchant_id' => $merchantId,
            'merchant_key' => $merchantKey,
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
            'notify_url' => $notifyUrl,
            'name_first' => 'Invoice',
            'name_last' => $invoice->number,
            'email_address' => $email,
            'm_payment_id' => $mPaymentId,
            'amount' => $amountMajor,
            'item_name' => 'Invoice '.$invoice->number,
        ];

        $signature = PayFastSignature::build($fields, $passphrase !== '' ? $passphrase : null);
        $fields['signature'] = $signature;

        /** @var array<string, string> $stringFields */
        $stringFields = array_map(static fn (mixed $v): string => (string) $v, $fields);

        $sandbox = (bool) config('services.payfast.sandbox', true);
        $action = $sandbox
            ? 'https://sandbox.payfast.co.za/eng/process'
            : 'https://www.payfast.co.za/eng/process';

        return [
            'type' => 'payfast_form',
            'action' => $action,
            'fields' => $stringFields,
        ];
    }
}
