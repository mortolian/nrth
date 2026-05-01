<?php

namespace App\Http\Controllers\Web\Webhooks;

use App\Domain\Invoicing\Actions\CompleteInvoiceOnlinePaymentSessionAction;
use App\Domain\Invoicing\Models\InvoiceOnlinePaymentSession;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Support\PayFastSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class PayFastPaymentWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        Team $team,
        CompleteInvoiceOnlinePaymentSessionAction $complete,
    ): Response {
        $settings = $team->mergedCompanySettings();
        /** @var array<string, mixed> $gateways */
        $gateways = is_array($settings['payment_gateways'] ?? null) ? $settings['payment_gateways'] : [];
        /** @var array<string, mixed> $payfast */
        $payfast = is_array($gateways['payfast'] ?? null) ? $gateways['payfast'] : [];
        $passphrase = isset($payfast['passphrase']) && is_string($payfast['passphrase']) ? trim($payfast['passphrase']) : '';

        /** @var array<string, mixed> $posted */
        $posted = $request->all();
        if (! PayFastSignature::verifyPosted($posted, $passphrase !== '' ? $passphrase : null)) {
            return response('Invalid signature', 400);
        }

        $paymentStatus = isset($posted['payment_status']) ? (string) $posted['payment_status'] : '';
        if ($paymentStatus !== 'COMPLETE') {
            return response('OK', 200);
        }

        $mPaymentId = isset($posted['m_payment_id']) ? (string) $posted['m_payment_id'] : '';
        if ($mPaymentId === '') {
            return response('Bad payload', 400);
        }

        $gross = $posted['amount_gross'] ?? null;
        if (! is_numeric($gross)) {
            return response('Bad amount', 400);
        }

        $paidCents = (int) round(((float) $gross) * 100);

        $dbSession = InvoiceOnlinePaymentSession::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('provider', 'payfast')
            ->where('provider_checkout_id', $mPaymentId)
            ->first();

        if ($dbSession === null) {
            return response('Session not found', 404);
        }

        $pfPaymentId = isset($posted['pf_payment_id']) ? (string) $posted['pf_payment_id'] : $mPaymentId;

        try {
            $complete->execute($dbSession, $paidCents, $pfPaymentId, 'PayFast');
        } catch (ValidationException) {
            return response('Unprocessable', 422);
        }

        return response('OK', 200);
    }
}
