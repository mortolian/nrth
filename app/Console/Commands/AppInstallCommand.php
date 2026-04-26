<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\User;
use Database\Seeders\DefaultChartOfAccountsSeeder;
use Database\Seeders\DefaultTaxRatesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

class AppInstallCommand extends Command
{
    protected $signature = 'app:install';

    protected $description = 'Interactive installer: requirements, key, migrations, admin user, SA chart of accounts, and default VAT rates.';

    public function handle(): int
    {
        $this->components->info(config('app.name').' self-hosted installation');
        $this->newLine();

        if (! $this->checkSystemRequirements()) {
            return self::FAILURE;
        }

        if (User::query()->exists()) {
            $this->components->error('At least one user already exists. This installer is meant for an empty database.');
            $this->line('Run <fg=cyan>php artisan migrate:fresh</> on a non-production database, or create users via the application.');

            return self::FAILURE;
        }

        if (empty((string) config('app.key'))) {
            $this->components->task('Generating application key', function (): void {
                Artisan::call('key:generate', ['--force' => true, '--ansi' => true]);
            });
            $this->newLine();
        } else {
            $this->components->info('Application key is already set.');
            $this->newLine();
        }

        $this->components->task('Running database migrations', function (): void {
            Artisan::call('migrate', ['--force' => true, '--ansi' => true]);
            if (Artisan::output() !== '') {
                $this->output->write(Artisan::output());
            }
        });

        $this->newLine();
        $this->components->info('Create the administrator account and company');
        $this->newLine();

        $name = (string) $this->ask('Full name');
        $email = (string) $this->ask('Email address');
        $companyName = (string) $this->ask('Company name (team)');
        $password = (string) $this->secret('Password');
        $passwordConfirmation = (string) $this->secret('Confirm password');

        $validator = Validator::make(
            [
                'name' => $name,
                'email' => $email,
                'company_name' => $companyName,
                'password' => $password,
                'password_confirmation' => $passwordConfirmation,
            ],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                'company_name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'confirmed', Password::defaults()],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $user = null;
        $team = null;

        try {
            DB::transaction(function () use ($name, $email, $password, $companyName, &$user, &$team): void {
                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'password' => $password,
                    'email_verified_at' => now(),
                ]);

                $team = $user->ownedTeams()->create([
                    'name' => $companyName,
                    'personal_team' => false,
                ]);

                $user->forceFill(['current_team_id' => $team->id])->save();

                Role::findOrCreate('admin', 'web');
                $user->assignRole('admin');

                app(PermissionRegistrar::class)->forgetCachedPermissions();
            });
        } catch (Throwable $e) {
            $this->components->error('Could not create the administrator: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $user instanceof User || ! $team instanceof Team) {
            $this->components->error('Installation did not produce a valid user or team record.');

            return self::FAILURE;
        }

        $this->components->task('Seeding default chart of accounts (South Africa)', function () use ($team): void {
            (new DefaultChartOfAccountsSeeder)->runForTeam($team);
        });

        $this->components->task('Creating default tax rates (VAT 15%, VAT 0%, VAT Exempt)', function () use ($team): void {
            (new DefaultTaxRatesSeeder)->runForTeam($team);
        });

        $this->newLine(2);
        $url = rtrim((string) config('app.url'), '/');
        $this->components->success('Installation complete.');
        $this->line("  Application URL: <fg=cyan>{$url}</>");
        $this->line('  Sign in with the email and password you just entered.');
        $this->newLine();

        return self::SUCCESS;
    }

    private function checkSystemRequirements(): bool
    {
        $this->components->info('Checking system requirements');
        $phpOk = version_compare(PHP_VERSION, '8.3.0', '>=');
        $this->line($phpOk
            ? '  <fg=green>✓</> PHP '.PHP_VERSION.' (>= 8.3 required by this project)'
            : '  <fg=red>✗</> PHP '.PHP_VERSION.' — PHP 8.3 or newer is required.');

        $required = [
            'ctype' => 'ctype',
            'curl' => 'curl',
            'dom' => 'dom',
            'fileinfo' => 'fileinfo',
            'filter' => 'filter',
            'hash' => 'hash',
            'json' => 'json',
            'mbstring' => 'mbstring',
            'openssl' => 'openssl',
            'pcre' => 'pcre',
            'pdo' => 'pdo',
            'session' => 'session',
            'tokenizer' => 'tokenizer',
            'xml' => 'xml',
            'bcmath' => 'bcmath',
        ];

        $allExtOk = true;

        foreach ($required as $label => $ext) {
            $loaded = extension_loaded($ext);
            $allExtOk = $allExtOk && $loaded;
            $this->line($loaded
                ? "  <fg=green>✓</> ext-{$label}"
                : "  <fg=red>✗</> ext-{$label} — missing");
        }

        $defaultConnection = (string) config('database.default');
        $driver = (string) config("database.connections.{$defaultConnection}.driver");
        $pdoExtension = match ($driver) {
            'pgsql' => 'pdo_pgsql',
            'mysql', 'mariadb' => 'pdo_mysql',
            'sqlite' => 'pdo_sqlite',
            default => null,
        };

        if ($pdoExtension !== null) {
            $pdoOk = extension_loaded($pdoExtension);
            $allExtOk = $allExtOk && $pdoOk;
            $this->line($pdoOk
                ? "  <fg=green>✓</> {$pdoExtension} (for {$driver})"
                : "  <fg=red>✗</> {$pdoExtension} — required for database driver [{$driver}]");
        }

        $this->newLine();

        if (! $phpOk || ! $allExtOk) {
            $this->components->error('System requirements are not satisfied. Install missing PHP extensions and retry.');

            return false;
        }

        $this->components->success('System requirements look good.');
        $this->newLine();

        return true;
    }
}
