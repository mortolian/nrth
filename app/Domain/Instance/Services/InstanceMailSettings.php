<?php

namespace App\Domain\Instance\Services;

use App\Mail\InstanceSmtpTestMail;
use App\Models\InstanceSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

final class InstanceMailSettings
{
    public const SETTING_KEY = 'mail.smtp';

    /**
     * @return array{
     *     enabled: bool,
     *     host: string,
     *     port: int,
     *     scheme: string|null,
     *     username: string,
     *     password: string,
     *     from_address: string,
     *     from_name: string
     * }
     */
    public function defaults(): array
    {
        return [
            'enabled' => false,
            'host' => '',
            'port' => 587,
            'scheme' => 'smtp',
            'username' => '',
            'password' => '',
            'from_address' => '',
            'from_name' => '',
        ];
    }

    /**
     * Decrypted settings for applying / testing (never send to Inertia).
     *
     * @return array{
     *     enabled: bool,
     *     host: string,
     *     port: int,
     *     scheme: string|null,
     *     username: string,
     *     password: string,
     *     from_address: string,
     *     from_name: string
     * }
     */
    public function current(): array
    {
        $defaults = $this->defaults();

        if (! $this->tableReady()) {
            return $defaults;
        }

        $stored = InstanceSetting::query()->find(self::SETTING_KEY)?->value;
        if (! is_array($stored)) {
            return $defaults;
        }

        return $this->hydrateFromStored($stored);
    }

