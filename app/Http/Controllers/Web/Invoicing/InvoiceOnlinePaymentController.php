<?php

namespace App\Http\Controllers\Web\Invoicing;

use App\Domain\Invoicing\Actions\StartInvoiceOnlinePaymentSessionAction;
use App\Domain\Invoicing\Models\Invoice;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

class InvoiceOnlinePaymentController extends Controller
{
    public function store(
        Request $request,
        Invoice $invoice,
        StartInvoiceOnlinePaymentSessionAction $startOnlinePayment,
    ): BaseResponse|Response {
        abort_unless($invoice->team_id === $request->user()->current_team_id, 403);

        $payload = $request->validate([
            'provider' => ['required', 'string', Rule::in(['stripe', 'payfast'])],
        ]);

        $team = $request->user()->currentTeam;
        abort_if($team === null, 403);

        $result = $startOnlinePayment->execute($team, $invoice, (string) $payload['provider']);

        if ($result['type'] === 'stripe_redirect') {
            return Inertia::location($result['url']);
        }

        return Inertia::render('Invoicing/Invoices/PayFastRedirect', [
            'action' => $result['action'],
            'fields' => $result['fields'],
        ]);
    }
}
