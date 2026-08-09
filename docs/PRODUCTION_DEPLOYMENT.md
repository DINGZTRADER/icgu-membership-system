# ICGU Membership Platform — Production Deployment Runbook

## 1. Go-live principle

Do not enable production traffic until `php artisan icgu:production-check --strict` passes. The command checks the application key, HTTPS URL, PostgreSQL connection/schema/TLS, secure session settings, durable cache/queue, live mail, document-storage durability and any payment providers declared mandatory.

## 2. Required infrastructure

Create dedicated production resources; never repurpose a development/client project.

- **Application:** Laravel Cloud, connected to `DINGZTRADER/icgu-membership-system` and `main`.
- **Database:** dedicated ICGU Supabase PostgreSQL project. Use the Session Pooler connection string, `DB_SCHEMA=icgu` and `DB_SSLMODE=require`.
- **Documents:** private persistent object storage. Laravel Cloud's application filesystem is ephemeral, so `MEMBERSHIP_DOCUMENT_DISK=local` is blocked by production policy unless a genuinely persistent encrypted volume is explicitly accepted.
- **Email:** SMTP credentials authorized to send as `icgu@icgu.org`.
- **Payments:** ICGU-owned MTN MoMo Collections production credentials. Airtel Money remains disabled until ICGU merchant credentials and the current Uganda API contract are available.

## 3. Database bootstrap

On the empty ICGU production database, execute once:

```bash
psql "$DB_URL" -v ON_ERROR_STOP=1 -f database/bootstrap/supabase.sql
```

Then deploy the Laravel schema:

```bash
php artisan migrate --force
php artisan db:seed --class='Database\Seeders\RbacSeeder' --force
```

Do **not** run the prototype `DatabaseSeeder` in production.

## 4. Laravel Cloud environment

Set secrets in Laravel Cloud, never in Git. At minimum configure:

```text
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<production-domain>
APP_KEY=<generated-production-key>
DB_URL=<supabase-session-pooler-url>
DB_SCHEMA=icgu
DB_SSLMODE=require
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=icgu@icgu.org
MEMBERSHIP_DOCUMENT_DISK=<persistent-private-disk>
```

The production `APP_KEY` is part of the security boundary because staff MFA secrets are encrypted with it. Back it up in the approved secrets manager. Do not rotate it casually; an unmanaged rotation would make existing encrypted MFA secrets unreadable.

## 5. Build and deploy commands

Recommended build command:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan optimize
```

Recommended deploy command:

```bash
php artisan migrate --force
php artisan icgu:production-check --strict
```

Enable Laravel Cloud's task scheduler and a queue worker/worker cluster. The application schedules renewals, reminder dispatch and MTN payment reconciliation. Shared database cache is required for `onOneServer()` scheduling locks.

## 6. Staff provisioning and MFA

Provision each real Secretariat user interactively from the production environment. Passwords are entered through a hidden prompt and never supplied as command arguments:

```bash
php artisan icgu:staff-user ceo@icgu.org ceo --name="Chief Executive Officer"
php artisan icgu:staff-user membership@icgu.org membership-officer --name="Membership Officer"
php artisan icgu:staff-user finance@icgu.org finance-officer --name="Finance Officer"
```

Use only verified ICGU addresses; the examples above illustrate command structure and are not a declaration that those mailboxes exist.

On first Secretariat login every staff account must configure TOTP MFA. The setup produces one-time recovery codes which should be stored in the approved password manager/offline recovery process.

## 7. MTN MoMo production activation

Keep `MTN_MOMO_ENABLED=false` until ICGU's MTN Uganda Collections account has been provisioned and tested.

For Uganda production set:

```text
MTN_MOMO_ENABLED=true
MTN_MOMO_BASE_URL=<MTN production base URL supplied during onboarding>
MTN_MOMO_SUBSCRIPTION_KEY=<secret>
MTN_MOMO_API_USER=<secret>
MTN_MOMO_API_KEY=<secret>
MTN_MOMO_TARGET_ENVIRONMENT=mtnuganda
MTN_MOMO_CALLBACK_URL=https://<production-domain>/api/integrations/mtn-momo/callback
MTN_MOMO_CURRENCY=UGX
PRODUCTION_REQUIRE_MTN_MOMO=true
```

The callback URL passed to MTN has the payment-request UUID appended to this base URL.

### Settlement safety

A callback is never treated as proof of payment. It is stored as webhook evidence and queues reconciliation. The application polls MTN's RequestToPay status endpoint. Only a provider result of `SUCCESSFUL`, with amount and currency matching the original request, can create a financial-ledger payment and receipt. Provider transaction references are used for idempotency.

First-year application payments do **not** automatically admit a member; an authorized ICGU admission action is still required. Renewal payments may reactivate/extend membership through the established renewal engine after verified settlement.

## 8. Airtel Money

Leave `AIRTEL_MONEY_ENABLED=false` until ICGU has an Airtel Uganda merchant/API account and the current production contract has been supplied. Do not copy unofficial or legacy endpoints into production configuration.

## 9. Documents and backups

Membership documents contain sensitive applicant/member information and must use private persistent object storage.

Database backup and object backup are separate concerns. Supabase database backups contain Storage metadata but do not restore the underlying Storage objects. Maintain an independent export/replication policy for uploaded documents and periodically test restoration.

Recommended minimum operational controls:

- daily database backups appropriate to the Supabase plan;
- regular off-site logical export (`supabase db dump`/`pg_dump`) for independent recovery;
- separate object-storage backup or replication;
- documented quarterly restoration test;
- retention policy approved by ICGU for applicant/member records.

## 10. Health, monitoring and operations

Use `GET /up` for uptime monitoring. Sprint 8 extends the Laravel health event with a database query, so the endpoint fails when the application cannot reach PostgreSQL.

Monitor:

- HTTP availability and latency;
- Laravel application error logs;
- queue depth/failed jobs;
- scheduler operation;
- `payment_requests` stuck in `pending`;
- failed `payment_webhook_events`;
- failed communication logs;
- database/storage capacity;
- overdue membership invoices.

Useful commands:

```bash
php artisan icgu:production-check --strict
php artisan icgu:reconcile-mobile-money --limit=100
php artisan schedule:list
php artisan queue:failed
```

## 11. Pre-launch acceptance sequence

1. Create dedicated production Supabase and persistent private object storage.
2. Configure Laravel Cloud production environment and custom HTTPS domain.
3. Bootstrap the private `icgu` schema and run migrations/RBAC seed.
4. Run `icgu:production-check --strict` until all blocking checks pass.
5. Provision a minimal real staff set; each user completes MFA.
6. Configure SMTP and send controlled renewal/application test mail.
7. Import a small, sanitized/approved pilot member dataset.
8. Test staff application review, invoice, manual payment, receipt and admission.
9. Complete MTN sandbox/UAT; then insert production credentials and test a low-value/approved live transaction with ICGU finance oversight.
10. Verify member portal, renewal, MoMo, receipt and public credential verification end-to-end.
11. Validate backup/restore procedures before broad member onboarding.
12. Launch first to a controlled ICGU pilot cohort, then expand after reconciliation and support checks.

## 12. Rollback

If a deployment introduces an application defect, roll back the application version in Laravel Cloud. Do not reverse financial ledger entries by deleting rows. Financial corrections must use the platform's append-only reversal/refund/waiver mechanisms. Database migration rollback should be a separately reviewed operation because production data may already depend on the new schema.
