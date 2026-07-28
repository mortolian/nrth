<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_line_items', function (Blueprint $table): void {
            $table->string('discount_type', 10)->nullable()->after('vat_rate');
            $table->decimal('discount_percent', 5, 2)->nullable()->after('discount_type');
            $table->unsignedBigInteger('discount_cents')->nullable()->after('discount_percent');
            $table->unsignedBigInteger('discount_amount_cents')->default(0)->after('discount_cents');
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->string('discount_type', 10)->nullable()->after('total_cents');
            $table->decimal('discount_percent', 5, 2)->nullable()->after('discount_type');
            $table->unsignedBigInteger('discount_cents')->nullable()->after('discount_percent');
            $table->unsignedBigInteger('discount_total_cents')->default(0)->after('discount_cents');
        });

        Schema::table('estimates', function (Blueprint $table): void {
            $table->string('discount_type', 10)->nullable()->after('total_cents');
            $table->decimal('discount_percent', 5, 2)->nullable()->after('discount_type');
            $table->unsignedBigInteger('discount_cents')->nullable()->after('discount_percent');
            $table->unsignedBigInteger('discount_total_cents')->default(0)->after('discount_cents');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_line_items', function (Blueprint $table): void {
            $table->dropColumn(['discount_type', 'discount_percent', 'discount_cents', 'discount_amount_cents']);
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['discount_type', 'discount_percent', 'discount_cents', 'discount_total_cents']);
        });

        Schema::table('estimates', function (Blueprint $table): void {
            $table->dropColumn(['discount_type', 'discount_percent', 'discount_cents', 'discount_total_cents']);
        });
    }
};
