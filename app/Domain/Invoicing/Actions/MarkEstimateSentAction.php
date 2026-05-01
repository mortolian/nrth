<?php

namespace App\Domain\Invoicing\Actions;

use App\Domain\Invoicing\Enums\EstimateStatus;
use App\Domain\Invoicing\Models\Estimate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarkEstimateSentAction
{
    public function execute(Estimate $estimate): Estimate
    {
        if ($estimate->status !== EstimateStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => __('Only draft estimates can be marked as sent.'),
            ]);
        }

        return DB::transaction(function () use ($estimate): Estimate {
            $estimate->status = EstimateStatus::Sent;
            $estimate->sent_at = now();
            $estimate->save();

            if (function_exists('activity')) {
                activity()
                    ->performedOn($estimate)
                    ->withProperties(['status' => EstimateStatus::Sent->value, 'manual' => true])
                    ->log('estimate_marked_sent');
            }

            return $estimate->refresh();
        });
    }
}
