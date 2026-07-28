<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit', 32)->nullable();
            $table->unsignedBigInteger('unit_price_cents')->default(0);
            $table->decimal('default_vat_rate', 5, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['team_id', 'name']);
        });

        Schema::table('invoice_line_items', function (Blueprint $table) {
            $table->foreignId('item_id')
                ->nullable()
                ->after('invoice_id')
                ->constrained('items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_line_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_id');
        });

        Schema::dropIfExists('items');
    }
};
