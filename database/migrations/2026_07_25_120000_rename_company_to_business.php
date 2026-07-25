<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('teams') && Schema::hasColumn('teams', 'company_settings') && ! Schema::hasColumn('teams', 'business_settings')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->renameColumn('company_settings', 'business_settings');
            });
        }

        if (Schema::hasTable('teams') && Schema::hasColumn('teams', 'business_settings')) {
            DB::table('teams')->orderBy('id')->chunkById(100, function ($teams): void {
                foreach ($teams as $team) {
                    $raw = $team->business_settings ?? null;
                    if ($raw === null || $raw === '') {
                        continue;
                    }

                    $settings = is_string($raw) ? json_decode($raw, true) : (array) $raw;
                    if (! is_array($settings)) {
                        continue;
                    }

                    $changed = false;

                    foreach (['email', 'phone', 'website'] as $suffix) {
                        $old = 'company_'.$suffix;
                        $new = 'business_'.$suffix;
                        if (array_key_exists($old, $settings)) {
                            if (! array_key_exists($new, $settings)) {
                                $settings[$new] = $settings[$old];
                            }
                            unset($settings[$old]);
                            $changed = true;
                        }
                    }

                    foreach (['invoice_email_subject_template', 'invoice_email_body_template'] as $key) {
                        if (! isset($settings[$key]) || ! is_string($settings[$key])) {
                            continue;
                        }
                        $updated = str_replace('{{company}}', '{{business}}', $settings[$key]);
                        if ($updated !== $settings[$key]) {
                            $settings[$key] = $updated;
                            $changed = true;
                        }
                    }

                    if ($changed) {
                        DB::table('teams')->where('id', $team->id)->update([
                            'business_settings' => json_encode($settings),
                        ]);
                    }
                }
            });
        }

        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table): void {
                if (Schema::hasColumn('invoices', 'company_currency_code') && ! Schema::hasColumn('invoices', 'business_currency_code')) {
                    $table->renameColumn('company_currency_code', 'business_currency_code');
                }
                if (Schema::hasColumn('invoices', 'fx_rate_invoice_to_company') && ! Schema::hasColumn('invoices', 'fx_rate_invoice_to_business')) {
                    $table->renameColumn('fx_rate_invoice_to_company', 'fx_rate_invoice_to_business');
                }
                if (Schema::hasColumn('invoices', 'total_company_currency_cents') && ! Schema::hasColumn('invoices', 'total_business_currency_cents')) {
                    $table->renameColumn('total_company_currency_cents', 'total_business_currency_cents');
                }
            });
        }

        if (Schema::hasTable('payments')
            && Schema::hasColumn('payments', 'bank_amount_company_cents')
            && ! Schema::hasColumn('payments', 'bank_amount_business_cents')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->renameColumn('bank_amount_company_cents', 'bank_amount_business_cents');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payments')
            && Schema::hasColumn('payments', 'bank_amount_business_cents')
            && ! Schema::hasColumn('payments', 'bank_amount_company_cents')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->renameColumn('bank_amount_business_cents', 'bank_amount_company_cents');
            });
        }

        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table): void {
                if (Schema::hasColumn('invoices', 'business_currency_code') && ! Schema::hasColumn('invoices', 'company_currency_code')) {
                    $table->renameColumn('business_currency_code', 'company_currency_code');
                }
                if (Schema::hasColumn('invoices', 'fx_rate_invoice_to_business') && ! Schema::hasColumn('invoices', 'fx_rate_invoice_to_company')) {
                    $table->renameColumn('fx_rate_invoice_to_business', 'fx_rate_invoice_to_company');
                }
                if (Schema::hasColumn('invoices', 'total_business_currency_cents') && ! Schema::hasColumn('invoices', 'total_company_currency_cents')) {
                    $table->renameColumn('total_business_currency_cents', 'total_company_currency_cents');
                }
            });
        }

        if (Schema::hasTable('teams') && Schema::hasColumn('teams', 'business_settings')) {
            DB::table('teams')->orderBy('id')->chunkById(100, function ($teams): void {
                foreach ($teams as $team) {
                    $raw = $team->business_settings ?? null;
                    if ($raw === null || $raw === '') {
                        continue;
                    }

                    $settings = is_string($raw) ? json_decode($raw, true) : (array) $raw;
                    if (! is_array($settings)) {
                        continue;
                    }

                    $changed = false;

                    foreach (['email', 'phone', 'website'] as $suffix) {
                        $old = 'business_'.$suffix;
                        $new = 'company_'.$suffix;
                        if (array_key_exists($old, $settings)) {
                            if (! array_key_exists($new, $settings)) {
                                $settings[$new] = $settings[$old];
                            }
                            unset($settings[$old]);
                            $changed = true;
                        }
                    }

                    foreach (['invoice_email_subject_template', 'invoice_email_body_template'] as $key) {
                        if (! isset($settings[$key]) || ! is_string($settings[$key])) {
                            continue;
                        }
                        $updated = str_replace('{{business}}', '{{company}}', $settings[$key]);
                        if ($updated !== $settings[$key]) {
                            $settings[$key] = $updated;
                            $changed = true;
                        }
                    }

                    if ($changed) {
                        DB::table('teams')->where('id', $team->id)->update([
                            'business_settings' => json_encode($settings),
                        ]);
                    }
                }
            });
        }

        if (Schema::hasTable('teams') && Schema::hasColumn('teams', 'business_settings') && ! Schema::hasColumn('teams', 'company_settings')) {
            Schema::table('teams', function (Blueprint $table) {
                $table->renameColumn('business_settings', 'company_settings');
            });
        }
    }
};
