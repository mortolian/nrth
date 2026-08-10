<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('parser', 32);
            $table->string('status', 32);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'created_at']);
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->foreignId('trip_import_id')
                ->nullable()
                ->after('vehicle_id')
                ->constrained('trip_imports')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trip_import_id');
        });

        Schema::dropIfExists('trip_imports');
    }
};
