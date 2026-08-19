<?php

use App\Support\MediaDisks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * One-off: drop unused `contracts` before anyone relies on the table.
 * Additive up() still applies to every other post-2026-08-18 migration.
 * Allowlisted in AdditiveMigrationPolicy::destructiveUpAllowlist().
 */
return new class extends Migration
{
    private const CONTRACT_MODEL = 'App\\Domain\\Contracting\\Models\\Contract';

    public function up(): void
    {
        if (Schema::hasTable('media')) {
            $ids = DB::table('media')
                ->where('model_type', self::CONTRACT_MODEL)
                ->pluck('id');

            $disk = Storage::disk(MediaDisks::private());
            foreach ($ids as $id) {
                $disk->deleteDirectory((string) $id);
            }

            if ($ids->isNotEmpty()) {
                DB::table('media')->whereIn('id', $ids)->delete();
            }
        }

        Schema::dropIfExists('contracts');
    }

    public function down(): void
    {
        if (Schema::hasTable('contracts')) {
            return;
        }

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('title');
            $table->string('status', 20)->default('draft');
            $table->string('billing_type', 30);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->unsignedBigInteger('contract_value_cents')->default(0);
            $table->unsignedBigInteger('hourly_rate_cents')->default(0);
            $table->unsignedBigInteger('monthly_amount_cents')->default(0);
            $table->string('payment_terms')->nullable();
            $table->longText('scope_of_work')->nullable();
            $table->date('next_invoice_due_date')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'billing_type']);
        });
    }
};
