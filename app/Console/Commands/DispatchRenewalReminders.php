<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CommunicationLog;
use App\Models\FinancialLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class DispatchRenewalReminders extends Command
{
    protected $signature = 'icgu:dispatch-reminders
        {--dry-run : Simulate dispatch without sending or writing logs}
        {--limit=50 : Maximum number of renewal invoices to inspect per run}';

    protected $description = 'Dispatch staged annual-renewal reminders for outstanding renewal invoices.';

    private int $dispatched = 0;
    private int $skipped = 0;
    private int $failed = 0;

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $limit = max(1, min((int) $this->option('limit'), 500));

        $invoices = FinancialLedger::query()
            ->with(['member.primaryEmail', 'member.status', 'member.communicationLogs', 'settlements', 'renewal'])
            ->where('type', 'invoice')
            ->whereNotNull('membership_renewal_id')
            ->whereNotNull('due_date')
            ->where('due_date', '<=', now()->addDays(30))
            ->orderBy('due_date')
            ->limit($limit)
            ->get()
            ->filter(fn (FinancialLedger $invoice): bool => (float) $invoice->balance_due > 0.0001)
            ->values();

        if ($invoices->isEmpty()) {
            $this->info('No outstanding renewal reminders are due.');
            return self::SUCCESS;
        }

        $this->info('ICGU Renewal Reminder Dispatcher');
        $this->line('Mode: '.($isDryRun ? 'DRY RUN' : 'LIVE'));
        $this->line("Outstanding renewal invoices inspected: {$invoices->count()}");
        $this->newLine();

        foreach ($invoices as $invoice) {
            $this->processInvoice($invoice, $isDryRun);
        }

        $this->newLine();
        $this->table(
            ['Status', 'Count'],
            [
                ['Dispatched', $this->dispatched],
                ['Skipped', $this->skipped],
                ['Failed', $this->failed],
            ],
        );

        return self::SUCCESS;
    }

    private function processInvoice(FinancialLedger $invoice, bool $isDryRun): void
    {
        $member = $invoice->member;
        if ($member === null || $member->primaryEmail === null || $invoice->renewal === null) {
            $this->warn("SKIP invoice {$invoice->invoice_number}: member, email or renewal record missing.");
            $this->skipped++;
            return;
        }

        $campaignRef = "renewal_{$invoice->membership_renewal_id}";
        $existingSequences = $member->communicationLogs
            ->where('campaign_ref', $campaignRef)
            ->where('status', 'sent')
            ->pluck('sequence')
            ->all();

        $nextSequence = $this->resolveNextSequence($invoice, $existingSequences);
        if ($nextSequence === null) {
            $this->skipped++;
            return;
        }

        $recipientEmail = $member->primaryEmail->email;
        $memberName = $member->display_name;
        $balanceDue = number_format((float) $invoice->balance_due, 0, '.', ',');
        $subject = $this->buildSubject($nextSequence, $member->registration_number);

        $this->line("→ {$nextSequence}: {$memberName} | {$recipientEmail} | UGX {$balanceDue}");

        if ($isDryRun) {
            $this->skipped++;
            return;
        }

        try {
            Mail::raw(
                $this->buildEmailBody(
                    $memberName,
                    $nextSequence,
                    $balanceDue,
                    $invoice->due_date?->toDateString(),
                    $invoice->renewal->target_year,
                    $member->status?->code,
                ),
                fn ($message) => $message->to($recipientEmail)->subject($subject),
            );

            CommunicationLog::query()->create([
                'member_id' => $member->id,
                'campaign_ref' => $campaignRef,
                'sequence' => $nextSequence,
                'channel' => 'email',
                'subject' => $subject,
                'status' => 'sent',
                'recipient_email' => $recipientEmail,
                'sent_at' => now(),
                'tracking_token' => (string) Str::uuid(),
                'meta' => [
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'membership_renewal_id' => $invoice->membership_renewal_id,
                    'balance_due' => $invoice->balance_due,
                    'due_date' => $invoice->due_date?->toDateString(),
                    'driver' => config('mail.default'),
                ],
            ]);

            $this->dispatched++;
        } catch (\Throwable $e) {
            CommunicationLog::query()->create([
                'member_id' => $member->id,
                'campaign_ref' => $campaignRef,
                'sequence' => $nextSequence,
                'channel' => 'email',
                'subject' => $subject,
                'status' => 'failed',
                'recipient_email' => $recipientEmail,
                'sent_at' => now(),
                'tracking_token' => (string) Str::uuid(),
                'meta' => [
                    'invoice_id' => $invoice->id,
                    'membership_renewal_id' => $invoice->membership_renewal_id,
                    'error_message' => $e->getMessage(),
                    'error_class' => $e::class,
                ],
            ]);

            $this->error("Failed for {$recipientEmail}: {$e->getMessage()}");
            $this->failed++;
        }
    }

    private function resolveNextSequence(FinancialLedger $invoice, array $existingSequences): ?string
    {
        $dueDate = $invoice->due_date?->startOfDay();
        if ($dueDate === null) {
            return null;
        }

        if ($dueDate->isFuture()) {
            $daysUntilDue = (int) today()->diffInDays($dueDate, false);
            return $daysUntilDue <= 30 && ! in_array('upcoming', $existingSequences, true) ? 'upcoming' : null;
        }

        $daysPastDue = (int) $dueDate->diffInDays(today());
        $hasFirst = in_array('first', $existingSequences, true);
        $hasSecond = in_array('second', $existingSequences, true);
        $hasFinal = in_array('final', $existingSequences, true);

        if (! $hasFirst) {
            return 'first';
        }
        if ($daysPastDue >= 15 && ! $hasSecond) {
            return 'second';
        }
        if ($daysPastDue >= 31 && $hasSecond && ! $hasFinal) {
            return 'final';
        }

        return null;
    }

    private function buildSubject(string $sequence, string $registrationNumber): string
    {
        return match ($sequence) {
            'upcoming' => "[ICGU] Membership Renewal Approaching | {$registrationNumber}",
            'first' => "[ICGU] Membership Renewal Due | {$registrationNumber}",
            'second' => "[ICGU] Second Renewal Notice | {$registrationNumber}",
            'final' => "[ICGU] Final Renewal Notice | {$registrationNumber}",
            default => "[ICGU] Membership Renewal | {$registrationNumber}",
        };
    }

    private function buildEmailBody(
        string $memberName,
        string $sequence,
        string $balanceDue,
        ?string $dueDate,
        int $targetYear,
        ?string $membershipStatus,
    ): string {
        $opening = match ($sequence) {
            'upcoming' => 'Your annual ICGU membership renewal is approaching.',
            'first' => 'Your annual ICGU membership renewal is now due.',
            'second' => 'This is a second notice that your annual ICGU membership renewal remains outstanding.',
            'final' => 'This is a final reminder that your annual ICGU membership renewal remains outstanding.',
            default => 'This is a reminder about your annual ICGU membership renewal.',
        };

        $statusLine = $membershipStatus === 'EXPIRED'
            ? 'Your membership is currently expired and can be reactivated when the applicable renewal period is fully paid.'
            : 'Please settle the renewal by the due date to maintain uninterrupted membership standing.';

        return <<<TEXT
Dear {$memberName},

{$opening}

Renewal year: {$targetYear}
Outstanding amount: UGX {$balanceDue}
Due date: {$dueDate}

{$statusLine}

For payment details or assistance, contact the Institute of Corporate Governance Uganda:
Email: icgu@icgu.org
Phone: +256 414 250239/7

Thank you for your continued membership.

ICGU Membership Team
Institute of Corporate Governance Uganda
TEXT;
    }
}
