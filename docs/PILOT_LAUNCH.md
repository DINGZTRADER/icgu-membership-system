# ICGU Membership Platform — Controlled Pilot Launch

## Objective

Sprint 9 moves the platform from code-ready to **pilot-ready** without placing real member data, credentials or payment secrets in Git.

The pilot is intentionally small. The default import cap is 50 member records per approved source file.

## 1. Production infrastructure gate

Before a real pilot, ICGU must have dedicated production infrastructure:

- Laravel Cloud production environment connected to `main`;
- dedicated ICGU Supabase PostgreSQL project;
- private persistent object storage for membership documents and pilot import files;
- live ICGU SMTP credentials;
- real Secretariat accounts with MFA;
- MTN Uganda production credentials only after sandbox/UAT is approved.

Do not reuse another client's Supabase project.

## 2. Prepare the pilot member file

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

Financial history is not bulk-imported by Sprint 9. Finance should reconcile opening balances separately under an approved accounting migration procedure.

## 3. Keep PII out of Git

Upload the approved CSV to the configured private object-storage disk. Never commit a real pilot CSV to the repository.

Dry run:

```bash
php artisan icgu:pilot-import pilot/icgu-pilot-001.csv --disk=<private-disk>
```

A dry run writes only the import audit batch/row diagnostics. It does **not** create members.

If the command reports any conflict or error, correct the source and run another dry run.

## 4. Commit the approved batch

After ICGU approves the validated source:

```bash
php artisan icgu:pilot-import pilot/icgu-pilot-001.csv \
  --disk=<private-disk> \
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

## 5. Pilot readiness gates

Run:

```bash
php artisan icgu:production-check --strict
php artisan icgu:pilot-check --strict
```

`pilot-check --strict` blocks launch when it detects critical data or operational conditions including:

- `.invalid` prototype accounts;
- no committed pilot import;
- active members without plans, primary emails, or current periods;
- CEO, Membership Officer, or Finance Officer not MFA-ready;
- unresolved MoMo manual-reconciliation items;
- failed queue jobs.

Historical dry-run/failed batch records remain visible for audit and should be reviewed, but corrected historical attempts do not by themselves block launch.

## 6. Laravel Cloud deployment

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

Enable the Laravel Cloud scheduler and queue worker/worker cluster. Keep `onOneServer()` for singleton scheduled tasks.

## 7. UAT sequence

Use a controlled cohort and execute in this order:

1. Secretariat login + MFA for CEO, Membership and Finance roles.
2. Dry-run and commit the approved pilot member file.
3. Run `icgu:pilot-check --strict`.
4. Verify member search, profile, status, plan and current membership period.
5. Create one controlled application and approve it through the normal workflow.
6. Generate invoice, record one approved manual payment, verify receipt.
7. Verify member portal invitation/login and digital credential verification.
8. Exercise renewal generation and reminder dry-run.
9. Complete MTN sandbox/UAT before enabling production credentials.
10. Confirm `/up`, queue processing, scheduler execution and error monitoring.
11. Export an independent database backup and verify the object-storage backup process.
12. Record ICGU pilot sign-off before expanding the cohort.

## 8. Rollback

Application rollback and data rollback are separate decisions.

- Application defect: deploy the previous known-good Laravel Cloud commit.
- Import defect before broader activity: investigate the specific `pilot_import_batch` and affected member IDs; do not blindly delete financial/audit history.
- Financial correction: use append-only reversal/refund/waiver mechanisms.
- Database disaster recovery: use the approved Supabase backup/PITR procedure only after assessing potential data loss.

## Current platform references checked for Sprint 9

- Laravel 12 deployment: https://laravel.com/docs/12.x/deployment
- Laravel Cloud deployments: https://cloud.laravel.com/docs/deployments
- Laravel Cloud environments: https://cloud.laravel.com/docs/environments
- Laravel Cloud scheduled tasks: https://cloud.laravel.com/docs/scheduled-tasks
- Supabase production checklist: https://supabase.com/docs/guides/deployment/going-into-prod
- Supabase backups: https://supabase.com/docs/guides/platform/backups
- Supabase maturity model: https://supabase.com/docs/guides/deployment/maturity-model
