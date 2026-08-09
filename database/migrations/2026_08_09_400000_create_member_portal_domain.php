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
        Schema::create('member_portal_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('access_role', 30)->default('owner');
            $table->boolean('is_primary')->default(false);
            $table->timestamp('linked_at');
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['member_id', 'user_id']);
            $table->index(['user_id', 'is_primary']);
        });

        DB::statement("ALTER TABLE member_portal_accounts ADD CONSTRAINT chk_portal_accounts_role CHECK (access_role IN ('owner','representative','billing'))");
        DB::statement('CREATE UNIQUE INDEX uq_portal_accounts_primary ON member_portal_accounts (member_id) WHERE is_primary = true');

        Schema::create('member_portal_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('email', 254);
            $table->char('token_hash', 64)->unique();
            $table->string('access_role', 30)->default('owner');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['member_id', 'email']);
            $table->index(['expires_at', 'accepted_at', 'revoked_at']);
        });

        DB::statement("ALTER TABLE member_portal_invitations ADD CONSTRAINT chk_portal_invitations_role CHECK (access_role IN ('owner','representative','billing'))");
        DB::statement('CREATE UNIQUE INDEX uq_portal_open_invitation ON member_portal_invitations (member_id, email) WHERE accepted_at IS NULL AND revoked_at IS NULL');

        Schema::create('member_credentials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->string('credential_type', 20);
            $table->uuid('verification_code')->unique();
            $table->date('valid_from');
            $table->date('valid_until');
            $table->timestamp('issued_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->jsonb('meta')->nullable();
            $table->timestamps();
            $table->index(['member_id', 'credential_type']);
            $table->index(['valid_until', 'revoked_at']);
        });

        DB::statement("ALTER TABLE member_credentials ADD CONSTRAINT chk_member_credentials_type CHECK (credential_type IN ('card','certificate'))");
        DB::statement('ALTER TABLE member_credentials ADD CONSTRAINT chk_member_credentials_dates CHECK (valid_until >= valid_from)');
        DB::statement('CREATE UNIQUE INDEX uq_member_active_credential ON member_credentials (member_id, credential_type) WHERE revoked_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uq_member_active_credential');
        Schema::dropIfExists('member_credentials');
        DB::statement('DROP INDEX IF EXISTS uq_portal_open_invitation');
        Schema::dropIfExists('member_portal_invitations');
        DB::statement('DROP INDEX IF EXISTS uq_portal_accounts_primary');
        Schema::dropIfExists('member_portal_accounts');
    }
};
