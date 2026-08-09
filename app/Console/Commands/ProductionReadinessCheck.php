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

        $this->check(config('queue.default') !== 'sync', 'Durable queue', 'QUEUE_CONNECTION must not be sync in production.', $strict);
        $this->check(! in_array(config('cache.default'), ['array', 'null'], true), 'Shared cache', 'CACHE_STORE must be a persistent shared store.', $strict);

        if ((bool) config('production.require_live_mail', true)) {
            $this->check(! in_array(config('mail.default'), ['log', 'array'], true), 'Live email transport', 'Configure a live mail transport before go-live.', $strict);
            if (config('mail.default') === 'smtp') {
                $this->check(trim((string) config('mail.mailers.smtp.host')) !== '', 'SMTP host', 'MAIL_HOST is required for SMTP.', $strict);
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
            if ($documentDisk === 'local' && ! (bool) config('production.allow_local_document_storage', false)) {
                $this->check(false, 'Membership document durability', 'Local document storage is disabled by production policy; configure persistent/object storage or explicitly accept a persistent local volume.', $strict);
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
