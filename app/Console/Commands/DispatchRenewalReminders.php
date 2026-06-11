<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FinancialLedger;
use App\Models\CommunicationLog;
use App\Models\LookupStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * DispatchRenewalReminders
 *
 * Lightweight synchronous reminder command for the ICGU prototype phase.
 * Avoids heavy queue workers. Runs via `php artisan icgu:dispatch-reminders`
 * or on a schedule (kernel schedule or Laravel Cloud cron).
 *
 * Sequence logic:
 *   First Notice  → overdue by 0–14 days  AND no prior notice exists
 *   Second Notice → overdue by 15–30 days AND only 'first' notice exists
 *   Final Notice  → overdue by 31+ days   AND 'second' notice exists but no 'final'
 *
 * Uses SYNC mail driver in prototype mode — no queue workers required.
 * Logs every dispatch attempt in communication_logs for full traceability.
 */
class DispatchRenewalReminders extends Command
{
    protected $signature   = 'icgu:dispatch-reminders
                                {--dry-run : Simulate dispatch without sending or writing logs}
                                {--limit=50 : Maximum number of reminders to process per run}';

    protected $description = 'Dispatch sequential renewal reminder emails to members with outstanding invoices.';

    private int $dispatched = 0;
    private int $skipped    = 0;
    private int $failed     = 0;

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $limit    = (int)  $this->option('limit');

        $this->info('ICGU Renewal Reminder Dispatcher');
        $this->info('Mode: ' . ($isDryRun ? 'DRY RUN (no emails sent)' : 'LIVE'));
        $this->newLine();

        // Fetch overdue invoices with member and email data (single optimized query)
        $overdueInvoices = FinancialLedger::query()
            ->with([
                'member.primaryEmail',
                'member.communicationLogs' => fn ($q) => $q->whereIn('sequence', ['first', 'second', 'final'])
                                                            ->orderByDesc('sent_at'),
            ])
            ->where('type', 'invoice')
            ->whereNull('settled_at')
            ->whereColumn('amount_settled', '<', 'amount')
            ->where('due_date', '<', now())
            ->orderBy('due_date')
            ->limit($limit)
            ->get();

        if ($overdueInvoices->isEmpty()) {
            $this->info('No overdue invoices found. Nothing to dispatch.');
            return self::SUCCESS;
        }

        $this->line("Found {$overdueInvoices->count()} overdue invoice(s).");
        $this->newLine();

        foreach ($overdueInvoices as $invoice) {
            $this->processInvoice($invoice, $isDryRun);
        }

        $this->newLine();
        $this->table(
            ['Status', 'Count'],
            [
                ['✓ Dispatched', $this->dispatched],
                ['⚠ Skipped (already sent)', $this->skipped],
                ['✗ Failed', $this->failed],
            ]
        );

