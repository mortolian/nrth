<?php

namespace App\Http\Controllers\Web\Webhooks;

use App\Domain\Invoicing\Actions\CompleteInvoiceOnlinePaymentSessionAction;
use App\Domain\Invoicing\Models\InvoiceOnlinePaymentSession;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Support\Iso4217Currencies;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Stripe\Event;
use Stripe\Webhook;
use Throwable;

class StripePaymentWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        Team $team,
        CompleteInvoiceOnlinePaymentSessionAction $complete,
    ): Response {
        $settings = $team->mergedCompanySettings();
        /** @var array<string, mixed> $gateways */
        $gateways = is_array($settings['payment_gateways'] ?? null) ? $settings['payment_gateways'] : [];
        /** @var array<string, mixed> $stripe */
        $stripe = is_array($gateways['stripe'] ?? null) ? $gateways['stripe'] : [];
        $secret = isset($stripe['webhook_secret']) && is_string($stripe['webhook_secret']) ? trim($stripe['webhook_secret']) : '';
        if ($secret === '') {
            return response('Webhook not configured', 400);
        }

        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature') ?? '';

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (Throwable) {
            return response('Invalid signature', 400);
        }

        if (! $event instanceof Event || $event->type !== 'checkout.session.completed') {
            return response('OK', 200);
        }

        $obj = $event->data->object;
        $sessionId = is_object($obj) && isset($obj->id) && is_string($obj->id) ? $obj->id : null;
        if ($sessionId === null) {
            return response('OK', 200);
        }

        $metaTeam = is_object($obj) && isset($obj->metadata) && is_object($obj->metadata)
            ? ($obj->metadata->team_id ?? null)
            : null;
        if ((string) $metaTeam !== (string) $team->id) {
            return response('Team mismatch', 400);
        }

        $paymentStatus = is_object($obj) && isset($obj->payment_status) ? $obj->payment_status : null;
        if ($paymentStatus !== 'paid') {
            return response('OK', 200);
        }

        $amountTotal = is_object($obj) && isset($obj->amount_total) ? (int) $obj->amount_total : null;
        $currencyRaw = is_object($obj) && isset($obj->currency) && is_string($obj->currency) ? $obj->currency : '';
        $currency = Iso4217Currencies::normalize(strtoupper($currencyRaw));

        $dbSession = InvoiceOnlinePaymentSession::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('provider', 'stripe')
            ->where('provider_checkout_id', $sessionId)
            ->first();

        if ($dbSession === null) {
            return response('Session not found', 404);
        }

        if ($amountTotal !== (int) $dbSession->amount_cents) {
            return response('Amount mismatch', 400);
        }

        if ($currency !== Iso4217Currencies::normalize((string) $dbSession->currency)) {
            return response('Currency mismatch', 400);
        }

        $pi = is_object($obj) && isset($obj->payment_intent) ? $obj->payment_intent : null;
        $ref = is_string($pi) && $pi !== '' ? $pi : $sessionId;

        try {
            $complete->execute($dbSession, $amountTotal, $ref, 'Stripe Checkout');
        } catch (ValidationException) {
            return response('Unprocessable', 422);
        }

        return response('OK', 200);
    }
}
