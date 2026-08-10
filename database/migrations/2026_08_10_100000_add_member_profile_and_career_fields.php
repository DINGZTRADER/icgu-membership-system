<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->string('membership_tier', 80)->nullable()->after('membership_plan_id');
            $table->boolean('is_job_seeker')->default(false)->after('job_title');
            $table->string('profile_photo_path', 500)->nullable()->after('is_job_seeker');
            $table->string('cv_path', 500)->nullable()->after('profile_photo_path');

            $table->index('membership_tier', 'idx_members_membership_tier');
            $table->index('is_job_seeker', 'idx_members_job_seeker');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropIndex('idx_members_membership_tier');
            $table->dropIndex('idx_members_job_seeker');
            $table->dropColumn(['membership_tier', 'is_job_seeker', 'profile_photo_path', 'cv_path']);
        });
    }
};
