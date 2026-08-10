<?php

namespace App\Domain\Vehicles\Services;

use App\Domain\Ai\AiProvider;
use App\Domain\Ai\AiProviderRegistry;
use App\Domain\Vehicles\Models\Trip;
use App\Domain\Vehicles\Models\Vehicle;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ParseTripLogImport
{
    public function __construct(
        private readonly AiProviderRegistry $providers,
        private readonly TripLogFileTextExtractor $extractor,
        private readonly TripLogTelematicsParser $telematicsParser,
        private readonly TripLogConsolidator $consolidator,
        private readonly TripLogDeduper $deduper,
    ) {}

    public function enabledFor(?Team $team): bool
    {
        return $team !== null && $team->aiEnabled();
    }

    /**
     * @return array{
     *     vehicle_id: int,
     *     suggested_vehicle_id: int|null,
     *     filename: string,
     *     truncated: bool,
     *     parser: string,
     *     source_segments_count: int,
     *     trips: list<array<string, mixed>>,
     *     summary: array{total: int, new: int, duplicates: int, segments_merged_away: int}
     * }
     */
    public function parse(UploadedFile $file, Team $team, int $vehicleId): array
    {
        if (! $this->enabledFor($team)) {
            throw ValidationException::withMessages([
                'file' => __('AI is not configured. Add an API key in Business settings → AI.'),
            ]);
        }

        $vehicle = Vehicle::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->whereKey($vehicleId)
            ->first();

        if ($vehicle === null) {
            throw ValidationException::withMessages([
                'vehicle_id' => __('Select a vehicle for this import.'),
            ]);
        }

        $isTabular = $this->extractor->isTabular($file);
        $isVision = $this->extractor->isVisionDocument($file);

        if (! $isTabular && ! $isVision) {
            throw ValidationException::withMessages([
                'file' => __('Upload a CSV, TXT, Excel, PDF, or image trip export.'),
            ]);
        }

        $truncated = false;
        $parser = 'ai';
        $metaRegistration = null;
        $metaVin = null;
        $sourceSegmentsCount = 0;
        $segments = [];

        if ($isTabular) {
            $extracted = $this->extractor->extract($file);
            $truncated = $extracted['truncated'];
            $telematics = $this->telematicsParser->tryParse($extracted['rows']);

            if ($telematics['matched']) {
                $parser = 'telematics';
                $segments = $telematics['segments'];
                $sourceSegmentsCount = $telematics['source_segments_count'];
                $metaRegistration = $telematics['vehicle_registration'];
                $metaVin = $telematics['vehicle_vin'];
            } else {
                $decoded = $this->askAiForTabular($team, $extracted['text'], $truncated);
                [$segments, $sourceSegmentsCount, $metaRegistration, $metaVin] = $this->segmentsFromAi($decoded);
            }
        } else {
            $decoded = $this->askAiForVision($team, $file);
            [$segments, $sourceSegmentsCount, $metaRegistration, $metaVin] = $this->segmentsFromAi($decoded);
        }

        if ($segments === []) {
            throw ValidationException::withMessages([
                'file' => __('No trips could be read from this file.'),
            ]);
        }

        $consolidated = $this->consolidator->consolidate($segments);
        $existing = Trip::queryWithoutTeamScope()
            ->where('team_id', $team->id)
            ->where('vehicle_id', $vehicle->id)
            ->get(['id', 'trip_date', 'started_at', 'distance_km', 'from_location', 'to_location']);

        $trips = $this->deduper->mark($consolidated, $existing);
        $trips = array_map(function (array $trip): array {
            $trip['key'] = (string) Str::uuid();

            return $trip;
        }, $trips);

        $duplicates = count(array_filter($trips, fn (array $t): bool => (bool) ($t['is_duplicate'] ?? false)));
        $segmentsMergedAway = max(0, $sourceSegmentsCount - count($trips));

        $suggestedVehicleId = $this->suggestVehicleId(
            (int) $team->id,
            $metaRegistration,
            $metaVin,
            (int) $vehicle->id,
        );

        return [
            'vehicle_id' => (int) $vehicle->id,
            'suggested_vehicle_id' => $suggestedVehicleId,
            'filename' => $file->getClientOriginalName() ?: 'trip-log',
            'truncated' => $truncated,
            'parser' => $parser,
            'source_segments_count' => $sourceSegmentsCount,
            'trips' => $trips,
            'summary' => [
                'total' => count($trips),
                'new' => count($trips) - $duplicates,
                'duplicates' => $duplicates,
                'segments_merged_away' => $segmentsMergedAway,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function askAiForTabular(Team $team, string $tsv, bool $truncated): array
    {
        $provider = $this->providerFor($team);
        $prompt = $this->prompt()
            ."\n\nSpreadsheet / CSV export (tab-separated"
            .($truncated ? '; truncated to first '.TripLogFileTextExtractor::MAX_DATA_ROWS.' data rows' : '')
            ."):\n".$tsv;

        try {
            return $provider->completeStructuredJson(
                $prompt,
                $team->aiApiKey(),
                $team->aiModel(),
                $team->aiBaseUrl() !== '' ? $team->aiBaseUrl() : null,
            );
        } catch (ValidationException $e) {
            throw $this->remapAiErrors($e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function askAiForVision(Team $team, UploadedFile $file): array
    {
        $provider = $this->providerFor($team);

        try {
            return $provider->extractStructuredJson(
                $file,
                $team->aiApiKey(),
                $team->aiModel(),
                $this->prompt(),
                $team->aiBaseUrl() !== '' ? $team->aiBaseUrl() : null,
            );
        } catch (ValidationException $e) {
            throw $this->remapAiErrors($e);
        }
    }

    private function providerFor(Team $team): AiProvider
    {
        try {
            return $this->providers->get($team->aiProvider());
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'file' => __('AI provider is not supported. Choose a provider in Business settings → AI.'),
            ]);
        }
    }

    private function prompt(): string
    {
        return 'Extract vehicle trip log entries from this Toyota Fleet Management / GPS tracker / onboard log-book export. '
            .'Return JSON only with keys: vehicle_registration (string|null), vehicle_vin (string|null), '
            .'source_segments_count (integer — how many raw stop/segment rows were in the file), '
            .'trips (array). Each trip object keys: trip_date (YYYY-MM-DD), started_at (YYYY-MM-DD HH:MM:SS or null), '
            .'ended_at (YYYY-MM-DD HH:MM:SS or null), duration_seconds (integer|null), distance_km (number), '
            .'purpose (business or private — map Personal/Private to private), from_location, to_location, '
            .'start_latitude, start_longitude, end_latitude, end_longitude (numbers or null), notes (string|null), '
            .'segments_merged (integer — how many raw stop rows were combined into this trip). '
            .'IMPORTANT: Do not create a new trip for every brief stop. Consolidate consecutive segments into one '
            .'logical journey when the vehicle only stopped briefly (under ~45 minutes) and purpose is unchanged. '
            .'A trip should usually go from the real origin to the real destination with summed distance. '
            .'Skip blank/total rows. Prefer fewer consolidated trips over raw stop-level rows.';
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{0: list<array<string, mixed>>, 1: int, 2: string|null, 3: string|null}
     */
    private function segmentsFromAi(array $decoded): array
    {
        $tripsRaw = $decoded['trips'] ?? null;
        if (! is_array($tripsRaw)) {
            throw ValidationException::withMessages([
                'file' => __('AI could not extract trips from this file.'),
            ]);
        }

        $segments = [];
        foreach ($tripsRaw as $row) {
            if (! is_array($row)) {
                continue;
            }
            $mapped = $this->mapAiTrip($row);
            if ($mapped !== null) {
                $segments[] = $mapped;
            }
        }

        $sourceCount = isset($decoded['source_segments_count']) && is_numeric($decoded['source_segments_count'])
            ? max(count($segments), (int) $decoded['source_segments_count'])
            : count($segments);

        return [
            $segments,
            $sourceCount,
            $this->nullableString($decoded['vehicle_registration'] ?? null),
            $this->nullableString($decoded['vehicle_vin'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function mapAiTrip(array $row): ?array
    {
        $distance = $this->nullableFloat($row['distance_km'] ?? null);
        if ($distance === null || $distance < 0) {
            return null;
        }

        $startedAt = $this->parseDateTime($row['started_at'] ?? null);
        $endedAt = $this->parseDateTime($row['ended_at'] ?? null);
        $tripDate = $this->nullableString($row['trip_date'] ?? null)
            ?? $startedAt?->toDateString();

        if ($tripDate === null) {
            return null;
        }

        $purposeRaw = strtolower((string) ($row['purpose'] ?? 'business'));
        $purpose = in_array($purposeRaw, ['private', 'personal'], true) ? 'private' : 'business';

        $duration = isset($row['duration_seconds']) && is_numeric($row['duration_seconds'])
            ? max(0, (int) $row['duration_seconds'])
            : null;
        if ($duration === null && $startedAt !== null && $endedAt !== null) {
            $duration = max(0, (int) $startedAt->diffInSeconds($endedAt));
        }

        $segmentsMerged = isset($row['segments_merged']) && is_numeric($row['segments_merged'])
            ? max(1, (int) $row['segments_merged'])
            : 1;

        return [
            'trip_date' => $tripDate,
            'started_at' => $startedAt?->format('Y-m-d H:i:s'),
            'ended_at' => $endedAt?->format('Y-m-d H:i:s'),
            'duration_seconds' => $duration,
            'distance_km' => round($distance, 1),
            'purpose' => $purpose,
            'from_location' => $this->nullableString($row['from_location'] ?? null),
            'to_location' => $this->nullableString($row['to_location'] ?? null),
            'start_latitude' => $this->nullableFloat($row['start_latitude'] ?? null),
            'start_longitude' => $this->nullableFloat($row['start_longitude'] ?? null),
            'end_latitude' => $this->nullableFloat($row['end_latitude'] ?? null),
            'end_longitude' => $this->nullableFloat($row['end_longitude'] ?? null),
            'notes' => $this->nullableString($row['notes'] ?? null),
            'segments_merged' => $segmentsMerged,
        ];
    }

    private function suggestVehicleId(int $teamId, ?string $registration, ?string $vin, int $fallbackId): ?int
    {
        $vehicles = Vehicle::queryWithoutTeamScope()
            ->where('team_id', $teamId)
            ->get(['id', 'registration_number', 'vin']);

        if ($vin !== null) {
            $needle = preg_replace('/\s+/', '', mb_strtoupper($vin)) ?? '';
            $match = $vehicles->first(function (Vehicle $vehicle) use ($needle): bool {
                $candidate = preg_replace('/\s+/', '', mb_strtoupper((string) $vehicle->vin)) ?? '';

                return $candidate !== '' && $candidate === $needle;
            });
            if ($match !== null) {
                return (int) $match->id;
            }
        }

        if ($registration !== null) {
            $needle = preg_replace('/\s+/', '', mb_strtoupper($registration)) ?? '';
            $match = $vehicles->first(function (Vehicle $vehicle) use ($needle): bool {
                $candidate = preg_replace('/\s+/', '', mb_strtoupper((string) $vehicle->registration_number)) ?? '';

                return $candidate !== '' && $candidate === $needle;
            });
            if ($match !== null) {
                return (int) $match->id;
            }
        }

        return $fallbackId;
    }

    private function remapAiErrors(ValidationException $e): ValidationException
    {
        $flat = collect($e->errors())->flatten()->filter()->values();
        $message = $flat->first();

        return ValidationException::withMessages([
            'file' => is_string($message) ? $message : __('Could not parse this trip log.'),
        ]);
    }

    private function parseDateTime(mixed $value): ?Carbon
    {
        $raw = $this->nullableString($value);
        if ($raw === null) {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }
}
