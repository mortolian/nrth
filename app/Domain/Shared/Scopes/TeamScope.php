<?php

namespace App\Domain\Shared\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class TeamScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! auth()->hasUser()) {
            if (! app()->runningInConsole()) {
                $builder->whereRaw('1 = 0');
            }

            return;
        }

        $teamId = auth()->user()->current_team_id;

        if ($teamId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $column = $model->getTable().'.team_id';

        $builder->where($column, $teamId);
    }
}
