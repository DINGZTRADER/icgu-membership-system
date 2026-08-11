# ICGU Membership Platform — Controlled Pilot Launch

## Objective

The platform is **pilot-ready** only after the application, production infrastructure and controlled member data all pass the release gates below. Real member data, credentials and payment secrets must never be committed to Git.

The initial pilot remains deliberately small. The default import cap is 50 member records per approved source file.

## 1. Production infrastructure gate

Before a real pilot, ICGU must have dedicated production infrastructure:

- Laravel Cloud production environment connected to `main`;
- dedicated ICGU Supabase PostgreSQL project using the private `icgu` schema;
- private Laravel Cloud Object Storage bucket for membership documents and pilot import files;
- live ICGU SMTP credentials;
- Google Workspace OAuth client restricted to the `icgu.org` staff domain;
- real Secretariat accounts with staff roles and MFA policy enabled;
- MTN Uganda production credentials only after sandbox/UAT is approved.

Do not reuse another client's Supabase project or object-storage bucket.

### Laravel Cloud private object storage

The Laravel Cloud application filesystem is ephemeral and must not hold member documents. In the production environment:

1. Add a **Laravel Object Storage** bucket from the environment infrastructure canvas.
2. Make the bucket **private**.
3. Use disk name `s3` and attach it to the production environment.
4. Re-deploy so Laravel Cloud injects `FILESYSTEM_DISK` and the AWS-compatible bucket credentials.
5. Confirm `MEMBERSHIP_DOCUMENT_DISK=s3`.
6. Run `php artisan icgu:production-check --strict`; the storage checks must pass.

The repository includes `league/flysystem-aws-s3-v3` and an `s3` filesystem disk for this purpose.

## 2. Google Workspace staff sign-in

Create a Google Cloud OAuth 2.0 **Web Application** client for the production membership platform.

Configure:

```text
GOOGLE_CLIENT_ID=<secret>
GOOGLE_CLIENT_SECRET=<secret>
GOOGLE_REDIRECT_URI=https://<production-domain>/auth/google/staff/callback
GOOGLE_HOSTED_DOMAIN=icgu.org
STAFF_MFA_REQUIRED=true
```

The callback URL registered in Google Cloud must exactly match `GOOGLE_REDIRECT_URI`. Google sign-in maps only to an existing active ICGU staff account; it must not auto-create Secretariat users.

For staff who should authenticate only through ICGU Google Workspace, provision them from Laravel Cloud without creating or transmitting a usable password:

```bash
php artisan icgu:staff-user <staff@icgu.org> <role> \
  --name="<Full Name>" \
  --google-only
```

`--google-only` is restricted to the configured Workspace domain, assigns an inaccessible random local password, keeps the account active for Google authentication, and records the provisioning action in the audit log. Provision at least one active `ceo`, `membership-officer`, and `finance-officer` account before pilot sign-off.

## 3. Prepare the pilot member file

Use `docs/pilot-members-template.csv`.

The header is versioned and intentionally strict. Do not add columns, reorder columns, or place financial transaction history in this file.

Required rules:

- registration number: `ICGU/NNN/YYYY`;
- `type`: `individual` or `corporate`;
- plan code must exist and match the member type;
- status must be a valid membership status;
- individuals require first and last names;
- corporate rows require `company_name`;
- every row requires a valid primary email and registration date;
- ACTIVE members require `period_start`, `period_end`, and `target_year`;
- a registration number or member email already present in the database is a conflict, never an update.

Financial history is not bulk-imported. Finance should reconcile opening balances separately under an approved accounting migration procedure.

## 4. Keep PII out of Git

Upload the approved CSV to the private `s3` disk. Never commit a real pilot CSV to the repository.

Dry run:

```bash
php artisan icgu:pilot-import pilot/icgu-pilot-001.csv --disk=s3
```

A dry run writes only import audit batch/row diagnostics. It does **not** create members.

