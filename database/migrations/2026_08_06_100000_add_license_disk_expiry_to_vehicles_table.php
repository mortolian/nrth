<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->date('license_disk_expires_on')->nullable()->after('vin');
            $table->date('license_disk_reminder_sent_for')->nullable()->after('license_disk_expires_on');

            $table->index(['license_disk_expires_on', 'is_active'], 'vehicles_license_disk_reminder_index');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex('vehicles_license_disk_reminder_index');
            $table->dropColumn(['license_disk_expires_on', 'license_disk_reminder_sent_for']);
        });
    }
};
