<?php

use App\Domain\Invoicing\Enums\InvoiceStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Client open-tracking is paused until a public portal exists.
        // Fold legacy "viewed" invoices back into "sent" so actions/filters stay consistent.
        DB::table('invoices')
            ->where('status', InvoiceStatus::Viewed->value)
            ->update(['status' => InvoiceStatus::Sent->value]);
    }

    public function down(): void
    {
        // Irreversible: cannot restore which invoices were viewed.
    }
};
