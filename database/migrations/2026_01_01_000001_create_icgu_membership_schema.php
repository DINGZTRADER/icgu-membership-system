<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // =========================================================
        // TABLE 1: lookup_statuses
        // Central reference table for all status codes across domains.
        // =========================================================
        Schema::create('lookup_statuses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 50)->unique();
            $table->string('type', 30)->comment('membership|payment|communication');
            $table->string('label', 100);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['type', 'is_active'], 'idx_lookup_statuses_type_active');
        });

        // =========================================================
        // TABLE 2: members
        // Unified actor model supporting Individual and Corporate members.
        // =========================================================
        Schema::create('members', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('registration_number', 20)->unique()->comment('Format: ICGU/NNN/YYYY');
            $table->string('type', 20)->comment('individual|corporate');
            // Individual-specific PII fields
            $table->string('title', 20)->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('national_id', 50)->nullable()->unique();
            // Corporate-specific fields
            $table->string('company_name', 200)->nullable();
            $table->string('industry_code', 10)->nullable()->comment('ISIC Rev.4 classification code');
            $table->string('registration_cert', 100)->nullable()->comment('Company registration certificate number');
            // Shared fields
            $table->string('phone', 30)->nullable();
            $table->string('organization', 200)->nullable()->comment('Employer for individuals; Parent group for corporates');
            $table->string('job_title', 150)->nullable();
            $table->date('registration_date');
            $table->foreignId('status_id')->constrained('lookup_statuses')->restrictOnDelete();
            $table->boolean('is_archived')->default(false);
            $table->timestamps();
            $table->softDeletes()->comment('Soft delete ONLY for GDPR erasure requests, never for membership state changes');

            $table->index(['type', 'status_id'], 'idx_members_type_status');
            $table->index('registration_date', 'idx_members_registration_date');
            $table->index('is_archived', 'idx_members_archived');
            $table->index(['last_name', 'first_name'], 'idx_members_name_search');
        });

        // Partial index: only non-archived members (PostgreSQL-native)
        DB::statement("
            CREATE INDEX idx_members_active_partial
            ON members (status_id, registration_date)
            WHERE is_archived = false AND deleted_at IS NULL
        ");

        // Check constraint: type must be valid
        DB::statement("
            ALTER TABLE members
            ADD CONSTRAINT chk_members_type
            CHECK (type IN ('individual', 'corporate'))
        ");

        // Check constraint: individuals must have a first/last name; corporates must have company_name
        DB::statement("
            ALTER TABLE members
            ADD CONSTRAINT chk_members_name_completeness
            CHECK (
                (type = 'individual' AND first_name IS NOT NULL AND last_name IS NOT NULL)
                OR
                (type = 'corporate' AND company_name IS NOT NULL)
            )
        ");

        // =========================================================
        // TABLE 3: member_emails
        // Unlimited, normalised multi-email per member.
        // =========================================================
        Schema::create('member_emails', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('email', 254);
            $table->string('email_type', 20)->default('work')->comment('work|personal|billing');
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_token', 100)->nullable();
            $table->timestamp('verification_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['member_id', 'email'], 'uq_member_emails_member_email');
            $table->index(['email'], 'idx_member_emails_email');
            $table->index(['member_id', 'is_primary'], 'idx_member_emails_primary');
        });

        // Partial unique index: enforces only ONE primary email per member
        DB::statement("
            CREATE UNIQUE INDEX uq_member_emails_one_primary
            ON member_emails (member_id)
            WHERE is_primary = true AND is_active = true
        ");

        // =========================================================
        // TABLE 4: membership_periods
        // Decoupled lifecycle engine: tracks annual membership windows.
        // Supports future periods, backdated payments, and multi-year renewals.
        // =========================================================
        Schema::create('membership_periods', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('target_year')->comment('The membership year this period represents, e.g. 2025');
            $table->boolean('is_backdated')->default(false)->comment('True when a late payment retroactively covers a prior period');
            $table->boolean('is_future')->default(false)->comment('True when period was pre-paid before the start date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['member_id', 'target_year'], 'uq_membership_periods_member_year');
            $table->index(['target_year', 'end_date'], 'idx_membership_periods_year_end');
            $table->index(['start_date', 'end_date'], 'idx_membership_periods_date_range');
        });

        DB::statement("
            ALTER TABLE membership_periods
            ADD CONSTRAINT chk_period_dates
            CHECK (end_date > start_date)
        ");

        // =========================================================
        // TABLE 5: financial_ledger
        // Immutable double-entry sub-ledger.
        // Records are NEVER updated or deleted — only offset by new entries.
        // =========================================================
        Schema::create('financial_ledger', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('member_id')->constrained('members')->restrictOnDelete();
            $table->foreignId('period_id')->nullable()->constrained('membership_periods')->nullOnDelete();
            $table->foreignId('status_id')->constrained('lookup_statuses')->restrictOnDelete();
            $table->string('type', 20)->comment('invoice|payment|refund|waiver');
            $table->string('fee_type', 30)->comment('application|annual_individual|annual_corporate|administrative|levy');
            $table->decimal('amount', 15, 4)->comment('Always positive. Direction determined by type.');
            $table->decimal('amount_settled', 15, 4)->default(0.0000)->comment('Running settled amount for invoices.');
            $table->string('tx_reference', 100)->nullable()->unique()->comment('External payment gateway or bank reference');
            $table->string('currency', 3)->default('UGX');
            $table->unsignedBigInteger('parent_invoice_id')->nullable()->comment('For payments/refunds: links back to the originating invoice');
            $table->text('notes')->nullable();
            $table->timestamp('due_date')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // NO softDeletes — financial records are immutable by design

            $table->index(['member_id', 'type', 'status_id'], 'idx_ledger_member_type_status');
            $table->index(['period_id', 'type'], 'idx_ledger_period_type');
            $table->index('due_date', 'idx_ledger_due_date');
            $table->index(['fee_type', 'created_at'], 'idx_ledger_feetype_date');
            $table->index('parent_invoice_id', 'idx_ledger_parent_invoice');
        });

        DB::statement("
            ALTER TABLE financial_ledger
            ADD CONSTRAINT fk_ledger_parent_invoice
            FOREIGN KEY (parent_invoice_id) REFERENCES financial_ledger(id) ON DELETE SET NULL
        ");
        DB::statement("ALTER TABLE financial_ledger ADD CONSTRAINT chk_ledger_amount_positive CHECK (amount >= 0)");
        DB::statement("ALTER TABLE financial_ledger ADD CONSTRAINT chk_ledger_settled_positive CHECK (amount_settled >= 0)");
        DB::statement("ALTER TABLE financial_ledger ADD CONSTRAINT chk_ledger_settled_lte_amount CHECK (amount_settled <= amount)");
        DB::statement("ALTER TABLE financial_ledger ADD CONSTRAINT chk_ledger_type CHECK (type IN ('invoice','payment','refund','waiver'))");
        DB::statement("ALTER TABLE financial_ledger ADD CONSTRAINT chk_ledger_fee_type CHECK (fee_type IN ('application','annual_individual','annual_corporate','administrative','levy'))");

        // Partial index: open invoices only
        DB::statement("
            CREATE INDEX idx_ledger_open_invoices
            ON financial_ledger (member_id, due_date, amount_settled)
            WHERE type = 'invoice' AND settled_at IS NULL
        ");

        // =========================================================
        // TABLE 6: member_status_history
        // Decoupled state transition log. Every status change is recorded.
        // =========================================================
        Schema::create('member_status_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('from_status_id')->nullable()->constrained('lookup_statuses')->nullOnDelete();
            $table->foreignId('to_status_id')->constrained('lookup_statuses')->restrictOnDelete();
            $table->string('reason_code', 50)->nullable();
            $table->text('reason_notes')->nullable();
            $table->timestamp('effective_at')->useCurrent();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['member_id', 'effective_at'], 'idx_status_history_member_time');
            $table->index('to_status_id', 'idx_status_history_to_status');
        });

        // =========================================================
        // TABLE 7: communication_logs
        // Tracks email/SMS drip campaigns: First, Second, Final Notices.
        // =========================================================
        Schema::create('communication_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('campaign_ref', 50)->nullable()->comment('Groups related reminder sequences together');
            $table->string('sequence', 20)->comment('first|second|final|ad_hoc');
            $table->string('channel', 20)->default('email')->comment('email|sms');
            $table->string('subject', 255)->nullable();
            $table->string('status', 30)->comment('queued|sent|delivered|failed|opened|bounced');
            $table->string('recipient_email', 254)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->string('tracking_token', 100)->nullable()->unique();
            $table->json('meta')->nullable()->comment('Provider response payload, message IDs, bounce reason, etc.');
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['member_id', 'sequence', 'status'], 'idx_comm_logs_member_seq_status');
            $table->index('sent_at', 'idx_comm_logs_sent_at');
            $table->index('campaign_ref', 'idx_comm_logs_campaign_ref');
        });

        // GIN index for meta JSON column
        DB::statement("CREATE INDEX idx_comm_logs_meta_gin ON communication_logs USING GIN (meta)");

        // Partial index: failed communications to facilitate retry queries
        DB::statement("
            CREATE INDEX idx_comm_logs_failed_partial
            ON communication_logs (member_id, sent_at)
            WHERE status IN ('failed', 'bounced')
        ");

        // =========================================================
        // TABLE 8: audit_logs
        // Centralized, append-only audit trail. NEVER updated or deleted.
        // Captures full JSONB before/after state for every critical mutation.
        // =========================================================
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50)->comment('created|updated|deleted|login|logout|export|status_changed|payment_recorded');
            $table->string('entity', 150)->comment('App\\Models\\Member, App\\Models\\FinancialLedger, etc.');
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->jsonb('before_payload')->nullable();
            $table->jsonb('after_payload')->nullable();
            $table->string('session_id', 100)->nullable();
            $table->string('request_id', 100)->nullable()->comment('Correlates to HTTP request for distributed tracing');
            $table->timestamps();
            // NO softDeletes — audit logs are sacred and immutable

            $table->index(['entity', 'entity_id'], 'idx_audit_entity');
            $table->index(['user_id', 'created_at'], 'idx_audit_user_time');
            $table->index('action', 'idx_audit_action');
            $table->index('created_at', 'idx_audit_created_at');
        });

        DB::statement("CREATE INDEX idx_audit_before_gin ON audit_logs USING GIN (before_payload)");
        DB::statement("CREATE INDEX idx_audit_after_gin ON audit_logs USING GIN (after_payload)");

        // =========================================================
        // TABLE 9: registration_sequences
        // Concurrency-safe sequence counter per year.
        // Used with SELECT ... FOR UPDATE to prevent race conditions.
        // =========================================================
        Schema::create('registration_sequences', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedSmallInteger('year')->unique();
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamps();
        });

        // =========================================================
        // SEED: Core lookup statuses
        // =========================================================
        $this->seedLookupStatuses();
        $this->seedRegistrationSequences();
    }

    private function seedLookupStatuses(): void
    {
        $now = now();
        DB::table('lookup_statuses')->insert([
            // --- Membership Statuses ---
            ['code' => 'PENDING',         'type' => 'membership',    'label' => 'Pending',          'sort_order' => 1,  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'ACTIVE',          'type' => 'membership',    'label' => 'Active',           'sort_order' => 2,  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'SUSPENDED',       'type' => 'membership',    'label' => 'Suspended',        'sort_order' => 3,  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'EXPIRED',         'type' => 'membership',    'label' => 'Expired',          'sort_order' => 4,  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'RESIGNED',        'type' => 'membership',    'label' => 'Resigned',         'sort_order' => 5,  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'ARCHIVED',        'type' => 'membership',    'label' => 'Archived',         'sort_order' => 6,  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            // --- Payment Statuses ---
            ['code' => 'PAY_PENDING',     'type' => 'payment',       'label' => 'Pending',          'sort_order' => 1,  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'PAY_PAID',        'type' => 'payment',       'label' => 'Paid',             'sort_order' => 2,  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'PAY_PARTIAL',     'type' => 'payment',       'label' => 'Partially Paid',   'sort_order' => 3,  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'PAY_OVERDUE',     'type' => 'payment',       'label' => 'Overdue',          'sort_order' => 4,  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'PAY_WAIVED',      'type' => 'payment',       'label' => 'Waived',           'sort_order' => 5,  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'PAY_REFUNDED',    'type' => 'payment',       'label' => 'Refunded',         'sort_order' => 6,  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'PAY_CANCELLED',   'type' => 'payment',       'label' => 'Cancelled',        'sort_order' => 7,  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            // --- Communication Statuses ---
            ['code' => 'COMM_QUEUED',     'type' => 'communication', 'label' => 'Queued',           'sort_order' => 1,  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'COMM_SENT',       'type' => 'communication', 'label' => 'Sent',             'sort_order' => 2,  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'COMM_DELIVERED',  'type' => 'communication', 'label' => 'Delivered',        'sort_order' => 3,  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'COMM_OPENED',     'type' => 'communication', 'label' => 'Opened',           'sort_order' => 4,  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'COMM_FAILED',     'type' => 'communication', 'label' => 'Failed',           'sort_order' => 5,  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'COMM_BOUNCED',    'type' => 'communication', 'label' => 'Bounced',          'sort_order' => 6,  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    private function seedRegistrationSequences(): void
    {
        DB::table('registration_sequences')->insert([
            'year'          => (int) date('Y'),
            'last_sequence' => 0,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    public function down(): void
    {
        // Drop PostgreSQL-native objects first
        DB::statement('DROP INDEX IF EXISTS idx_audit_after_gin');
        DB::statement('DROP INDEX IF EXISTS idx_audit_before_gin');
        DB::statement('DROP INDEX IF EXISTS idx_comm_logs_meta_gin');
        DB::statement('DROP INDEX IF EXISTS idx_comm_logs_failed_partial');
        DB::statement('DROP INDEX IF EXISTS idx_ledger_open_invoices');
        DB::statement('DROP INDEX IF EXISTS idx_members_active_partial');
        DB::statement('DROP INDEX IF EXISTS uq_member_emails_one_primary');

        // Drop tables in reverse dependency order
        Schema::dropIfExists('registration_sequences');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('communication_logs');
        Schema::dropIfExists('member_status_history');
        Schema::dropIfExists('financial_ledger');
        Schema::dropIfExists('membership_periods');
        Schema::dropIfExists('member_emails');
        Schema::dropIfExists('members');
        Schema::dropIfExists('lookup_statuses');
    }
};
