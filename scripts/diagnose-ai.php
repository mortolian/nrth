#!/usr/bin/env php
<?php

/**
 * Production diagnostic: AI / receipt-scan config.
 *
 * Usage on server:
 *   ./scripts/compose.sh exec -T app php scripts/diagnose-ai.php
 */

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

echo "=== nrth AI diagnose ===\n";

$teamCount = Team::query()->count();
echo "teams={$teamCount}\n";

$team = Team::query()->orderBy('id')->first();
if ($team === null) {
    echo "ERROR: no teams in database\n";
    exit(1);
}

echo 'team_id='.$team->id."\n";
echo 'team_name='.$team->name."\n";
echo 'provider='.$team->aiProvider()."\n";
echo 'model='.$team->aiModel()."\n";
echo 'base_url='.$team->aiBaseUrl()."\n";
echo 'enabled='.($team->aiEnabled() ? 'yes' : 'no')."\n";
echo 'key_len='.strlen($team->aiApiKey())."\n";

$envOpenAi = trim((string) config('services.openai.api_key', ''));
echo 'env_OPENAI_API_KEY_len='.strlen($envOpenAi)."\n";

$key = $team->aiApiKey();
if ($key === '') {
    echo "ERROR: no API key resolved (Company settings → AI, or .env)\n";
    exit(1);
}

$base = rtrim($team->aiBaseUrl() !== '' ? $team->aiBaseUrl() : 'https://api.openai.com/v1', '/');
$url = $base.'/models';

echo "probing {$url} ...\n";

try {
    $response = Http::acceptJson()
        ->withToken($key)
        ->timeout(20)
        ->get($url);

    echo 'http_status='.$response->status()."\n";
    if (! $response->successful()) {
        $msg = $response->json('error.message') ?? $response->body();
        echo 'error='.(is_string($msg) ? $msg : json_encode($msg))."\n";
        exit(1);
    }

    echo "outbound_ok=yes\n";
} catch (Throwable $e) {
    echo 'outbound_ok=no'."\n";
    echo 'exception='.$e->getMessage()."\n";
    exit(1);
}

echo "done\n";
