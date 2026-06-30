<?php

namespace App\Http\Controllers\Web\Tax;

use App\Domain\Takeout\Enums\TakeoutRunStatus;
use App\Domain\Takeout\Jobs\GenerateTakeoutJob;
use App\Domain\Takeout\Models\TakeoutRun;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TakeoutController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', TakeoutRun::class);

        $teamId = (int) $request->user()->current_team_id;
        $validated = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
        ]);

        $run = TakeoutRun::queryWithoutTeamScope()->create([
            'team_id' => $teamId,
            'requested_by' => $request->user()->id,
            'from_date' => $validated['from_date'],
            'to_date' => $validated['to_date'],
            'status' => TakeoutRunStatus::Queued,
            'download_token' => TakeoutRun::generateDownloadToken(),
        ]);

        GenerateTakeoutJob::dispatch($run->id);

        return redirect()
            ->route('tax.documents.index', [
                'from' => $validated['from_date'],
                'to' => $validated['to_date'],
            ])
            ->with('success', 'Your data takeout is being prepared. You will be notified when it is ready to download.');
    }

    public function download(Request $request, TakeoutRun $takeoutRun): StreamedResponse
    {
        Gate::authorize('download', $takeoutRun);

        if (! $takeoutRun->isDownloadable()) {
            if ($takeoutRun->status === TakeoutRunStatus::Expired
                || ($takeoutRun->expires_at !== null && $takeoutRun->expires_at->isPast())) {
                abort(410, 'This takeout download has expired.');
            }

            abort(404);
        }

        $disk = Storage::disk('local');
        if (! $disk->exists((string) $takeoutRun->storage_path)) {
            abort(404);
        }

        $filename = sprintf(
            'nrth-takeout_%s_to_%s.zip',
            $takeoutRun->from_date->format('Y-m-d'),
            $takeoutRun->to_date->format('Y-m-d'),
        );

        return $disk->download((string) $takeoutRun->storage_path, $filename, [
            'Content-Type' => 'application/zip',
        ]);
    }
}
