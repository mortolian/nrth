<?php

namespace App\Domain\Invoicing\Services;

use App\Domain\Invoicing\Models\Payment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class PaymentReceiptPdfService
{
    public function generate(Payment $payment): Media
    {
        $tmpPath = $this->renderToTemporaryPath($payment);
        $payment = $payment->fresh(['invoice', 'team', 'invoice.client']);
        if ($payment === null) {
            File::delete($tmpPath);
            throw new \RuntimeException('Payment not found.');
        }

        $invoiceNumber = (string) ($payment->invoice?->number ?? 'invoice');
        $fileName = 'receipt-'.$invoiceNumber.'-'.$payment->id.'.pdf';

        try {
            return $payment
                ->addMedia($tmpPath)
                ->usingName('Payment receipt '.$payment->id)
                ->usingFileName($fileName)
                ->toMediaCollection('payment-receipts');
        } finally {
            File::delete($tmpPath);
        }
    }

    /**
     * Render the payment receipt PDF to a temp path. Caller must delete the file when done.
     */
    public function renderToTemporaryPath(Payment $payment): string
    {
        $payment = $payment->fresh(['invoice.client', 'invoice.payments', 'team']);
        if ($payment === null || $payment->invoice === null) {
            throw new \RuntimeException('Payment or invoice not found.');
        }

        $tmpPath = storage_path('app/tmp/payment-receipt-'.$payment->id.'-'.uniqid().'.pdf');
        File::ensureDirectoryExists(dirname($tmpPath));

        Pdf::loadView('pdf.payment-receipt', [
            'payment' => $payment,
            'invoice' => $payment->invoice,
            'totals' => $this->totalsAsOfPayment($payment),
        ])
            ->setPaper('a4')
            ->setOptions([
                'isRemoteEnabled' => true,
                'isPhpEnabled' => false,
                'defaultFont' => 'DejaVu Sans',
                'dpi' => 96,
            ])
            ->save($tmpPath);

        return $tmpPath;
    }

    /**
     * Invoice totals relative to this payment (paid through this receipt, then outstanding).
     *
     * @return array{invoice_total_cents: int, paid_through_cents: int, outstanding_cents: int, payment_cents: int}
     */
    public function totalsAsOfPayment(Payment $payment): array
    {
        $invoice = $payment->invoice;
        $invoiceTotal = (int) $invoice->getRawOriginal('total_cents');
        $paymentCents = (int) $payment->getRawOriginal('amount_cents');
        $paymentDate = optional($payment->payment_date)->toDateString();

        $paidThrough = (int) $invoice->payments()
            ->where(function ($query) use ($payment, $paymentDate): void {
                $query->whereDate('payment_date', '<', $paymentDate)
                    ->orWhere(function ($sameDay) use ($payment, $paymentDate): void {
                        $sameDay->whereDate('payment_date', $paymentDate)
                            ->where('id', '<=', $payment->id);
                    });
            })
            ->sum('amount_cents');

        return [
            'invoice_total_cents' => $invoiceTotal,
            'payment_cents' => $paymentCents,
            'paid_through_cents' => $paidThrough,
            'outstanding_cents' => max(0, $invoiceTotal - $paidThrough),
        ];
    }
}
