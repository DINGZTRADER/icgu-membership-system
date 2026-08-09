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
        Schema::create('membership_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 150);
            $table->string('audience', 20);
            $table->decimal('first_year_fee', 15, 2);
            $table->decimal('renewal_fee', 15, 2);
            $table->string('currency', 3)->default('UGX');
            $table->boolean('requires_legal_entity')->default(false);
            $table->jsonb('requirements')->default('{}');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE membership_plans ADD CONSTRAINT chk_membership_plans_audience CHECK (audience IN ('individual','student','corporate'))");
        DB::statement('ALTER TABLE membership_plans ADD CONSTRAINT chk_membership_plans_fees CHECK (first_year_fee >= 0 AND renewal_fee >= 0)');

        Schema::create('organisations', function (Blueprint $table): void {
            $table->id();
            $table->string('legal_name', 200);
            $table->string('trading_name', 200)->nullable();
            $table->string('registration_number', 120)->nullable()->unique();
            $table->string('entity_type', 30);
            $table->string('tin', 60)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('address_line', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country', 100)->default('Uganda');
            $table->string('industry', 150)->nullable();
            $table->text('profile_summary')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['entity_type', 'legal_name']);
        });

        DB::statement("ALTER TABLE organisations ADD CONSTRAINT chk_organisations_entity_type CHECK (entity_type IN ('company','ngo','sme','academic','government','other'))");

        Schema::create('membership_applications', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->char('access_token_hash', 64)->unique();
            $table->foreignId('membership_plan_id')->constrained('membership_plans')->restrictOnDelete();
            $table->foreignId('applicant_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('organisation_id')->nullable()->constrained('organisations')->nullOnDelete();
            $table->string('status', 30)->default('draft');
            $table->string('title', 20)->nullable();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100)->nullable();
            $table->string('email', 254);
            $table->string('phone', 40)->nullable();
            $table->string('job_title', 150)->nullable();
            $table->string('institution_name', 200)->nullable();
            $table->text('applicant_notes')->nullable();
            $table->timestamp('integrity_declaration_at')->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('review_started_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decision_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('decision_notes')->nullable();
            $table->foreignId('resulting_member_id')->nullable()->unique()->constrained('members')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['status', 'submitted_at']);
            $table->index(['email', 'status']);
            $table->index(['membership_plan_id', 'status']);
        });

        DB::statement("ALTER TABLE membership_applications ADD CONSTRAINT chk_membership_applications_status CHECK (status IN ('draft','submitted','under_review','approved_pending_payment','rejected','withdrawn','admitted'))");

        Schema::create('application_representatives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('membership_application_id')->constrained('membership_applications')->cascadeOnDelete();
            $table->string('title', 20)->nullable();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 254);
            $table->string('phone', 40)->nullable();
            $table->string('position', 150)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->index(['membership_application_id', 'email']);
        });

        DB::statement('CREATE UNIQUE INDEX uq_application_representatives_primary ON application_representatives (membership_application_id) WHERE is_primary = true');

        Schema::create('application_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('membership_application_id')->constrained('membership_applications')->cascadeOnDelete();
            $table->foreignId('application_representative_id')->nullable()->constrained('application_representatives')->nullOnDelete();
            $table->string('document_type', 60);
            $table->string('storage_disk', 50);
            $table->string('object_path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum_sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['storage_disk', 'object_path']);
            $table->index(['membership_application_id', 'document_type']);
            $table->index('checksum_sha256');
        });

        Schema::table('members', function (Blueprint $table): void {
            $table->foreignId('membership_plan_id')->nullable()->constrained('membership_plans')->nullOnDelete();
            $table->foreignId('source_application_id')->nullable()->unique()->constrained('membership_applications')->nullOnDelete();
            $table->foreignId('organisation_id')->nullable()->constrained('organisations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('organisation_id');
            $table->dropConstrainedForeignId('source_application_id');
            $table->dropConstrainedForeignId('membership_plan_id');
        });

        Schema::dropIfExists('application_documents');
        DB::statement('DROP INDEX IF EXISTS uq_application_representatives_primary');
        Schema::dropIfExists('application_representatives');
        Schema::dropIfExists('membership_applications');
        Schema::dropIfExists('organisations');
        Schema::dropIfExists('membership_plans');
    }
};
