<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_bank_accounts', function (Blueprint $table) {
            $table->string('swift_code', 32)->nullable()->after('bank_account_number');
            $table->string('bic', 32)->nullable()->after('swift_code');
            $table->string('iban', 64)->nullable()->after('bic');
            $table->string('routing_sort_code', 64)->nullable()->after('iban');
        });
    }

    public function down(): void
    {
        Schema::table('team_bank_accounts', function (Blueprint $table) {
            $table->dropColumn(['swift_code', 'bic', 'iban', 'routing_sort_code']);
        });
    }
};
