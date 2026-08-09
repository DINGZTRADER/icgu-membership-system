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
        Schema::table('financial_ledger', function (Blueprint $table): void {
            $table->foreignId('membership_application_id')->nullable()->after('member_id')->constrained('membership_applications')->nullOnDelete();
            $table->string('invoice_number', 40)->nullable()->unique()->after('type');
            $table->string('payment_method', 30)->nullable()->after('tx_reference');
            $table->string('payment_provider', 80)->nullable()->after('payment_method');
            $table->timestamp('received_at')->nullable()->after('settled_at');
            $table->jsonb('meta')->nullable()->after('notes');
            $table->index(['membership_application_id', 'type'], 'idx_ledger_application_type');
        });

        DB::statement('ALTER TABLE financial_ledger ALTER COLUMN member_id DROP NOT NULL');
        DB::statement("ALTER TABLE financial_ledger ADD CONSTRAINT chk_ledger_owner CHECK (member_id IS NOT NULL OR membership_application_id IS NOT NULL)");
        DB::statement("ALTER TABLE financial_ledger ADD CONSTRAINT chk_ledger_payment_method CHECK (payment_method IS NULL OR payment_method IN ('bank_transfer','mobile_money','cash','card','cheque','other'))");
        DB::statement("ALTER TABLE financial_ledger ADD CONSTRAINT chk_ledger_invoice_number CHECK ((type = 'invoice' AND invoice_number IS NOT NULL) OR (type <> 'invoice' AND invoice_number IS NULL))");
        DB::statement("CREATE UNIQUE INDEX uq_ledger_application_invoice ON financial_ledger (membership_application_id) WHERE type = 'invoice' AND membership_application_id IS NOT NULL");

        Schema::create('financial_document_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('document_type', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamps();
            $table->unique(['document_type', 'year']);
        });

        Schema::create('receipts', function (Blueprint $table): void {
            $table->id();
            $table->string('receipt_number', 40)->unique();
            $table->foreignId('payment_ledger_id')->unique()->constrained('financial_ledger')->restrictOnDelete();
            $table->foreignId('membership_application_id')->nullable()->constrained('membership_applications')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->decimal('amount', 15, 4);
            $table->string('currency', 3)->default('UGX');
            $table->string('payment_reference', 100)->nullable();
            $table->timestamp('issued_at');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('meta')->nullable();
            $table->timestamps();
            $table->index(['membership_application_id', 'issued_at']);
            $table->index(['member_id', 'issued_at']);
        });

        DB::statement('ALTER TABLE receipts ADD CONSTRAINT chk_receipts_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('financial_document_sequences');

        DB::statement('DROP INDEX IF EXISTS uq_ledger_application_invoice');
        DB::statement('ALTER TABLE financial_ledger DROP CONSTRAINT IF EXISTS chk_ledger_invoice_number');
        DB::statement('ALTER TABLE financial_ledger DROP CONSTRAINT IF EXISTS chk_ledger_payment_method');
        DB::statement('ALTER TABLE financial_ledger DROP CONSTRAINT IF EXISTS chk_ledger_owner');

        Schema::table('financial_ledger', function (Blueprint $table): void {
            $table->dropIndex('idx_ledger_application_type');
            $table->dropColumn(['invoice_number', 'payment_method', 'payment_provider', 'received_at', 'meta']);
            $table->dropConstrainedForeignId('membership_application_id');
        });

        DB::statement('ALTER TABLE financial_ledger ALTER COLUMN member_id SET NOT NULL');
    }
};
