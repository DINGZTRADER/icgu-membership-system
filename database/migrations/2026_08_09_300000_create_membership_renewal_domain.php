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
        Schema::create('membership_renewals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->restrictOnDelete();
            $table->foreignId('source_period_id')->unique()->constrained('membership_periods')->restrictOnDelete();
            $table->unsignedSmallInteger('target_year');
            $table->date('planned_start_date');
            $table->date('planned_end_date');
            $table->decimal('renewal_fee', 15, 4);
            $table->string('currency', 3)->default('UGX');
            $table->string('status', 30)->default('invoiced');
            $table->foreignId('invoice_id')->nullable()->unique()->constrained('financial_ledger')->restrictOnDelete();
            $table->foreignId('resulting_period_id')->nullable()->unique()->constrained('membership_periods')->restrictOnDelete();
            $table->timestamp('generated_at')->useCurrent();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['member_id', 'target_year'], 'uq_membership_renewals_member_year');
            $table->index(['status', 'planned_start_date'], 'idx_membership_renewals_status_start');
            $table->index(['planned_end_date', 'status'], 'idx_membership_renewals_end_status');
        });

        DB::statement("ALTER TABLE membership_renewals ADD CONSTRAINT chk_renewal_dates CHECK (planned_end_date > planned_start_date)");
        DB::statement("ALTER TABLE membership_renewals ADD CONSTRAINT chk_renewal_fee_positive CHECK (renewal_fee > 0)");
        DB::statement("ALTER TABLE membership_renewals ADD CONSTRAINT chk_renewal_status CHECK (status IN ('invoiced','partial','settled','renewed'))");

        Schema::table('financial_ledger', function (Blueprint $table): void {
            $table->foreignId('membership_renewal_id')->nullable()->after('membership_application_id')->constrained('membership_renewals')->nullOnDelete();
            $table->index(['membership_renewal_id', 'type'], 'idx_ledger_renewal_type');
        });

        DB::statement("CREATE UNIQUE INDEX uq_ledger_renewal_invoice ON financial_ledger (membership_renewal_id) WHERE type = 'invoice' AND membership_renewal_id IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_ledger_renewal_invoice');

        Schema::table('financial_ledger', function (Blueprint $table): void {
            $table->dropIndex('idx_ledger_renewal_type');
            $table->dropConstrainedForeignId('membership_renewal_id');
        });

        Schema::dropIfExists('membership_renewals');
    }
};
