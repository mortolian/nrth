<?php

namespace App\Http\Controllers\Web\Settings;

use App\Domain\Instance\Services\InstanceBackupDestinationSettings;
use App\Domain\Instance\Services\InstanceBackupRetentionSettings;
use App\Domain\Instance\Services\InstanceMailSettings;
use App\Domain\Instance\Services\InstanceOperatorService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class InstanceSettingsController extends Controller
{
    public function __construct(
        private readonly InstanceOperatorService $operators,
        private readonly InstanceBackupRetentionSettings $backupRetention,
        private readonly InstanceBackupDestinationSettings $backupDestinations,
        private readonly InstanceMailSettings $mailSettings,
    ) {}

    public function edit(): Response
    {
        Gate::authorize('manageInstanceBackups');

        $operatorCount = count($this->operators->listEffectiveOperators());

        return Inertia::render('Settings/Instance/Index', [
            'mail_summary' => $this->mailSettings->publicProps()['summary'],
            'operators_summary' => $operatorCount === 1
                ? '1 operator'
                : "{$operatorCount} operators",
        ]);
    }

    public function mail(Request $request): Response
    {
        Gate::authorize('manageInstanceBackups');

        $this->mailSettings->applyToRuntime();

        return Inertia::render('Settings/Instance/Mail', [
            'mail' => $this->mailSettings->publicProps(),
            'test_to_default' => (string) $request->user()->email,
        ]);
    }

    public function operators(): Response
    {
        Gate::authorize('manageInstanceBackups');

        return Inertia::render('Settings/Instance/Operators', [
            'operators' => $this->operators->listEffectiveOperators(),
            'env_break_glass_configured' => $this->operators->envOperatorEmails() !== [],
        ]);
    }

    public function updateBackupRetention(Request $request): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');

        $validated = $request->validate([
            'keep_daily' => ['required', 'integer', 'min:1', 'max:90'],
            'keep_weekly' => ['required', 'integer', 'min:0', 'max:104'],
            'keep_monthly' => ['required', 'integer', 'min:0', 'max:60'],
            'keep_yearly' => ['required', 'integer', 'min:0', 'max:20'],
            'weekly_on' => ['required', 'string', 'in:sunday,monday,tuesday,wednesday,thursday,friday,saturday'],
            'delete_oldest_backups_when_using_more_megabytes_than' => [
                'nullable',
                'integer',
                'min:100',
                'max:200000',
            ],
        ]);

        $this->backupRetention->update($validated);

        activity()
            ->causedBy($request->user())
            ->withProperties([
                'action' => 'instance_backup_retention_updated',
                'settings' => $validated,
            ])
            ->log('Updated instance backup retention');

        return redirect()
            ->route('backups-exports.retention')
            ->with('success', 'Backup retention settings saved.');
    }

    public function updateBackupDestinations(Request $request): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');

        $validated = $request->validate([
            's3.enabled' => ['required', 'boolean'],
            's3.key' => ['nullable', 'string', 'max:255'],
            's3.secret' => ['nullable', 'string', 'max:255'],
            's3.region' => ['nullable', 'string', 'max:64'],
            's3.bucket' => ['nullable', 'string', 'max:255'],
            's3.endpoint' => ['nullable', 'string', 'max:255'],
            's3.use_path_style_endpoint' => ['sometimes', 'boolean'],
            's3.root' => ['nullable', 'string', 'max:255'],
            'path.enabled' => ['required', 'boolean'],
            'path.root' => ['nullable', 'string', 'max:1024'],
        ]);

        $this->backupDestinations->update($validated);

        activity()
            ->causedBy($request->user())
            ->withProperties([
                'action' => 'instance_backup_destinations_updated',
                's3_enabled' => (bool) ($validated['s3']['enabled'] ?? false),
                'path_enabled' => (bool) ($validated['path']['enabled'] ?? false),
            ])
            ->log('Updated instance backup offsite destinations');

        return redirect()
            ->route('backups-exports.destinations')
            ->with('success', 'Offsite backup destinations saved.');
    }

    public function testBackupS3(Request $request): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');

        $validated = $request->validate([
            'key' => ['nullable', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:64'],
            'bucket' => ['nullable', 'string', 'max:255'],
            'endpoint' => ['nullable', 'string', 'max:255'],
            'use_path_style_endpoint' => ['sometimes', 'boolean'],
            'root' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->backupDestinations->testS3($validated);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                's3.bucket' => $e->getMessage() ?: __('S3 connection test failed.'),
            ]);
        }

        return redirect()
            ->route('backups-exports.destinations')
            ->with('success', 'S3 offsite connection test succeeded.');
    }

    public function testBackupPath(Request $request): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');

        $validated = $request->validate([
            'root' => ['nullable', 'string', 'max:1024'],
        ]);

        try {
            $this->backupDestinations->testPath($validated);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'path.root' => $e->getMessage() ?: __('Path/NFS connection test failed.'),
            ]);
        }

        return redirect()
            ->route('backups-exports.destinations')
            ->with('success', 'Path/NFS offsite connection test succeeded.');
    }

    public function updateMail(Request $request): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'scheme' => ['nullable', 'string', Rule::in(['smtp', 'smtps', 'tls', 'null', ''])],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'from_address' => ['nullable', 'string', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
        ]);

        if (($validated['scheme'] ?? null) === 'null' || ($validated['scheme'] ?? null) === '') {
            $validated['scheme'] = null;
        }

        $this->mailSettings->update($validated);

        activity()
            ->causedBy($request->user())
            ->withProperties([
                'action' => 'instance_mail_updated',
                'enabled' => (bool) ($validated['enabled'] ?? false),
                'host' => $validated['host'] ?? null,
                'from_address' => $validated['from_address'] ?? null,
            ])
            ->log('Updated instance SMTP settings');

        return redirect()
            ->route('settings.instance.mail')
            ->with('success', 'Outbound email settings saved.');
    }

    public function testMail(Request $request): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');

        $validated = $request->validate([
            'to' => ['nullable', 'email', 'max:255'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'scheme' => ['nullable', 'string', Rule::in(['smtp', 'smtps', 'tls', 'null', ''])],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'from_address' => ['nullable', 'string', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
        ]);

        if (($validated['scheme'] ?? null) === 'null' || ($validated['scheme'] ?? null) === '') {
            $validated['scheme'] = null;
        }

        $to = trim((string) ($validated['to'] ?? ''));
        if ($to === '') {
            $to = (string) $request->user()->email;
        }
        unset($validated['to']);

        try {
            $this->mailSettings->sendTest($to, $validated);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'host' => $e->getMessage() ?: __('SMTP test failed.'),
            ]);
        }

        return redirect()
            ->route('settings.instance.mail')
            ->with('success', "Test email sent to {$to}.");
    }

    public function addOperator(Request $request): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $user = $this->operators->addByEmail($validated['email']);

        activity()
            ->causedBy($request->user())
            ->performedOn($user)
            ->withProperties(['action' => 'instance_operator_added', 'email' => $user->email])
            ->log('Added instance operator');

        return redirect()
            ->route('settings.instance.operators')
            ->with('success', "Added {$user->email} as an instance operator.");
    }

    public function removeOperator(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('manageInstanceBackups');

        $this->operators->remove($request->user(), $user);

        activity()
            ->causedBy($request->user())
            ->performedOn($user)
            ->withProperties(['action' => 'instance_operator_removed', 'email' => $user->email])
            ->log('Removed instance operator');

        return redirect()
            ->route('settings.instance.operators')
            ->with('success', "Removed {$user->email} as an instance operator.");
    }
}
