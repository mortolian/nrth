<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('bank_name')->nullable();
            $table->string('bank_account_holder')->nullable();
            $table->string('bank_account_number', 64)->nullable();
            $table->string('bank_branch_code', 32)->nullable();
            $table->string('bank_account_type', 32)->default('current');
            $table->boolean('show_on_invoice')->default(true);
            $table->timestamps();
        });

        foreach (DB::table('teams')->cursor() as $team) {
            $raw = $team->company_settings ?? null;
            $settings = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : []);
            if (! is_array($settings)) {
                $settings = [];
            }
            $name = $settings['bank_name'] ?? null;
            $holder = $settings['bank_account_holder'] ?? null;
            $number = $settings['bank_account_number'] ?? null;
            $branch = $settings['bank_branch_code'] ?? null;
            $type = $settings['bank_account_type'] ?? 'current';
            if (! in_array($type, ['current', 'savings'], true)) {
                $type = 'current';
            }
            $hasAny = ($name !== null && $name !== '')
                || ($holder !== null && $holder !== '')
                || ($number !== null && $number !== '')
                || ($branch !== null && $branch !== '');
            if (! $hasAny) {
                continue;
            }
            DB::table('team_bank_accounts')->insert([
                'team_id' => $team->id,
                'sort_order' => 0,
                'bank_name' => $name ?: null,
                'bank_account_holder' => $holder ?: null,
                'bank_account_number' => $number ?: null,
                'bank_branch_code' => $branch ?: null,
                'bank_account_type' => $type,
                'show_on_invoice' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('team_bank_accounts');
    }
};
