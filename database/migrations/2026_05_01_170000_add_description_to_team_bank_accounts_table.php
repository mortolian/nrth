<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_bank_accounts', function (Blueprint $table) {
            $table->text('description')->nullable()->after('bank_account_type');
        });
    }

    public function down(): void
    {
        Schema::table('team_bank_accounts', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
