<?php

namespace App\Http\Controllers\Web\Vehicles;

use App\Domain\Vehicles\Enums\TripImportStatus;
use App\Domain\Vehicles\Enums\TripPurpose;
use App\Domain\Vehicles\Models\Trip;
use App\Domain\Vehicles\Models\TripImport;
use App\Domain\Vehicles\Models\Vehicle;
use App\Domain\Vehicles\Services\ParseTripLogImport;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TripImportController extends Controller
{
    private const SESSION_KEY = 'trip_log_import';

    public function create(Request $request, ParseTripLogImport $parser): Response
    {
        $this->authorizeTeam('vehicles.manage', $request);
        $this->ensureAiEnabled($request, $parser);

        $teamId = (int) $request->user()->current_team_id;

        return Inertia::render('Vehicles/Trips/Import/Upload', [
            'vehicles' => $this->vehicleOptions($teamId, activeOnly: true),
            'prefill_vehicle_id' => $request->integer('vehicle_id') ?: null,
        ]);
    }

    public function store(Request $request, ParseTripLogImport $parser): RedirectResponse
    {
        $this->authorizeTeam('vehicles.manage', $request);
        $this->ensureAiEnabled($request, $parser);

        $team = $request->user()->currentTeam;
        abort_if($team === null, 403);

        $validated = $request->validate([
            'vehicle_id' => [
                'required',
                'integer',
                Rule::exists('vehicles', 'id')->where('team_id', $team->id),
            ],
            'file' => [
                'required',
                'file',
                'max:10240',
                'mimes:csv,txt,xlsx,xls,pdf,jpg,jpeg,png,webp,gif',
            ],
        ]);

        $result = $parser->parse(
            $request->file('file'),
            $team,
            (int) $validated['vehicle_id'],
        );

        if ($result['suggested_vehicle_id'] !== null) {
            $result['vehicle_id'] = (int) $result['suggested_vehicle_id'];
        }

        $request->session()->put(self::SESSION_KEY, $result);

        return to_route('vehicles.trips.import.preview')
            ->with('success', __('Trip log scanned. Review before importing.'));
    }

    public function preview(Request $request, ParseTripLogImport $parser): Response|RedirectResponse
    {
        $this->authorizeTeam('vehicles.manage', $request);
        $this->ensureAiEnabled($request, $parser);

        $draft = $request->session()->get(self::SESSION_KEY);
        if (! is_array($draft) || empty($draft['trips'])) {
            return to_route('vehicles.trips.import.create')
                ->with('error', __('Upload a trip log to preview.'));
        }

        $teamId = (int) $request->user()->current_team_id;

        return Inertia::render('Vehicles/Trips/Import/Preview', [
            'draft' => [
                'vehicle_id' => (int) $draft['vehicle_id'],
                'filename' => (string) ($draft['filename'] ?? 'trip-log'),
                'truncated' => (bool) ($draft['truncated'] ?? false),
                'parser' => (string) ($draft['parser'] ?? 'ai'),
                'source_segments_count' => (int) ($draft['source_segments_count'] ?? 0),
                'summary' => $draft['summary'] ?? [
                    'total' => 0,
                    'new' => 0,
                    'duplicates' => 0,
                    'segments_merged_away' => 0,
                ],
                'trips' => array_values($draft['trips']),
            ],
            'vehicles' => $this->vehicleOptions($teamId),
        ]);
    }

    public function confirm(Request $request, ParseTripLogImport $parser): RedirectResponse
    {
        $this->authorizeTeam('vehicles.manage', $request);
        $this->ensureAiEnabled($request, $parser);

        $draft = $request->session()->get(self::SESSION_KEY);
        if (! is_array($draft) || empty($draft['trips'])) {
            return to_route('vehicles.trips.import.create')
                ->with('error', __('Upload a trip log to import.'));
        }

        $teamId = (int) $request->user()->current_team_id;
        $validated = $request->validate([
            'vehicle_id' => [
                'required',
                'integer',
                Rule::exists('vehicles', 'id')->where('team_id', $teamId),
            ],
            'keys' => ['required', 'array', 'min:1'],
            'keys.*' => ['required', 'string'],
        ]);

        $selectedKeys = array_flip($validated['keys']);
        $selected = [];
        foreach ($draft['trips'] as $trip) {
            if (! is_array($trip)) {
                continue;
            }
            $key = (string) ($trip['key'] ?? '');
            if ($key !== '' && isset($selectedKeys[$key])) {
                $selected[] = $trip;
            }
        }

        if ($selected === []) {
            throw ValidationException::withMessages([
                'keys' => __('Select at least one trip to import.'),
            ]);
        }

        $vehicle = Vehicle::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->whereKey((int) $validated['vehicle_id'])
            ->firstOrFail();

        $created = 0;

        DB::transaction(function () use ($selected, $teamId, $vehicle, $draft, &$created): void {
            $import = TripImport::queryWithoutTeamScope()->create([
                'team_id' => $teamId,
                'vehicle_id' => $vehicle->id,
                'original_filename' => (string) ($draft['filename'] ?? 'trip-log'),
                'parser' => (string) ($draft['parser'] ?? 'ai'),
                'status' => TripImportStatus::Imported,
                'imported_rows' => 0,
                'metadata' => null,
            ]);

            foreach ($selected as $row) {
                $payload = $this->normalizeTripPayload($row);
                Trip::queryWithoutTeamScope()->create([
                    'team_id' => $teamId,
                    'vehicle_id' => $vehicle->id,
                    'trip_import_id' => $import->id,
                    ...$payload,
                ]);
                $created++;
            }

            $import->update(['imported_rows' => $created]);
        });

        $request->session()->forget(self::SESSION_KEY);

        return to_route('vehicles.trips.index')
            ->with('success', trans_choice(
                '{1} Imported :count trip.|[2,*] Imported :count trips.',
                $created,
                ['count' => $created]
            ));
    }

    public function index(Request $request): Response
    {
        $this->authorizeTeam('vehicles.view', $request);

        $teamId = (int) $request->user()->current_team_id;
        $vehicleId = (int) $request->integer('vehicle_id');
        $canDelete = $request->user()->canOnTeam('vehicles.delete');

        $query = TripImport::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->with(['vehicle:id,name,registration_number']);

        if ($vehicleId > 0) {
            $query->where('vehicle_id', $vehicleId);
        }

        $imports = $query
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (TripImport $import): array => [
                'id' => $import->id,
                'original_filename' => $import->original_filename,
                'parser' => $import->parser,
                'status' => $import->status->value,
                'imported_rows' => $import->imported_rows,
                'can_undo' => $canDelete && $import->status === TripImportStatus::Imported,
                'created_at' => $import->created_at?->toIso8601String(),
                'vehicle' => $import->vehicle
                    ? [
                        'id' => $import->vehicle->id,
                        'name' => $import->vehicle->name,
                        'registration_number' => $import->vehicle->registration_number,
                    ]
                    : null,
            ]);

        return Inertia::render('Vehicles/Trips/Import/History', [
            'imports' => $imports,
            'vehicles' => $this->vehicleOptions($teamId, activeOnly: false),
            'filters' => [
                'vehicle_id' => $vehicleId > 0 ? $vehicleId : null,
            ],
        ]);
    }

    public function undo(Request $request, TripImport $import): RedirectResponse
    {
        $this->authorizeTeam('vehicles.delete', $request);
        $this->authorizeImport($request, $import);

        if ($import->status !== TripImportStatus::Imported) {
            throw ValidationException::withMessages([
                'import' => __('Only a completed import can be undone.'),
            ]);
        }

        DB::transaction(function () use ($import): void {
            Trip::queryWithoutTeamScope()
                ->where('trip_import_id', $import->id)
                ->delete();

            $import->update([
                'status' => TripImportStatus::Undone,
                'imported_rows' => 0,
                'metadata' => array_merge($import->metadata ?? [], [
                    'undone_at' => now()->toIso8601String(),
                ]),
            ]);
        });

        return back()->with('success', __('Import undone. Trips from this import were removed.'));
    }

    private function authorizeImport(Request $request, TripImport $import): void
    {
        abort_unless(
            $import->team_id === (int) $request->user()->current_team_id,
            403
        );
    }

    private function ensureAiEnabled(Request $request, ParseTripLogImport $parser): void
    {
        abort_unless($parser->enabledFor($request->user()?->currentTeam), 404);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeTripPayload(array $row): array
    {
        $startedAt = ! empty($row['started_at']) ? Carbon::parse((string) $row['started_at']) : null;
        $endedAt = ! empty($row['ended_at']) ? Carbon::parse((string) $row['ended_at']) : null;
        $tripDate = ! empty($row['trip_date'])
            ? Carbon::parse((string) $row['trip_date'])->toDateString()
            : $startedAt?->toDateString();

        if ($tripDate === null) {
            throw ValidationException::withMessages([
                'keys' => __('One of the selected trips is missing a date.'),
            ]);
        }

        $purpose = (string) ($row['purpose'] ?? TripPurpose::Business->value);
        if (! in_array($purpose, [TripPurpose::Business->value, TripPurpose::Private->value], true)) {
            $purpose = TripPurpose::Business->value;
        }

        $durationSeconds = isset($row['duration_seconds']) && $row['duration_seconds'] !== null
            ? max(0, (int) $row['duration_seconds'])
            : null;
        if ($durationSeconds === null && $startedAt !== null && $endedAt !== null) {
            $durationSeconds = max(0, (int) $startedAt->diffInSeconds($endedAt));
        }

        $notes = isset($row['notes']) ? trim((string) $row['notes']) : '';
        $segmentsMerged = isset($row['segments_merged']) ? (int) $row['segments_merged'] : 1;
        if ($segmentsMerged > 1) {
            $mergeNote = __('Consolidated from :count GPS segments.', ['count' => $segmentsMerged]);
            $notes = $notes === '' ? $mergeNote : $notes.' · '.$mergeNote;
        }

        return [
            'trip_date' => $tripDate,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_seconds' => $durationSeconds,
            'distance_km' => round((float) ($row['distance_km'] ?? 0), 1),
            'purpose' => $purpose,
            'from_location' => $this->nullableString($row['from_location'] ?? null),
            'to_location' => $this->nullableString($row['to_location'] ?? null),
            'start_latitude' => $this->nullableCoord($row['start_latitude'] ?? null),
            'start_longitude' => $this->nullableCoord($row['start_longitude'] ?? null),
            'end_latitude' => $this->nullableCoord($row['end_latitude'] ?? null),
            'end_longitude' => $this->nullableCoord($row['end_longitude'] ?? null),
            'notes' => $notes === '' ? null : $notes,
            'start_odometer_km' => null,
            'end_odometer_km' => null,
        ];
    }

    /**
     * @return list<array{id: int, name: string, registration_number: string|null, is_active: bool}>
     */
    private function vehicleOptions(int $teamId, bool $activeOnly = false): array
    {
        $query = Vehicle::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->orderBy('name');

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query
            ->get(['id', 'name', 'registration_number', 'is_active'])
            ->map(fn (Vehicle $vehicle): array => [
                'id' => $vehicle->id,
                'name' => $vehicle->name,
                'registration_number' => $vehicle->registration_number,
                'is_active' => (bool) $vehicle->is_active,
            ])
            ->all();
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : mb_substr($string, 0, 255);
    }

    private function nullableCoord(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 7);
    }
}
