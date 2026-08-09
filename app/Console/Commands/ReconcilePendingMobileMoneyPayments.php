<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\MobileMoneyPaymentService;
use Illuminate\Console\Command;

final class ReconcilePendingMobileMoneyPayments extends Command
{
    protected $signature = 'icgu:reconcile-mobile-money {--limit=100 : Maximum pending requests to inspect}';
    protected $description = 'Poll MTN MoMo for pending membership payment requests and settle only verified successful transactions.';

    public function __construct(private readonly MobileMoneyPaymentService $payments)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! (bool) config('services.mtn_momo.enabled', false)) {
            $this->line('MTN MoMo is disabled; nothing to reconcile.');
            return self::SUCCESS;
        }

        $result = $this->payments->reconcilePending((int) $this->option('limit'));

        $this->table(['Metric', 'Count'], [
            ['Processed', $result['processed']],
            ['Successful', $result['successful']],
            ['Failed', $result['failed']],
            ['Still pending', $result['pending']],
        ]);

        return self::SUCCESS;
    }
}
