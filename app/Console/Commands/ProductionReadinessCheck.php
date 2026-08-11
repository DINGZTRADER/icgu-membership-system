<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProductionReadinessCheck extends Command
{
    protected $signature = 'icgu:production-check {--strict : Treat production warnings as failures}';
    protected $description = 'Validate ICGU production configuration, database reachability and integration safety gates.';

    /** @var list<array{level:string,check:string,message:string}> */
    private array $results = [];

    public function handle(): int
    {
        $strict = (bool) $this->option('strict');

        $this->check(config('app.env') === 'production', 'Application environment', 'APP_ENV should be production.', $strict);
        $this->check(config('app.debug') === false, 'Debug mode', 'APP_DEBUG must be false.', true);
        $this->check(Str::startsWith((string) config('app.url'), 'https://'), 'Application URL', 'APP_URL must use HTTPS.', $strict);
        $this->check(trim((string) config('app.key')) !== '', 'Application key', 'APP_KEY must be configured.', true);

        $googleClientId = trim((string) config('services.google.client_id'));
        $googleClientSecret = trim((string) config('services.google.client_secret'));
        $googleRedirect = trim((string) config('services.google.redirect'));
        $googleHostedDomain = mb_strtolower(trim((string) config('services.google.hosted_domain')));
        $this->check($googleClientId !== '', 'Google Workspace client ID', 'GOOGLE_CLIENT_ID is required for Secretariat sign-in.', $strict);
        $this->check($googleClientSecret !== '', 'Google Workspace client secret', 'GOOGLE_CLIENT_SECRET is required for Secretariat sign-in.', $strict);
        $this->check($googleHostedDomain === 'icgu.org', 'Google Workspace hosted domain', 'GOOGLE_HOSTED_DOMAIN must be icgu.org.', true);
        $this->check(Str::startsWith($googleRedirect, 'https://'), 'Google OAuth callback HTTPS', 'GOOGLE_REDIRECT_URI must use HTTPS in production.', $strict);
        $this->check(Str::endsWith($googleRedirect, '/auth/google/staff/callback'), 'Google OAuth callback path', 'GOOGLE_REDIRECT_URI must end with /auth/google/staff/callback.', $strict);

        $this->check(config('database.default') === 'pgsql', 'Database driver', 'Production database must use PostgreSQL.', true);
        $this->check(config('database.connections.pgsql.search_path') === 'icgu', 'Private database schema', 'DB_SCHEMA must be icgu.', true);
        $this->check(config('database.connections.pgsql.sslmode') === 'require', 'Database TLS', 'DB_SSLMODE must be require.', $strict);

        try {
            DB::select('select 1');
            $schema = DB::selectOne('select current_schema() as schema');
            $this->passResult('Database connectivity', 'PostgreSQL is reachable.');
            $this->check(($schema->schema ?? null) === 'icgu', 'Database search path', 'The active PostgreSQL schema must resolve to icgu.', true);
        } catch (\Throwable $exception) {
            $this->failResult('Database connectivity', 'PostgreSQL check failed: '.$exception->getMessage());
        }

        $this->check(config('session.driver') === 'database', 'Session persistence', 'SESSION_DRIVER should be database.', $strict);
        $this->check((bool) config('session.encrypt') === true, 'Session encryption', 'SESSION_ENCRYPT must be true.', true);
        $this->check((bool) config('session.secure') === true, 'Secure cookies', 'SESSION_SECURE_COOKIE must be true.', true);
        $this->check((bool) config('session.http_only') === true, 'HTTP-only cookies', 'SESSION_HTTP_ONLY must be true.', true);
        $this->check(in_array(config('session.same_site'), ['lax', 'strict'], true), 'SameSite cookies', 'SESSION_SAME_SITE must be lax or strict.', true);
        $this->check(
            (bool) config('production.require_staff_mfa', false),
            'Staff MFA policy',
            'STAFF_MFA_REQUIRED must be enabled for the production pilot.',
            $strict,
        );

        $this->check(config('queue.default') !== 'sync', 'Durable queue', 'QUEUE_CONNECTION must not be sync in production.', $strict);
        $this->check(! in_array(config('cache.default'), ['array', 'null'], true), 'Shared cache', 'CACHE_STORE must be a persistent shared store.', $strict);

        if ((bool) config('production.require_live_mail', true)) {
            $this->check(! in_array(config('mail.default'), ['log', 'array'], true), 'Live email transport', 'Configure a live mail transport before go-live.', $strict);
            if (config('mail.default') === 'smtp') {
                $this->check(trim((string) config('mail.mailers.smtp.host')) !== '', 'SMTP host', 'MAIL_HOST is required for SMTP.', $strict);
                $scheme = trim((string) config('mail.mailers.smtp.scheme'));
                $this->check($scheme === '' || in_array($scheme, ['smtp', 'smtps'], true), 'SMTP scheme', 'MAIL_SCHEME must be smtp, smtps, or left blank for Laravel to infer it from the port.', true);
            }
        }

        $documentDisk = (string) config('filesystems.membership_documents', 'local');
        $configuredDisks = (array) config('filesystems.disks', []);
        $diskExists = array_key_exists($documentDisk, $configuredDisks);
        $this->check(
            $diskExists,
            'Membership document disk',
            "Configured membership document disk '{$documentDisk}' is not defined in filesystems.disks.",
            true,
        );

        if ($diskExists) {
            $diskConfig = (array) ($configuredDisks[$documentDisk] ?? []);
            $driver = (string) ($diskConfig['driver'] ?? '');

            if ($documentDisk === 'local' && ! (bool) config('production.allow_local_document_storage', false)) {
                $this->check(false, 'Membership document durability', 'Laravel Cloud local filesystems are ephemeral; attach private object storage and set MEMBERSHIP_DOCUMENT_DISK=s3.', $strict);
            } elseif ($driver === 's3') {
                $this->check(class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class), 'S3 filesystem adapter', 'Install league/flysystem-aws-s3-v3 before using Laravel Cloud Object Storage.', true);
                $this->check(trim((string) ($diskConfig['key'] ?? '')) !== '', 'Object storage access key', 'AWS_ACCESS_KEY_ID is required for the attached private bucket.', $strict);
                $this->check(trim((string) ($diskConfig['secret'] ?? '')) !== '', 'Object storage secret key', 'AWS_SECRET_ACCESS_KEY is required for the attached private bucket.', $strict);
                $this->check(trim((string) ($diskConfig['bucket'] ?? '')) !== '', 'Object storage bucket', 'AWS_BUCKET is required for the attached private bucket.', $strict);
                $endpoint = trim((string) ($diskConfig['endpoint'] ?? ''));
                $this->check($endpoint !== '', 'Object storage endpoint', 'AWS_ENDPOINT is required for Laravel Cloud Object Storage.', $strict);
                $this->check($endpoint === '' || Str::startsWith($endpoint, 'https://'), 'Object storage endpoint HTTPS', 'AWS_ENDPOINT must use HTTPS.', $strict);
                $this->passResult('Membership document durability', 'S3-compatible private object storage is configured.');
            } else {
                $this->passResult('Membership document durability', 'Document storage policy is explicitly configured.');
            }
        }

        $mtnEnabled = (bool) config('services.mtn_momo.enabled', false);
        if ((bool) config('production.require_mtn_momo', false)) {
            $this->check($mtnEnabled, 'MTN MoMo required', 'Production policy requires MTN MoMo to be enabled.', $strict);
        }
        if ($mtnEnabled) {
            foreach (['subscription_key', 'api_user', 'api_key', 'callback_url', 'base_url'] as $key) {
                $this->check(trim((string) config('services.mtn_momo.'.$key)) !== '', 'MTN MoMo '.$key, 'Missing MTN MoMo configuration: '.$key.'.', true);
            }
            $this->check(Str::startsWith((string) config('services.mtn_momo.callback_url'), 'https://'), 'MTN callback HTTPS', 'MTN production callbacks must use HTTPS.', true);
            if ($strict) {
                $this->check(config('services.mtn_momo.target_environment') === 'mtnuganda', 'MTN Uganda environment', 'Production target environment must be mtnuganda.', true);
            }
        }

        if ((bool) config('services.airtel_money.enabled', false)) {
            foreach (['base_url', 'client_id', 'client_secret'] as $key) {
                $this->check(trim((string) config('services.airtel_money.'.$key)) !== '', 'Airtel Money '.$key, 'Missing Airtel Money configuration: '.$key.'.', true);
            }
        }

        $this->table(['Result', 'Check', 'Detail'], array_map(
            fn (array $row): array => [strtoupper($row['level']), $row['check'], $row['message']],
            $this->results,
        ));

        $failures = collect($this->results)->where('level', 'fail')->count();
        if ($failures > 0) {
            $this->error("Production readiness failed with {$failures} blocking check(s).");
            return self::FAILURE;
        }

        $this->info('ICGU production readiness checks passed.');
        return self::SUCCESS;
    }

    private function check(bool $condition, string $check, string $failureMessage, bool $blocking): void
    {
        if ($condition) {
            $this->passResult($check, 'OK');
            return;
        }

        $blocking ? $this->failResult($check, $failureMessage) : $this->warnResult($check, $failureMessage);
    }

    private function passResult(string $check, string $message): void
    {
        $this->results[] = ['level' => 'pass', 'check' => $check, 'message' => $message];
    }

    private function failResult(string $check, string $message): void
    {
        $this->results[] = ['level' => 'fail', 'check' => $check, 'message' => $message];
    }

    private function warnResult(string $check, string $message): void
    {
        $this->results[] = ['level' => 'warn', 'check' => $check, 'message' => $message];
    }
}
