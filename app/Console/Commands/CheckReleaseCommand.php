<?php

namespace App\Console\Commands;

use App\Support\Upgrade\SchemaUpgradeStatus;
use App\Support\Version\GithubReleaseChecker;
use Illuminate\Console\Command;

class CheckReleaseCommand extends Command
{
    protected $signature = 'nrth:check-release';

    protected $description = 'Refresh the cached GitHub latest-release check used by the app UI.';

    public function handle(SchemaUpgradeStatus $status, GithubReleaseChecker $checker): int
    {
        $current = $status->displayVersion();
        $this->components->info(config('app.name').' release check');
        $this->line('  Installed: '.$current);

        if (! (bool) config('nrth.releases.check_enabled')) {
            $this->components->warn('NRTH_RELEASE_CHECK is disabled; skipping GitHub.');

            return self::SUCCESS;
        }

        $latest = $checker->refresh();
        if ($latest === null) {
            $this->components->warn('Could not read the latest GitHub Release (cached the miss).');

            return self::SUCCESS;
        }

        $this->line('  Latest GitHub Release: '.$latest->version);
        if (version_compare($latest->version, $current, '>')) {
            $this->components->info('Update available: '.$latest->htmlUrl);
        } else {
            $this->components->info('This instance is on the latest GitHub Release.');
        }

        return self::SUCCESS;
    }
}
