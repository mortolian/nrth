<?php

namespace App\Domain\Invoicing\Services;

use App\Domain\Invoicing\Models\InvoiceNumberSequence;
use App\Domain\Invoicing\Models\Invoice;
use App\Models\Team;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class InvoiceNumberService
{
    public function generate(int $teamId, ?CarbonInterface $forDate = null): string
    {
        $date = $forDate ?? now();
        $year = (int) $date->format('Y');

        $sequence = DB::transaction(function () use ($teamId, $year): int {
            /** @var InvoiceNumberSequence $row */
            $row = InvoiceNumberSequence::query()
                ->where('team_id', $teamId)
                ->where('year', $year)
                ->lockForUpdate()
                ->first() ?? InvoiceNumberSequence::query()->create([
                    'team_id' => $teamId,
                    'year' => $year,
                    'next_number' => 1,
                ]);

            $current = $row->next_number;
            $row->update(['next_number' => $current + 1]);

            return $current;
        });

        $date = $forDate ?? now();
        $settings = $this->teamSettings($teamId);
        if (! ($settings['invoice_number_use_random_suffix'] ?? false)) {
            return $this->formatNumber($teamId, $year, $sequence, $date);
        }

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $candidate = $this->formatNumber($teamId, $year, $sequence, $date);
            $exists = Invoice::queryWithoutTeamScope()
                ->where('team_id', $teamId)
                ->where('number', $candidate)
                ->exists();
            if (! $exists) {
                return $candidate;
            }
        }

        return $this->formatNumber($teamId, $year, $sequence, $date);
    }

    public function formatNumber(int $teamId, int $year, int $sequence, ?CarbonInterface $forDate = null): string
    {
        $date = $forDate ?? now();
        $prefix = $this->normalizedPrefix($teamId);
        $settings = $this->teamSettings($teamId);
        $includeMonth = (bool) ($settings['invoice_number_include_month'] ?? false);
        $useRandomSuffix = (bool) ($settings['invoice_number_use_random_suffix'] ?? false);

        $parts = [$prefix, (string) $year];
        if ($includeMonth) {
            $parts[] = $date->format('m');
        }

        if ($useRandomSuffix) {
            $parts[] = $this->randomIdentifier();
        } else {
            $parts[] = sprintf('%04d', $sequence);
        }

        return implode('-', $parts);
    }

    public function normalizedPrefix(int $teamId): string
    {
        $raw = $this->teamSettings($teamId)['invoice_prefix'] ?? 'INV';
        $base = trim((string) $raw, " \t\n\r\0\x0B-");
        if ($base === '') {
            return 'INV';
        }

        return $base;
    }

    /**
     * @return array<string, mixed>
     */
    private function teamSettings(int $teamId): array
    {
        $team = Team::query()->find($teamId);

        return $team?->mergedCompanySettings() ?? [];
    }

    private function randomIdentifier(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $suffix = '';
        for ($i = 0; $i < 4; $i++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $suffix;
    }
}
