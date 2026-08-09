<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Keep the append-only audit trigger function immune to caller-controlled
        // search_path changes. Supabase's database linter flags mutable function
        // search paths as a security risk.
        DB::statement('ALTER FUNCTION prevent_audit_log_mutation() SET search_path = pg_catalog');

        // Cover foreign-key columns used by deletes, joins and operational lookups.
        // IF NOT EXISTS keeps this migration safe for the production database,
        // where these indexes were applied during the initial controlled bootstrap.
        $indexes = [
            'CREATE INDEX IF NOT EXISTS idx_app_docs_representative ON application_documents(application_representative_id)',
            'CREATE INDEX IF NOT EXISTS idx_app_docs_uploaded_by ON application_documents(uploaded_by)',
            'CREATE INDEX IF NOT EXISTS idx_app_docs_verified_by ON application_documents(verified_by)',
            'CREATE INDEX IF NOT EXISTS idx_comm_logs_sent_by ON communication_logs(sent_by)',
            'CREATE INDEX IF NOT EXISTS idx_ledger_created_by ON financial_ledger(created_by)',
            'CREATE INDEX IF NOT EXISTS idx_ledger_status_id ON financial_ledger(status_id)',
            'CREATE INDEX IF NOT EXISTS idx_member_credentials_issued_by ON member_credentials(issued_by)',
            'CREATE INDEX IF NOT EXISTS idx_portal_accounts_linked_by ON member_portal_accounts(linked_by)',
            'CREATE INDEX IF NOT EXISTS idx_portal_invitations_created_by ON member_portal_invitations(created_by)',
            'CREATE INDEX IF NOT EXISTS idx_status_history_actor ON member_status_history(actor_id)',
            'CREATE INDEX IF NOT EXISTS idx_status_history_from_status ON member_status_history(from_status_id)',
            'CREATE INDEX IF NOT EXISTS idx_members_plan ON members(membership_plan_id)',
            'CREATE INDEX IF NOT EXISTS idx_members_organisation ON members(organisation_id)',
            'CREATE INDEX IF NOT EXISTS idx_applications_applicant_user ON membership_applications(applicant_user_id)',
            'CREATE INDEX IF NOT EXISTS idx_applications_decision_by ON membership_applications(decision_by)',
            'CREATE INDEX IF NOT EXISTS idx_applications_organisation ON membership_applications(organisation_id)',
            'CREATE INDEX IF NOT EXISTS idx_periods_created_by ON membership_periods(created_by)',
            'CREATE INDEX IF NOT EXISTS idx_renewals_created_by ON membership_renewals(created_by)',
            'CREATE INDEX IF NOT EXISTS idx_organisations_verified_by ON organisations(verified_by)',
            'CREATE INDEX IF NOT EXISTS idx_payment_requests_created_by ON payment_requests(created_by)',
            'CREATE INDEX IF NOT EXISTS idx_payment_requests_application ON payment_requests(membership_application_id)',
            'CREATE INDEX IF NOT EXISTS idx_payment_requests_renewal ON payment_requests(membership_renewal_id)',
            'CREATE INDEX IF NOT EXISTS idx_payment_webhooks_request ON payment_webhook_events(payment_request_id)',
            'CREATE INDEX IF NOT EXISTS idx_permission_role_role ON permission_role(role_id)',
            'CREATE INDEX IF NOT EXISTS idx_pilot_batches_approved_by ON pilot_import_batches(approved_by)',
            'CREATE INDEX IF NOT EXISTS idx_pilot_rows_member ON pilot_import_rows(member_id)',
            'CREATE INDEX IF NOT EXISTS idx_receipts_issued_by ON receipts(issued_by)',
            'CREATE INDEX IF NOT EXISTS idx_role_user_user ON role_user(user_id)',
        ];

        foreach ($indexes as $statement) {
            DB::statement($statement);
        }
    }

    public function down(): void
    {
        $indexes = [
            'idx_role_user_user',
            'idx_receipts_issued_by',
            'idx_pilot_rows_member',
            'idx_pilot_batches_approved_by',
            'idx_permission_role_role',
            'idx_payment_webhooks_request',
            'idx_payment_requests_renewal',
            'idx_payment_requests_application',
            'idx_payment_requests_created_by',
            'idx_organisations_verified_by',
            'idx_renewals_created_by',
            'idx_periods_created_by',
            'idx_applications_organisation',
            'idx_applications_decision_by',
            'idx_applications_applicant_user',
            'idx_members_organisation',
            'idx_members_plan',
            'idx_status_history_from_status',
            'idx_status_history_actor',
            'idx_portal_invitations_created_by',
            'idx_portal_accounts_linked_by',
            'idx_member_credentials_issued_by',
            'idx_ledger_status_id',
            'idx_ledger_created_by',
            'idx_comm_logs_sent_by',
            'idx_app_docs_verified_by',
            'idx_app_docs_uploaded_by',
            'idx_app_docs_representative',
        ];

        foreach ($indexes as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }

        DB::statement('ALTER FUNCTION prevent_audit_log_mutation() RESET search_path');
    }
};
