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
        Schema::create('payment_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 30);
            $table->uuid('external_reference')->unique();
            $table->foreignId('invoice_id')->constrained('financial_ledger')->restrictOnDelete();
            $table->foreignId('membership_application_id')->nullable()->constrained('membership_applications')->nullOnDelete();
            $table->foreignId('membership_renewal_id')->nullable()->constrained('membership_renewals')->nullOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->decimal('amount', 15, 4);
            $table->string('currency', 3)->default('UGX');
            $table->string('payer_msisdn', 32);
            $table->string('status', 30)->default('created');
            $table->string('provider_status', 80)->nullable();
            $table->string('provider_transaction_id', 120)->nullable()->index();
            $table->string('failure_reason', 500)->nullable();
            $table->jsonb('provider_payload')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('callback_received_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['provider', 'status', 'created_at']);
            $table->index(['invoice_id', 'status']);
            $table->index(['member_id', 'status']);
        });

        DB::statement("ALTER TABLE payment_requests ADD CONSTRAINT chk_payment_requests_provider CHECK (provider IN ('mtn_momo','airtel_money'))");
        DB::statement("ALTER TABLE payment_requests ADD CONSTRAINT chk_payment_requests_status CHECK (status IN ('created','pending','successful','failed','expired','cancelled'))");
        DB::statement('ALTER TABLE payment_requests ADD CONSTRAINT chk_payment_requests_amount CHECK (amount > 0)');

        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_request_id')->nullable()->constrained('payment_requests')->nullOnDelete();
            $table->string('provider', 30);
            $table->uuid('external_reference')->nullable()->index();
            $table->string('source_ip', 45)->nullable();
            $table->string('http_method', 10);
            $table->char('headers_sha256', 64);
            $table->jsonb('payload');
            $table->string('processing_status', 30)->default('received');
            $table->string('processing_error', 1000)->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'processing_status', 'received_at']);
        });

        DB::statement("ALTER TABLE payment_webhook_events ADD CONSTRAINT chk_payment_webhook_provider CHECK (provider IN ('mtn_momo','airtel_money'))");
        DB::statement("ALTER TABLE payment_webhook_events ADD CONSTRAINT chk_payment_webhook_status CHECK (processing_status IN ('received','verified','ignored','failed'))");
        DB::statement('CREATE INDEX idx_payment_requests_payload_gin ON payment_requests USING GIN (provider_payload)');
        DB::statement('CREATE INDEX idx_payment_webhooks_payload_gin ON payment_webhook_events USING GIN (payload)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_payment_webhooks_payload_gin');
        DB::statement('DROP INDEX IF EXISTS idx_payment_requests_payload_gin');
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payment_requests');
    }
};
