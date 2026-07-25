<?php

use App\Support\TeamAccess\EnsureTeamSystemRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('team_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->string('description')->nullable();
            $table->json('permissions');
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(['team_id', 'key']);
        });

        EnsureTeamSystemRoles::ensureForAllTeams();
    }

    public function down(): void
    {
        Schema::dropIfExists('team_roles');
    }
};
