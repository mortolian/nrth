<?php

namespace App\Domain\Shared;

use App\Domain\Invoicing\Models\Client;

trait LoadsClientWithoutTeamScope
{
    public function loadClientWithoutTeamScope(): static
    {
        $clientId = $this->client_id ?? null;
        if ($clientId === null) {
            $this->setRelation('client', null);

            return $this;
        }

        $this->setRelation(
            'client',
            Client::queryWithoutTeamScope()
                ->where('team_id', $this->team_id)
                ->whereKey($clientId)
                ->first()
        );

        return $this;
    }
}
