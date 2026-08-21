<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Former "inactive" assets become archived (soft-deleted).
        DB::table('wealth_assets')
            ->where('is_active', false)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);
    }

    public function down(): void
    {
        // Best-effort: restore rows soft-deleted by this migration cannot be distinguished
        // from intentional archives, so down is a no-op.
    }
};
