<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('team_bank_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('team_bank_accounts', 'description')) {
                $table->dropColumn('description');
            }
        });

        Schema::table('team_bank_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('team_bank_accounts', 'title')) {
                $table->string('title', 128)->nullable()->after('sort_order');
            }
        });
    }

    public function down(): void
    {
        Schema::table('team_bank_accounts', function (Blueprint $table) {
            if (Schema::hasColumn('team_bank_accounts', 'title')) {
                $table->dropColumn('title');
            }
        });

        Schema::table('team_bank_accounts', function (Blueprint $table) {
            $table->text('description')->nullable()->after('bank_account_type');
        });
    }
};
