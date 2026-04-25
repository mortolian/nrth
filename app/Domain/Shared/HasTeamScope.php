<?php

namespace App\Domain\Shared;

use App\Domain\Shared\Scopes\TeamScope;
use Illuminate\Database\Eloquent\Builder;

trait HasTeamScope
{
    protected static function bootHasTeamScope(): void
    {
        static::addGlobalScope(new TeamScope);
    }

    /**
     * @return Builder<static>
     */
    public static function queryWithoutTeamScope(): Builder
    {
        return static::withoutGlobalScope(TeamScope::class);
    }
}