    /**
     * Safe props for the operator UI (password never included).
     *
     * @return array{
     *     enabled: bool,
     *     host: string,
     *     port: int,
     *     scheme: string|null,
     *     username: string,
     *     password_set: bool,
     *     from_address: string,
     *     from_name: string,
     *     using_instance: bool,
     *     summary: string
     * }
     */
    public function publicProps(): array
    {
        $current = $this->current();
        $usingInstance = $current['enabled'];

        return [
            'enabled' => $current['enabled'],
            'host' => $current['host'],
            'port' => $current['port'],
            'scheme' => $current['scheme'],
            'username' => $current['username'],
            'password_set' => $current['password'] !== '',
            'from_address' => $current['from_address'],
            'from_name' => $current['from_name'],
            'using_instance' => $usingInstance,
            'summary' => $this->summary($current),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     enabled: bool,
     *     host: string,
     *     port: int,
     *     scheme: string|null,
     *     username: string,
     *     password_set: bool,
     *     from_address: string,
     *     from_name: string,
     *     using_instance: bool,
     *     summary: string
     * }
     */
    public function update(array $input): array
    {
        if (! $this->tableReady()) {
            throw ValidationException::withMessages([
                'enabled' => __('Instance settings are not available yet. Run migrations and try again.'),
            ]);
        }

        $previous = $this->current();
        $normalized = $this->normalizeInput($input, $previous);
        $this->assertValidWhenEnabled($normalized);

        InstanceSetting::query()->updateOrCreate(
            ['key' => self::SETTING_KEY],
            ['value' => $this->serializeForStorage($normalized)],
        );

        $this->applyToRuntime($normalized);

        return $this->publicProps();
    }

    /**
     * Apply instance SMTP to Laravel mail config when enabled; otherwise leave env config alone.
     *
     * @param  array{
     *     enabled: bool,
     *     host: string,
     *     port: int,
     *     scheme: string|null,
     *     username: string,
     *     password: string,
     *     from_address: string,
     *     from_name: string
     * }|null  $settings
     */
    public function applyToRuntime(?array $settings = null): void
    {
        $settings ??= $this->current();

        if (! $settings['enabled']) {
            return;
        }

        $mailers = config('mail.mailers', []);
        $smtp = is_array($mailers['smtp'] ?? null) ? $mailers['smtp'] : [];

        $smtp['transport'] = 'smtp';
        $smtp['host'] = $settings['host'];
        $smtp['port'] = $settings['port'];
        $smtp['scheme'] = $settings['scheme'];
        $smtp['username'] = $settings['username'] !== '' ? $settings['username'] : null;
        $smtp['password'] = $settings['password'] !== '' ? $settings['password'] : null;

        $mailers['smtp'] = $smtp;

        config([
            'mail.default' => 'smtp',
            'mail.mailers' => $mailers,
            'mail.from.address' => $settings['from_address'],
            'mail.from.name' => $settings['from_name'] !== ''
                ? $settings['from_name']
                : config('app.name'),
        ]);

        try {
            Mail::purgeMailers();
        } catch (Throwable) {
            //
        }
    }

    /**
     * Merge optional unsaved form overrides, apply, and send a test message to $toEmail.
     *
     * @param  array<string, mixed>  $override
     */
    public function sendTest(string $toEmail, array $override = []): void
    {
        $previous = $this->current();
        $merged = $this->normalizeInput(array_merge(
            [
                'enabled' => true,
                'host' => $previous['host'],
                'port' => $previous['port'],
                'scheme' => $previous['scheme'],
                'username' => $previous['username'],
                'password' => '',
                'from_address' => $previous['from_address'],
                'from_name' => $previous['from_name'],
            ],
            $override,
            ['enabled' => true],
        ), $previous);

        $this->assertValidWhenEnabled($merged);
        $this->applyToRuntime($merged);

        try {
            $mailable = new InstanceSmtpTestMail;

            if ($merged['from_address'] !== '') {
                $mailable->from(
                    $merged['from_address'],
                    $merged['from_name'] !== '' ? $merged['from_name'] : (string) config('app.name'),
                );
            }

            Mail::to($toEmail)->send($mailable);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            $detail = trim($e->getMessage());
            $message = $detail !== ''
                ? __('Could not send the test email: :error', ['error' => $detail])
                : __('Could not send the test email. Check the SMTP host, port, encryption, and credentials.');

            throw ValidationException::withMessages([
                'host' => $message,
            ]);
        }
    }

    /**
     * @param  array{
     *     enabled: bool,
     *     host: string,
     *     port: int,
     *     scheme: string|null,
     *     username: string,
     *     password: string,
     *     from_address: string,
     *     from_name: string
     * }  $settings
     */
    private function summary(array $settings): string
    {
        if (! $settings['enabled']) {
            return 'Using .env mail settings';
        }

        $from = $settings['from_address'] !== '' ? $settings['from_address'] : 'no from address';

        return "SMTP via {$settings['host']}:{$settings['port']} · {$from}";
    }

    /**
     * @param  array{
     *     enabled: bool,
     *     host: string,
     *     port: int,
     *     scheme: string|null,
     *     username: string,
     *     password: string,
     *     from_address: string,
     *     from_name: string
     * }  $settings
     */
    private function assertValidWhenEnabled(array $settings): void
    {
        if (! $settings['enabled']) {
            return;
        }

        $errors = [];

        if ($settings['host'] === '') {
            $errors['host'] = __('Host is required when SMTP is enabled.');
        }

        if ($settings['port'] < 1 || $settings['port'] > 65535) {
            $errors['port'] = __('Port must be between 1 and 65535.');
        }

        if ($settings['from_address'] === '' || ! filter_var($settings['from_address'], FILTER_VALIDATE_EMAIL)) {
            $errors['from_address'] = __('A valid From address is required when SMTP is enabled.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array{
     *     enabled: bool,
     *     host: string,
     *     port: int,
     *     scheme: string|null,
     *     username: string,
     *     password: string,
     *     from_address: string,
     *     from_name: string
     * }  $previous
     * @return array{
     *     enabled: bool,
     *     host: string,
     *     port: int,
     *     scheme: string|null,
     *     username: string,
     *     password: string,
     *     from_address: string,
     *     from_name: string
     * }
     */
    private function normalizeInput(array $input, array $previous): array
    {
        $password = trim((string) ($input['password'] ?? ''));
        $schemeRaw = $input['scheme'] ?? $previous['scheme'];
        $scheme = $this->normalizeScheme($schemeRaw);

        $portRaw = $input['port'] ?? $previous['port'];
        $port = is_numeric($portRaw) ? (int) $portRaw : $previous['port'];

        return [
            'enabled' => filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'host' => trim((string) ($input['host'] ?? $previous['host'])),
            'port' => $port,
            'scheme' => $scheme,
            'username' => trim((string) ($input['username'] ?? $previous['username'])),
            'password' => $password !== '' ? $password : $previous['password'],
            'from_address' => trim((string) ($input['from_address'] ?? $previous['from_address'])),
            'from_name' => trim((string) ($input['from_name'] ?? $previous['from_name'])),
        ];
    }

    private function normalizeScheme(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 'null') {
            return null;
        }

        $scheme = strtolower(trim((string) $value));

        // Legacy UI stored STARTTLS as "tls"; Symfony Mailer only accepts smtp | smtps.
        if ($scheme === 'tls') {
            return 'smtp';
        }

        return in_array($scheme, ['smtp', 'smtps'], true) ? $scheme : null;
    }

    /**
     * @param  array{
     *     enabled: bool,
     *     host: string,
     *     port: int,
     *     scheme: string|null,
     *     username: string,
     *     password: string,
     *     from_address: string,
     *     from_name: string
     * }  $settings
     * @return array<string, mixed>
     */
    private function serializeForStorage(array $settings): array
    {
        return [
            'enabled' => $settings['enabled'],
            'host' => $settings['host'],
            'port' => $settings['port'],
            'scheme' => $settings['scheme'],
            'username' => $settings['username'],
            'password_encrypted' => $settings['password'] !== ''
                ? Crypt::encryptString($settings['password'])
                : null,
            'from_address' => $settings['from_address'],
            'from_name' => $settings['from_name'],
        ];
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array{
     *     enabled: bool,
     *     host: string,
     *     port: int,
     *     scheme: string|null,
     *     username: string,
     *     password: string,
     *     from_address: string,
     *     from_name: string
     * }
     */
    private function hydrateFromStored(array $stored): array
    {
        $defaults = $this->defaults();

        return [
            'enabled' => filter_var($stored['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'host' => trim((string) ($stored['host'] ?? $defaults['host'])),
            'port' => is_numeric($stored['port'] ?? null) ? (int) $stored['port'] : $defaults['port'],
            'scheme' => $this->normalizeScheme($stored['scheme'] ?? $defaults['scheme']),
            'username' => trim((string) ($stored['username'] ?? $defaults['username'])),
            'password' => $this->decryptString($stored['password_encrypted'] ?? null),
            'from_address' => trim((string) ($stored['from_address'] ?? $defaults['from_address'])),
            'from_name' => trim((string) ($stored['from_name'] ?? $defaults['from_name'])),
        ];
    }

    private function decryptString(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return '';
        }
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('instance_settings');
        } catch (Throwable) {
            return false;
        }
    }
}