        return self::SUCCESS;
    }

    private function processInvoice(FinancialLedger $invoice, bool $isDryRun): void
    {
        $member = $invoice->member;

        if ($member === null || $member->primaryEmail === null) {
            $this->warn("  SKIP: Invoice #{$invoice->id} — member or email not found.");
            $this->skipped++;
            return;
        }

        $recipientEmail = $member->primaryEmail->email;
        $memberName     = $member->display_name;
        $daysPastDue    = (int) now()->diffInDays($invoice->due_date);

        // Determine which sequence this member should receive next
        $existingSequences = $member->communicationLogs
            ->where('campaign_ref', "renewal_{$invoice->id}")
            ->pluck('sequence')
            ->toArray();

        $nextSequence = $this->resolveNextSequence($daysPastDue, $existingSequences);

        if ($nextSequence === null) {
            $this->line("  SKIP: {$memberName} ({$recipientEmail}) — all notices already sent or not yet due.");
            $this->skipped++;
            return;
        }

        $subject       = $this->buildSubject($nextSequence, $member->registration_number);
        $trackingToken = Str::uuid()->toString();
        $campaignRef   = "renewal_{$invoice->id}";
        $balanceDue    = number_format((float) $invoice->balance_due, 2, '.', ',');

        $this->line("  → [{$nextSequence}] {$memberName} | {$recipientEmail} | Balance: UGX {$balanceDue}");

        if ($isDryRun) {
            $this->skipped++;
            return;
        }

        try {
            // Send synchronous email (SYNC driver = no queue worker needed)
            Mail::raw(
                $this->buildEmailBody($memberName, $nextSequence, $balanceDue, $invoice->due_date?->toDateString()),
                function ($message) use ($recipientEmail, $subject) {
                    $message->to($recipientEmail)->subject($subject);
                }
            );

            // Log successful dispatch
            CommunicationLog::create([
                'member_id'       => $member->id,
                'campaign_ref'    => $campaignRef,
                'sequence'        => $nextSequence,
                'channel'         => 'email',
                'subject'         => $subject,
                'status'          => 'sent',
                'recipient_email' => $recipientEmail,
                'sent_at'         => now(),
                'tracking_token'  => $trackingToken,
                'meta'            => [
                    'invoice_id'     => $invoice->id,
                    'balance_due'    => $invoice->balance_due,
                    'days_past_due'  => $daysPastDue,
                    'driver'         => config('mail.default'),
                ],
            ]);

            $this->info("    ✓ Sent ({$nextSequence} notice)");
            $this->dispatched++;

        } catch (\Throwable $e) {
            // Log failed attempt — never suppress, always record
            CommunicationLog::create([
                'member_id'       => $member->id,
                'campaign_ref'    => $campaignRef,
                'sequence'        => $nextSequence,
                'channel'         => 'email',
                'subject'         => $subject,
                'status'          => 'failed',
                'recipient_email' => $recipientEmail,
                'sent_at'         => now(),
                'tracking_token'  => $trackingToken,
                'meta'            => [
                    'invoice_id'    => $invoice->id,
                    'error_message' => $e->getMessage(),
                    'error_class'   => get_class($e),
                ],
            ]);

            $this->error("    ✗ Failed: " . $e->getMessage());
            $this->failed++;
        }
    }

    /**
     * Resolve the next notice sequence based on days overdue and existing notices.
     */
    private function resolveNextSequence(int $daysPastDue, array $existingSequences): ?string
    {
        $hasFinal  = in_array('final',  $existingSequences, true);
        $hasSecond = in_array('second', $existingSequences, true);
        $hasFirst  = in_array('first',  $existingSequences, true);

        if ($hasFinal) {
            return null; // All notices sent; escalate manually
        }

        if ($daysPastDue >= 31 && $hasSecond) {
            return 'final';
        }

        if ($daysPastDue >= 15 && $hasFirst && !$hasSecond) {
            return 'second';
        }

        if ($daysPastDue >= 0 && !$hasFirst) {
            return 'first';
        }

        return null;
    }

    private function buildSubject(string $sequence, string $registrationNumber): string
    {
        return match ($sequence) {
            'first'  => "[ICGU] Renewal Due — Annual Membership Fee | {$registrationNumber}",
            'second' => "[ICGU] Second Notice — Outstanding Membership Dues | {$registrationNumber}",
            'final'  => "[ICGU] FINAL NOTICE — Immediate Payment Required | {$registrationNumber}",
            default  => "[ICGU] Membership Renewal Reminder | {$registrationNumber}",
        };
    }

    private function buildEmailBody(
        string  $memberName,
        string  $sequence,
        string  $balanceDue,
        ?string $dueDate,
    ): string {
        $notice = match ($sequence) {
            'first'  => 'This is a courtesy reminder',
            'second' => 'This is your SECOND NOTICE',
            'final'  => 'This is your FINAL NOTICE — failure to pay may result in membership suspension',
            default  => 'This is a reminder',
        };

        return <<<TEXT
Dear {$memberName},

{$notice} that your ICGU annual membership fee of UGX {$balanceDue} was due on {$dueDate}.

Please arrange immediate payment to maintain your membership standing.

For payment details or queries, contact the ICGU Secretariat:
Email: secretary@icgu.org
Phone: +256 XXX XXX XXX

Thank you for your continued membership.

Warm regards,
The ICGU Membership Team
Institute of Corporate Governance Uganda

---
This is an automated notification. Please do not reply directly to this email.
TEXT;
    }
}
