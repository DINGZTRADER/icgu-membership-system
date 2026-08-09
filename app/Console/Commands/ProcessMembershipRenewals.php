<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MembershipRenewalService;
use Illuminate\Console\Command;

final class ProcessMembershipRenewals extends Command
{
    protected $signature = 'icgu:process-renewals
        {--days-ahead=30 : Generate renewal invoices this many days before membership expiry}';

    protected $description = 'Generate due membership renewals and synchronize ACTIVE/EXPIRED membership status.';

    public function __construct(private readonly MembershipRenewalService $renewals)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $daysAhead = max(0, min((int) $this->option('days-ahead'), 365));
        $generation = $this->renewals->generateDueRenewals($daysAhead);
        $statuses = $this->renewals->synchronizeMembershipStatuses();

        $this->info('ICGU membership renewal lifecycle processed successfully.');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Renewal invoices generated', $generation['generated']],
                ['Existing renewal cycles retained', $generation['existing']],
                ['Memberships activated/reactivated', $statuses['activated']],
                ['Memberships expired', $statuses['expired']],
                ['Membership statuses unchanged', $statuses['unchanged']],
            ],
        );

        return self::SUCCESS;
    }
}
