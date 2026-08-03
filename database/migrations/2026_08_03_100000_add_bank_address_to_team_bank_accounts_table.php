<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_bank_accounts', function (Blueprint $table) {
            $table->text('bank_address')->nullable()->after('routing_sort_code');
        });
    }

    public function down(): void
    {
        Schema::table('team_bank_accounts', function (Blueprint $table) {
            $table->dropColumn('bank_address');
        });
    }
};
