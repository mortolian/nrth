<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicles')) {
            return;
        }

        if (Schema::hasColumn('vehicles', 'current_odometer_km') && ! Schema::hasColumn('vehicles', 'starting_odometer_km')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->renameColumn('current_odometer_km', 'starting_odometer_km');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('vehicles')) {
            return;
        }

        if (Schema::hasColumn('vehicles', 'starting_odometer_km') && ! Schema::hasColumn('vehicles', 'current_odometer_km')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->renameColumn('starting_odometer_km', 'current_odometer_km');
            });
        }
    }
};
