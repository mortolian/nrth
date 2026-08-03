<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_backup_runs', function (Blueprint $table) {
            $table->text('mirror_warning')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('instance_backup_runs', function (Blueprint $table) {
            $table->dropColumn('mirror_warning');
        });
    }
};
