<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('completed_onboarding_at')->nullable()->after('email_verified_at');
        });

        DB::table('users')->whereNull('completed_onboarding_at')->update([
            'completed_onboarding_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('completed_onboarding_at');
        });
    }
};
