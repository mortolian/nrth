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
            $table->json('preferences')->nullable()->after('remember_token');
        });

        if (Schema::hasTable('team_user')) {
            DB::table('team_user')->where('role', 'admin')->update(['role' => 'accountant']);
            DB::table('team_user')->where('role', 'editor')->update(['role' => 'viewer']);
        }

        if (Schema::hasTable('team_invitations')) {
            DB::table('team_invitations')->where('role', 'admin')->update(['role' => 'accountant']);
            DB::table('team_invitations')->where('role', 'editor')->update(['role' => 'viewer']);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferences');
        });
    }
};
