<?php

use App\Domain\Invoicing\Models\Client;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->text('default_invoice_notes')->nullable()->after('notes');
        });

        $clients = Client::queryWithoutTeamScope()
            ->with(['noteTemplates' => fn ($q) => $q
                ->where('note_templates.target', 'notes')
                ->orderByPivot('sort_order')])
            ->get();

        foreach ($clients as $client) {
            $composed = $client->noteTemplates
                ->pluck('body')
                ->filter()
                ->implode("\n\n");

            if ($composed === '') {
                continue;
            }

            DB::table('clients')
                ->where('id', $client->id)
                ->update(['default_invoice_notes' => $composed]);
        }
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('default_invoice_notes');
        });
    }
};
