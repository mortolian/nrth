<?php

namespace App\Domain\Reports\Services;

use App\Models\Team;
use Symfony\Component\HttpFoundation\Response;

final class ProfitLossPdfService
{
    /**
     * @param  array{
     *     report: array<string, mixed>,
     *     comparison: array<string, mixed>|null,
     *     period: array{from: string, to: string, preset: string},
     *     compare: string,
     *     comparison_period: array{from: string, to: string}|null
     * }  $bundle
     */
    public function download(Team $team, array $bundle): Response
    {
        return ReportPdf::download(
            'pdf.profit-loss',
            $this->document($team, $bundle),
            'profit-and-loss-'.$bundle['period']['from'].'-'.$bundle['period']['to'].'.pdf',
            'portrait',
        );
    }

    /**
     * @param  array{
     *     report: array<string, mixed>,
     *     comparison: array<string, mixed>|null,
     *     period: array{from: string, to: string, preset: string},
     *     compare: string,
     *     comparison_period: array{from: string, to: string}|null
     * }  $bundle
     * @return array<string, mixed>
     */
    public function document(Team $team, array $bundle): array
    {
        $comparison = $bundle['comparison'];

        return [
            ...ReportPdf::chrome($team),
            'currency' => ReportPeriod::currency($team),
            'period' => $bundle['period'],
            'compare' => $bundle['compare'],
            'comparison_period' => $bundle['comparison_period'],
            'has_comparison' => $comparison !== null,
            'income' => $this->rows($bundle['report']['income'] ?? [], $comparison['income'] ?? null),
            'expenses' => $this->rows($bundle['report']['expenses'] ?? [], $comparison['expenses'] ?? null),
            'totals' => $bundle['report']['totals'] ?? ['income' => 0, 'expenses' => 0, 'net_profit' => 0],
            'comparison_totals' => $comparison['totals'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $current
     * @param  array<int, array<string, mixed>>|null  $comparison
     * @return array<int, array{code: string, name: string, amount: int, comparison: int}>
     */
    private function rows(array $current, ?array $comparison): array
    {
        $byId = [];
        foreach ($current as $line) {
            $byId[(int) $line['account_id']] = [
                'code' => (string) $line['code'],
                'name' => (string) $line['name'],
                'amount' => (int) $line['amount'],
                'comparison' => 0,
            ];
        }

        foreach ($comparison ?? [] as $line) {
            $id = (int) $line['account_id'];
            if (! isset($byId[$id])) {
                $byId[$id] = [
                    'code' => (string) $line['code'],
                    'name' => (string) $line['name'],
                    'amount' => 0,
                    'comparison' => (int) $line['amount'],
                ];

                continue;
            }
            $byId[$id]['comparison'] = (int) $line['amount'];
        }

        $rows = array_values($byId);
        usort($rows, fn (array $a, array $b): int => strcmp($a['code'], $b['code']));

        return $rows;
    }
}