If the command reports any conflict or error, correct the source and run another dry run.

## 5. Commit the approved batch

After ICGU approves the validated source:

```bash
php artisan icgu:pilot-import pilot/icgu-pilot-001.csv \
  --disk=s3 \
  --commit \
  --approved-by=<active-secretariat-email>
```

Commit mode is all-or-nothing for the pilot batch.

The importer:

- writes members only after every row passes;
- creates the primary member email;
- creates the supplied membership period;
- records initial membership status history;
- advances annual registration-number counters to avoid post-cutover collisions;
- records the source SHA-256;
- blocks a second commit of the exact same source;
- records the approving Secretariat account.

After successful import, retain the source according to ICGU's approved records policy or securely delete it from the staging location.

## 6. Pilot readiness gates

Run:

```bash
php artisan icgu:production-check --strict
php artisan icgu:pilot-check --strict
```

`production-check --strict` validates the production environment, including HTTPS, APP_KEY, Google Workspace OAuth, PostgreSQL/TLS/private schema, secure sessions, MFA policy, durable queue/cache, live mail and private object storage.

`pilot-check --strict` blocks launch when it detects critical data or operational conditions including:

- `.invalid` prototype accounts;
- no committed pilot import;
- active members without plans, primary emails, or current periods;
- CEO, Membership Officer, or Finance Officer not MFA-ready;
- unresolved MoMo manual-reconciliation items;
- failed queue jobs.

Historical dry-run/failed batch records remain visible for audit and should be reviewed, but corrected historical attempts do not by themselves block launch.

## 7. Laravel Cloud deployment

Use production build/deploy separation.

Build:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan optimize
```

Deploy:

```bash
php artisan migrate --force
php artisan icgu:production-check --strict
```

Enable the Laravel Cloud scheduler and a queue worker/worker cluster. Keep `onOneServer()` for singleton scheduled tasks.

## 8. UAT sequence

Use a controlled cohort and execute in this order:

1. Confirm the `main` branch release verification is green.
2. Confirm `/up` returns healthy on the production domain.
3. Test Google Workspace Secretariat sign-in for CEO, Membership and Finance roles.
4. Dry-run and commit the approved pilot member file from private object storage.
5. Run `icgu:pilot-check --strict`.
6. Verify member search, profile, status, plan and current membership period.
7. Create one controlled application and approve it through the normal workflow.
8. Generate invoice, record one approved manual payment, verify receipt.
9. Verify member portal invitation/login and digital credential verification.
10. Exercise renewal generation and reminder dry-run.
11. Complete MTN sandbox/UAT before enabling production credentials.
12. Confirm queue processing, scheduler execution and error monitoring.
13. Export an independent database backup and verify the object-storage backup process.
14. Record ICGU pilot sign-off before expanding the cohort.

## 9. Rollback

Application rollback and data rollback are separate decisions.

- Application defect: deploy the previous known-good Laravel Cloud commit.
- Import defect before broader activity: investigate the specific `pilot_import_batch` and affected member IDs; do not blindly delete financial/audit history.
- Financial correction: use append-only reversal/refund/waiver mechanisms.
- Database disaster recovery: use the approved Supabase backup/PITR procedure only after assessing potential data loss.

## Platform references

- Laravel 12 deployment: https://laravel.com/docs/12.x/deployment
- Laravel Cloud deployments: https://cloud.laravel.com/docs/deployments
- Laravel Cloud environments: https://cloud.laravel.com/docs/environments
- Laravel Cloud Object Storage: https://cloud.laravel.com/docs/resources/object-storage
- Laravel Cloud scheduled tasks: https://cloud.laravel.com/docs/scheduled-tasks
- Supabase production checklist: https://supabase.com/docs/guides/deployment/going-into-prod
- Supabase backups: https://supabase.com/docs/guides/platform/backups
- Supabase maturity model: https://supabase.com/docs/guides/deployment/maturity-model
