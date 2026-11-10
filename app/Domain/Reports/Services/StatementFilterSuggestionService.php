<?php

namespace App\Domain\Reports\Services;

use App\Domain\Banking\Enums\TransactionDirection;
use App\Domain\Banking\Models\BankingTransaction;
use Illuminate\Database\Eloquent\Builder;

final class StatementFilterSuggestionService
{
    public const MIN_PREVIEW_LENGTH = 3;

    public const MAX_PREVIEW_SAMPLES = 5;

    public const MIN_GROUP_COUNT = 2;

    public const MAX_GROUPS = 12;

    public const MAX_EXAMPLES = 3;

    /**
     * Leading bank boilerplate. Removed only from the start of a line so a
     * merchant name later in the description is what gets grouped.
     *
     * @var list<string>
     */
    private const LEADING_NOISE = [
        'pos', 'purchase', 'purch', 'debit', 'order', 'credit', 'card', 'visa',
        'mastercard', 'eft', 'txn', 'trans', 'transaction', 'ref', 'nr', 'no',
        'the', 'and', 'from', 'atm', 'payment', 'pymt', 'thank', 'you',
        'instant', 'transfer', 'trf',
    ];

    /**
     * @return array{count: int, total_cents: int, samples: list<array{description: string, count: int}>}
     */
    public function preview(int $teamId, string $match): array
    {
        $needle = mb_strtolower(trim($match));
        if (mb_strlen($needle) < self::MIN_PREVIEW_LENGTH) {
            return ['count' => 0, 'total_cents' => 0, 'samples' => []];
        }

        $count = 0;
        $total = 0;
        $samples = [];
        foreach ($this->lineQuery($teamId)->cursor() as $transaction) {
            if (! $this->contains($transaction, $needle)) {
                continue;
            }
            $count++;
            $total += $this->signedCents($transaction);
            $label = $this->sampleLabel($transaction, $needle);
            $samples[$label] = ($samples[$label] ?? 0) + 1;
        }
        arsort($samples);

        $listed = [];
        foreach (array_slice($samples, 0, self::MAX_PREVIEW_SAMPLES, true) as $description => $sampleCount) {
            $listed[] = [
                'description' => (string) $description,
                'count' => $sampleCount,
            ];
        }

        return [
            'count' => $count,
            'total_cents' => $total,
            'samples' => $listed,
        ];
    }

    /**
     * @return list<array{alias: string, match: string, count: int, total_cents: int, examples: list<string>}>
     */
    public function suggest(int $teamId): array
    {
        /** @var array<string, array{count: int, total_cents: int, examples: array<string, int>}> $groups */
        $groups = [];

        foreach ($this->lineQuery($teamId)->cursor() as $transaction) {
            $key = $this->merchantKey($transaction->description, $transaction->reference);
            if ($key === null) {
                continue;
            }
            if (! isset($groups[$key])) {
                $groups[$key] = ['count' => 0, 'total_cents' => 0, 'examples' => []];
            }
            $groups[$key]['count']++;
            $groups[$key]['total_cents'] += $this->signedCents($transaction);
            $example = trim($transaction->description);
            if ($example === '') {
                continue;
            }
            $groups[$key]['examples'][$example] = ($groups[$key]['examples'][$example] ?? 0) + 1;
        }

        $suggestions = [];
        foreach ($groups as $match => $group) {
            if ($group['count'] < self::MIN_GROUP_COUNT) {
                continue;
            }
            arsort($group['examples']);
            $suggestions[] = [
                'alias' => mb_convert_case($match, MB_CASE_TITLE, 'UTF-8'),
                'match' => $match,
                'count' => $group['count'],
                'total_cents' => $group['total_cents'],
                'examples' => array_slice(array_keys($group['examples']), 0, self::MAX_EXAMPLES),
            ];
        }

        usort($suggestions, function (array $left, array $right): int {
            return $right['count'] <=> $left['count'] ?: abs($right['total_cents']) <=> abs($left['total_cents']);
        });

        return array_slice($suggestions, 0, self::MAX_GROUPS);
    }

    /**
     * @return Builder<BankingTransaction>
     */
    private function lineQuery(int $teamId): Builder
    {
        return BankingTransaction::query()
            ->select(['id', 'description', 'reference', 'amount', 'direction'])
            ->where('team_id', $teamId)
            ->orderBy('id');
    }

    private function contains(BankingTransaction $transaction, string $needle): bool
    {
        $haystack = mb_strtolower(trim($transaction->description.' '.($transaction->reference ?? '')));

        return str_contains($haystack, $needle);
    }

    private function sampleLabel(BankingTransaction $transaction, string $needle): string
    {
        $description = trim($transaction->description);
        if ($description !== '' && str_contains(mb_strtolower($description), $needle)) {
            return $description;
        }

        $reference = trim((string) $transaction->reference);
        if ($description === '') {
            return $reference;
        }
        if ($reference === '') {
            return $description;
        }

        return $description.' · '.$reference;
    }

    private function signedCents(BankingTransaction $transaction): int
    {
        $cents = (int) round(((float) $transaction->amount) * 100);

        return $transaction->direction === TransactionDirection::Debit ? $cents : -$cents;
    }

    private function merchantKey(string $description, ?string $reference): ?string
    {
        $tokens = $this->withoutLeadingNoise($this->tokens($description));
        if ($tokens === []) {
            $tokens = $this->withoutLeadingNoise($this->tokens((string) $reference));
        }
        if ($tokens === []) {
            return null;
        }

        $first = $tokens[0];
        if (mb_strlen($first) >= 4) {
            return $first;
        }
        if (! isset($tokens[1])) {
            return null;
        }

        return $first.' '.$tokens[1];
    }

    /**
     * @return list<string>
     */
    private function tokens(string $value): array
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/\d{4}-\d{2}-\d{2}/', ' ', $value) ?? $value;
        $value = preg_replace('/\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4}/', ' ', $value) ?? $value;
        $value = preg_replace('/\d{2,}/', ' ', $value) ?? $value;
        $value = preg_replace('/[^a-z\s]/', ' ', $value) ?? $value;
        $parts = preg_split('/\s+/', trim($value)) ?: [];

        return array_values(array_filter(
            $parts,
            fn (string $part): bool => $part !== '' && mb_strlen($part) >= 2,
        ));
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function withoutLeadingNoise(array $tokens): array
    {
        while ($tokens !== [] && in_array($tokens[0], self::LEADING_NOISE, true)) {
            array_shift($tokens);
        }

        return $tokens;
    }
}
