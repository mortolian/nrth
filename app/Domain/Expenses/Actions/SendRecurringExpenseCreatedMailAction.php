<?php

namespace App\Domain\Expenses\Actions;

use App\Domain\Accounting\Models\Transaction;
use App\Mail\RecurringExpenseCreatedMailer;
use App\Support\TeamMailRecipients;
use Illuminate\Support\Facades\Mail;

class SendRecurringExpenseCreatedMailAction
{
    public function execute(Transaction $transaction): void
    {
        $transaction->loadMissing('team');
        $team = $transaction->team;
        if ($team === null) {
            return;
        }

        foreach (TeamMailRecipients::for($team, 'expenses.view', 'notify_recurring_expense') as $user) {
            Mail::to($user->email)->queue(new RecurringExpenseCreatedMailer($transaction));
        }
    }
}
