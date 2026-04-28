<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('company_currency_code', 3)->nullable()->after('currency');
            $table->decimal('fx_rate_invoice_to_company', 20, 10)->nullable()->after('company_currency_code');
            $table->date('fx_rate_date')->nullable()->after('fx_rate_invoice_to_company');
            $table->unsignedBigInteger('total_company_currency_cents')->nullable()->after('fx_rate_date');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn([
                'company_currency_code',
                'fx_rate_invoice_to_company',
                'fx_rate_date',
                'total_company_currency_cents',
            ]);
        });
    }
};
