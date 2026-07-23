<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banking_accounts', function (Blueprint $table) {
            $table->json('csv_mapping_profile')->nullable()->after('gl_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('banking_accounts', function (Blueprint $table) {
            $table->dropColumn('csv_mapping_profile');
        });
    }
};
