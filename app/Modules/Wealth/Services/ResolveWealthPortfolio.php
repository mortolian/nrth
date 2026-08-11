<?php

namespace App\Modules\Wealth\Services;

use App\Models\Team;
use App\Modules\Wealth\Models\WealthPortfolio;
use App\Support\Iso4217Currencies;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Session;

final class ResolveWealthPortfolio
{
    public function __construct(
        private readonly EnsureDefaultWealthPortfolio $ensureDefault,
    ) {}

    /**
     * @return Collection<int, WealthPortfolio>
     */
    public function listForTeam(Team $team): Collection
    {
        $this->ensureDefault->forTeam($team);

        return WealthPortfolio::query()
            ->where('team_id', $team->id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    public function resolve(Team $team, ?int $requestedId = null): WealthPortfolio
    {
        $this->ensureDefault->forTeam($team);

        $sessionKey = $this->sessionKey($team);

        $candidateId = $requestedId
            ?? (Session::has($sessionKey) ? (int) Session::get($sessionKey) : null);

        if ($candidateId !== null && $candidateId > 0) {
            $portfolio = WealthPortfolio::query()
                ->where('team_id', $team->id)
                ->whereKey($candidateId)
                ->first();

            if ($portfolio !== null) {
                Session::put($sessionKey, $portfolio->id);

                return $portfolio;
            }
        }

        $default = WealthPortfolio::query()
            ->where('team_id', $team->id)
            ->where('is_default', true)
            ->first()
            ?? WealthPortfolio::query()->where('team_id', $team->id)->first();

        if ($default === null) {
            $default = $this->ensureDefault->forTeam($team);
        }

        Session::put($sessionKey, $default->id);

        return $default;
    }

    public function remember(Team $team, WealthPortfolio $portfolio): void
    {
        Session::put($this->sessionKey($team), $portfolio->id);
    }

    /**
     * @return array{id: int, name: string, base_currency: string, financial_year_start_month: int, is_default: bool, notes: string|null}
     */
    public function present(WealthPortfolio $portfolio): array
    {
        return [
            'id' => $portfolio->id,
            'name' => $portfolio->name,
            'base_currency' => $portfolio->base_currency,
            'financial_year_start_month' => (int) $portfolio->financial_year_start_month,
            'is_default' => (bool) $portfolio->is_default,
            'notes' => $portfolio->notes,
        ];
    }

    /**
     * @return list<array{id: int, name: string, base_currency: string, financial_year_start_month: int, is_default: bool, notes: string|null}>
     */
    public function presentList(Team $team): array
    {
        return $this->listForTeam($team)
            ->map(fn (WealthPortfolio $p) => $this->present($p))
            ->values()
            ->all();
    }

    public function createForTeam(Team $team, string $name, ?int $fyStartMonth = null, ?string $notes = null): WealthPortfolio
    {
        $this->ensureDefault->forTeam($team);

        $settings = $team->mergedBusinessSettings();
        $currency = Iso4217Currencies::normalize((string) ($settings['invoice_default_currency'] ?? 'ZAR'));
        $endMonth = (int) ($settings['financial_year_end_month'] ?? 2);
        $startMonth = $fyStartMonth ?? WealthFinancialYear::startMonthFromEndMonth($endMonth);

        $portfolio = WealthPortfolio::query()->create([
            'team_id' => $team->id,
            'name' => $name,
            'base_currency' => $currency,
            'financial_year_start_month' => max(1, min(12, $startMonth)),
            'notes' => $notes,
            'is_default' => false,
        ]);

        $this->remember($team, $portfolio);

        return $portfolio;
    }

    private function sessionKey(Team $team): string
    {
        return 'wealth.current_portfolio_id.'.$team->id;
    }
}
