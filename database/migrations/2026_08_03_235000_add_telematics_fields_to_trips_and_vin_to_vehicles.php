<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vehicles') || ! Schema::hasTable('trips')) {
            return;
        }

        Schema::table('vehicles', function (Blueprint $table) {
            if (! Schema::hasColumn('vehicles', 'vin')) {
                $table->string('vin', 32)->nullable()->after('registration_number');
            }
        });

        Schema::table('trips', function (Blueprint $table) {
            if (! Schema::hasColumn('trips', 'started_at')) {
                $table->timestamp('started_at')->nullable()->after('trip_date');
            }
            if (! Schema::hasColumn('trips', 'ended_at')) {
                $table->timestamp('ended_at')->nullable()->after('started_at');
            }
            if (! Schema::hasColumn('trips', 'duration_seconds')) {
                $table->unsignedInteger('duration_seconds')->nullable()->after('ended_at');
            }
            if (! Schema::hasColumn('trips', 'start_latitude')) {
                $table->decimal('start_latitude', 10, 7)->nullable()->after('to_location');
            }
            if (! Schema::hasColumn('trips', 'start_longitude')) {
                $table->decimal('start_longitude', 10, 7)->nullable()->after('start_latitude');
            }
            if (! Schema::hasColumn('trips', 'end_latitude')) {
                $table->decimal('end_latitude', 10, 7)->nullable()->after('start_longitude');
            }
            if (! Schema::hasColumn('trips', 'end_longitude')) {
                $table->decimal('end_longitude', 10, 7)->nullable()->after('end_latitude');
            }
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE trips ALTER COLUMN start_odometer_km DROP NOT NULL');
            DB::statement('ALTER TABLE trips ALTER COLUMN end_odometer_km DROP NOT NULL');
            DB::statement('CREATE INDEX IF NOT EXISTS trips_team_id_started_at_index ON trips (team_id, started_at)');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('trips')) {
            return;
        }

        Schema::table('trips', function (Blueprint $table) {
            foreach (['started_at', 'ended_at', 'duration_seconds', 'start_latitude', 'start_longitude', 'end_latitude', 'end_longitude'] as $column) {
                if (Schema::hasColumn('trips', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasColumn('vehicles', 'vin')) {
            Schema::table('vehicles', function (Blueprint $table) {
                $table->dropColumn('vin');
            });
        }
    }
};
