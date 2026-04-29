<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Enums\QuoteStatus;
use App\Domain\Invoicing\Models\Quote;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarkQuoteSentAction
{
    public function execute(Quote $quote): Quote
    {
        if ($quote->status !== QuoteStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => __('Only draft quotes can be marked as sent.'),
            ]);
        }

        return DB::transaction(function () use ($quote): Quote {
            $quote->status = QuoteStatus::Sent;
            $quote->sent_at = now();
            $quote->save();

            if (function_exists('activity')) {
                activity()
                    ->performedOn($quote)
                    ->withProperties(['status' => QuoteStatus::Sent->value, 'manual' => true])
                    ->log('quote_marked_sent');
            }

            return $quote->refresh();
        });
    }
}

