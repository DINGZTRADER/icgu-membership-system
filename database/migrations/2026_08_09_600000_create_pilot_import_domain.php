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
        Schema::create('pilot_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('source_name', 255);
            $table->char('source_sha256', 64)->index();
            $table->string('status', 20)->default('validated');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('conflict_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->jsonb('summary')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'idx_pilot_import_batches_status_time');
        });

        DB::statement("ALTER TABLE pilot_import_batches ADD CONSTRAINT chk_pilot_import_batches_status CHECK (status IN ('validated','committed','failed'))");
        DB::statement("CREATE UNIQUE INDEX uq_pilot_import_batches_committed_source ON pilot_import_batches (source_sha256) WHERE status = 'committed'");

        Schema::create('pilot_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pilot_import_batch_id')->constrained('pilot_import_batches')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->char('row_sha256', 64);
            $table->string('registration_number', 20)->nullable();
            $table->string('disposition', 20);
            $table->jsonb('normalized_payload')->nullable();
            $table->jsonb('issues')->nullable();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamps();

            $table->unique(['pilot_import_batch_id', 'row_number'], 'uq_pilot_import_rows_batch_row');
            $table->index(['pilot_import_batch_id', 'disposition'], 'idx_pilot_import_rows_batch_disposition');
            $table->index('registration_number', 'idx_pilot_import_rows_registration');
        });

        DB::statement("ALTER TABLE pilot_import_rows ADD CONSTRAINT chk_pilot_import_rows_disposition CHECK (disposition IN ('valid','conflict','error','imported'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('pilot_import_rows');
        DB::statement('DROP INDEX IF EXISTS uq_pilot_import_batches_committed_source');
        Schema::dropIfExists('pilot_import_batches');
    }
};
