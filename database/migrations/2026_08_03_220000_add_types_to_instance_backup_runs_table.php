<?php

use App\Domain\Backup\Models\InstanceBackupRun;
use App\Domain\Backup\Services\InstanceBackupTypeResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instance_backup_runs', function (Blueprint $table) {
            $table->json('types')->nullable()->after('status');
        });

        $resolver = app(InstanceBackupTypeResolver::class);

        InstanceBackupRun::query()
            ->orderBy('id')
            ->each(function (InstanceBackupRun $run) use ($resolver): void {
                $at = $run->completed_at ?? $run->created_at;
                $types = $resolver->typesFor($at ?? now());

                $run->forceFill(['types' => $types])->saveQuietly();
            });
    }

    public function down(): void
    {
        Schema::table('instance_backup_runs', function (Blueprint $table) {
            $table->dropColumn('types');
        });
    }
};
