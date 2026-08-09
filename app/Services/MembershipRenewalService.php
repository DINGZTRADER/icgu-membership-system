<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FinancialLedger;
use App\Models\LookupStatus;
use App\Models\Member;
use App\Models\MembershipPeriod;
use App\Models\MembershipRenewal;
use App\Models\Receipt;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MembershipRenewalService
{
    public function __construct(
        private readonly FinancialDocumentNumberService $numbers,
        private readonly AuditService $audit,
    ) {}

    public function ensureRenewal(Member $member, ?User $actor = null, ?\DateTimeInterface $dueDate = null): MembershipRenewal
    {
        return DB::transaction(function () use ($member, $actor, $dueDate): MembershipRenewal {
            $lockedMember = Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            $lockedMember->load(['status', 'membershipPlan']);
            $this->assertRenewable($lockedMember);

            $source = MembershipPeriod::query()
                ->where('member_id', $lockedMember->id)
                ->orderByDesc('end_date')
                ->lockForUpdate()
                ->first();

            if ($source === null) {
                throw ValidationException::withMessages(['membership' => 'Member has no membership period to renew.']);
            }

            $plannedStart = CarbonImmutable::parse($source->end_date->toDateString(), config('app.timezone'))->addDay()->startOfDay();
            $plannedEnd = $plannedStart->addYear()->subDay();
            $targetYear = (int) $plannedStart->format('Y');

            $existing = MembershipRenewal::query()
                ->where('member_id', $lockedMember->id)
                ->where('target_year', $targetYear)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing->load(['invoice.settlements', 'sourcePeriod', 'resultingPeriod']);
            }

            $renewalFee = (float) ($lockedMember->membershipPlan?->renewal_fee ?? 0);
            if ($renewalFee <= 0) {
                throw ValidationException::withMessages(['membership_plan' => 'The membership plan does not have a valid renewal fee.']);
            }

            $renewal = MembershipRenewal::query()->create([
                'member_id' => $lockedMember->id,
                'source_period_id' => $source->id,
                'target_year' => $targetYear,
                'planned_start_date' => $plannedStart->toDateString(),
                'planned_end_date' => $plannedEnd->toDateString(),
                'renewal_fee' => $renewalFee,
                'currency' => $lockedMember->membershipPlan->currency,
                'status' => 'invoiced',
                'generated_at' => now(),
                'created_by' => $actor?->id,
            ]);

            $invoiceDueAt = $dueDate !== null
                ? CarbonImmutable::instance(\DateTimeImmutable::createFromInterface($dueDate))->setTimezone(config('app.timezone'))
                : CarbonImmutable::parse($source->end_date->toDateString(), config('app.timezone'))->endOfDay();

            $invoice = FinancialLedger::query()->create([
                'member_id' => $lockedMember->id,
                'membership_application_id' => null,
                'membership_renewal_id' => $renewal->id,
                'period_id' => null,
                'status_id' => $this->paymentStatusId('PAY_PENDING'),
                'type' => 'invoice',
                'invoice_number' => $this->numbers->invoice(),
                'fee_type' => $lockedMember->membershipPlan->audience === 'corporate' ? 'annual_corporate' : 'annual_individual',
                'amount' => $renewalFee,
                'amount_settled' => 0,
                'currency' => $lockedMember->membershipPlan->currency,
                'due_date' => $invoiceDueAt,
                'notes' => "Annual membership renewal for {$targetYear}.",
                'meta' => [
                    'renewal_target_year' => $targetYear,
                    'planned_start_date' => $plannedStart->toDateString(),
                    'planned_end_date' => $plannedEnd->toDateString(),
                ],
                'created_by' => $actor?->id,
            ]);

            $renewal->forceFill(['invoice_id' => $invoice->id])->save();

            $this->audit->record('membership_renewal_invoiced', $renewal, after: [
                'registration_number' => $lockedMember->registration_number,
                'target_year' => $targetYear,
                'invoice_number' => $invoice->invoice_number,
                'amount' => $invoice->amount,
                'currency' => $invoice->currency,
                'due_date' => $invoice->due_date?->toIso8601String(),
            ]);

            return $renewal->load(['invoice.settlements', 'sourcePeriod', 'resultingPeriod']);
        });
    }

    /** @return array{payment: FinancialLedger, receipt: Receipt, renewal: MembershipRenewal, balance_due: string} */
    public function recordPayment(
        MembershipRenewal $renewal,
        string $amount,
        string $method,
        string $transactionReference,
        User $actor,
        ?string $provider = null,
        ?\DateTimeInterface $receivedAt = null,
    ): array {
        if (! in_array($method, ['bank_transfer', 'mobile_money', 'cash', 'card', 'cheque', 'other'], true)) {
            throw ValidationException::withMessages(['payment_method' => 'Unsupported payment method.']);
        }

        $amountValue = round((float) $amount, 4);
        if ($amountValue <= 0) {
            throw ValidationException::withMessages(['amount' => 'Payment amount must be greater than zero.']);
        }

        return DB::transaction(function () use ($renewal, $amountValue, $method, $transactionReference, $actor, $provider, $receivedAt): array {
            $lockedRenewal = MembershipRenewal::query()->whereKey($renewal->id)->lockForUpdate()->firstOrFail();
            $lockedMember = Member::query()->whereKey($lockedRenewal->member_id)->lockForUpdate()->firstOrFail();
            $this->assertRenewable($lockedMember->load('status'));

            $invoice = FinancialLedger::query()
                ->whereKey($lockedRenewal->invoice_id)
                ->where('membership_renewal_id', $lockedRenewal->id)
                ->where('type', 'invoice')
                ->lockForUpdate()
                ->firstOrFail();

            $balanceBefore = (float) $invoice->balance_due;
            if ($balanceBefore <= 0.0001) {
                throw ValidationException::withMessages(['invoice' => 'Renewal invoice is already fully settled.']);
            }
            if ($amountValue - $balanceBefore > 0.0001) {
                throw ValidationException::withMessages(['amount' => 'Payment exceeds the outstanding renewal balance.']);
            }

            $timestamp = $receivedAt ?? now();
            $payment = FinancialLedger::query()->create([
                'member_id' => $lockedMember->id,
                'membership_application_id' => null,
                'membership_renewal_id' => $lockedRenewal->id,
                'period_id' => null,
                'status_id' => $this->paymentStatusId('PAY_PAID'),
                'type' => 'payment',
                'fee_type' => $invoice->fee_type,
                'amount' => $amountValue,
                'amount_settled' => 0,
                'tx_reference' => $transactionReference,
                'payment_method' => $method,
                'payment_provider' => $provider,
                'currency' => $invoice->currency,
                'parent_invoice_id' => $invoice->id,
                'received_at' => $timestamp,
                'settled_at' => $timestamp,
                'notes' => 'Annual membership renewal payment.',
                'created_by' => $actor->id,
            ]);

            $receipt = new Receipt();
            $receipt->forceFill([
                'receipt_number' => $this->numbers->receipt(),
                'payment_ledger_id' => $payment->id,
                'membership_application_id' => null,
                'member_id' => $lockedMember->id,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'payment_reference' => $transactionReference,
                'issued_at' => now(),
                'issued_by' => $actor->id,
                'meta' => [
                    'payment_method' => $method,
                    'payment_provider' => $provider,
                    'membership_renewal_id' => $lockedRenewal->id,
                ],
            ])->save();

            $balanceDue = $invoice->refresh()->balance_due;
            if ((float) $balanceDue <= 0.0001) {
                $lockedRenewal->forceFill([
                    'status' => 'settled',
                    'settled_at' => $timestamp,
                ])->save();
                $lockedRenewal = $this->activateSettledRenewal($lockedRenewal, $actor);
            } else {
                $lockedRenewal->forceFill(['status' => 'partial'])->save();
            }

            $this->audit->record('membership_renewal_payment_recorded', $payment, after: [
                'registration_number' => $lockedMember->registration_number,
                'membership_renewal_id' => $lockedRenewal->id,
                'invoice_number' => $invoice->invoice_number,
                'receipt_number' => $receipt->receipt_number,
                'amount' => $payment->amount,
                'balance_due' => $balanceDue,
                'tx_reference' => $transactionReference,
                'renewal_status' => $lockedRenewal->status,
            ]);

            return [
                'payment' => $payment,
                'receipt' => $receipt,
                'renewal' => $lockedRenewal->load(['invoice.settlements', 'resultingPeriod']),
                'balance_due' => $balanceDue,
            ];
        });
    }

    /** @return array{generated:int, existing:int} */
    public function generateDueRenewals(int $daysAhead = 30, ?User $actor = null): array
    {
        $daysAhead = max(0, min($daysAhead, 365));
        $cutoff = today()->addDays($daysAhead);
        $generated = 0;
        $existing = 0;

        Member::query()
            ->notArchived()
            ->whereNotNull('membership_plan_id')
            ->whereHas('status', fn ($query) => $query->whereIn('code', ['ACTIVE', 'EXPIRED']))
            ->with(['latestPeriod', 'membershipPlan', 'status'])
            ->orderBy('id')
            ->chunkById(100, function ($members) use ($cutoff, $actor, &$generated, &$existing): void {
                foreach ($members as $member) {
                    $period = $member->latestPeriod;
                    if ($period === null || $period->end_date->gt($cutoff)) {
                        continue;
                    }

                    $targetYear = (int) $period->end_date->copy()->addDay()->format('Y');
                    $alreadyExists = MembershipRenewal::query()
                        ->where('member_id', $member->id)
                        ->where('target_year', $targetYear)
                        ->exists();

                    $this->ensureRenewal($member, $actor);
                    $alreadyExists ? $existing++ : $generated++;
                }
            });

        return compact('generated', 'existing');
    }

    /** @return array{activated:int, expired:int, unchanged:int} */
    public function synchronizeMembershipStatuses(?User $actor = null): array
    {
        $activated = 0;
        $expired = 0;
        $unchanged = 0;

        Member::query()
            ->notArchived()
            ->whereHas('status', fn ($query) => $query->whereNotIn('code', ['SUSPENDED', 'RESIGNED', 'ARCHIVED']))
            ->orderBy('id')
            ->chunkById(100, function ($members) use ($actor, &$activated, &$expired, &$unchanged): void {
                foreach ($members as $member) {
                    DB::transaction(function () use ($member, $actor, &$activated, &$expired, &$unchanged): void {
                        $locked = Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
                        $before = $locked->status()->value('code');
                        $desired = $this->desiredMembershipStatus($locked);

                        if ($desired === null || $desired === $before) {
                            $unchanged++;
                            return;
                        }

                        $this->transitionMemberStatus(
                            $locked,
                            $desired,
                            $desired === 'ACTIVE' ? 'PAID_PERIOD_ACTIVE' : 'MEMBERSHIP_PERIOD_EXPIRED',
                            $desired === 'ACTIVE'
                                ? 'A paid membership period is active.'
                                : 'No paid membership period covers the current date.',
                            $actor,
                        );

                        $desired === 'ACTIVE' ? $activated++ : $expired++;
                    });
                }
            });

        return compact('activated', 'expired', 'unchanged');
    }

    private function activateSettledRenewal(MembershipRenewal $renewal, User $actor): MembershipRenewal
    {
        if ($renewal->status === 'renewed' && $renewal->resulting_period_id !== null) {
            return $renewal;
        }

        $invoice = FinancialLedger::query()->whereKey($renewal->invoice_id)->with('settlements')->firstOrFail();
        if (! $invoice->is_fully_settled) {
            throw ValidationException::withMessages(['payment' => 'Renewal invoice must be fully settled before a membership period is created.']);
        }

        $period = MembershipPeriod::query()
            ->where('member_id', $renewal->member_id)
            ->where('target_year', $renewal->target_year)
            ->lockForUpdate()
            ->first();

        if ($period === null) {
            $period = MembershipPeriod::query()->create([
                'member_id' => $renewal->member_id,
                'start_date' => $renewal->planned_start_date,
                'end_date' => $renewal->planned_end_date,
                'target_year' => $renewal->target_year,
                'is_backdated' => $renewal->planned_start_date->lt(today()),
                'is_future' => $renewal->planned_start_date->gt(today()),
                'notes' => 'Annual membership period created after full renewal payment.',
                'created_by' => $actor->id,
            ]);
        } elseif (
            $period->start_date->toDateString() !== $renewal->planned_start_date->toDateString()
            || $period->end_date->toDateString() !== $renewal->planned_end_date->toDateString()
        ) {
            throw ValidationException::withMessages(['period' => 'An incompatible membership period already exists for this renewal year.']);
        }

        $renewal->forceFill([
            'status' => 'renewed',
            'resulting_period_id' => $period->id,
            'activated_at' => now(),
        ])->save();

        $member = Member::query()->whereKey($renewal->member_id)->lockForUpdate()->firstOrFail();
        $desired = $this->desiredMembershipStatus($member);
        $current = $member->status()->value('code');

        if ($desired === 'ACTIVE' && $current !== 'ACTIVE' && ! in_array($current, ['SUSPENDED', 'RESIGNED', 'ARCHIVED'], true)) {
            $this->transitionMemberStatus(
                $member,
                'ACTIVE',
                'RENEWAL_REACTIVATION',
                'Membership reactivated after the annual renewal invoice was fully paid.',
                $actor,
            );
        }

        $this->audit->record('membership_renewed', $renewal, after: [
            'registration_number' => $member->registration_number,
            'target_year' => $renewal->target_year,
            'period_id' => $period->id,
            'start_date' => $period->start_date->toDateString(),
            'end_date' => $period->end_date->toDateString(),
            'is_backdated' => $period->is_backdated,
            'is_future' => $period->is_future,
        ]);

        return $renewal;
    }

    private function desiredMembershipStatus(Member $member): ?string
    {
        $hasCurrentPeriod = MembershipPeriod::query()
            ->where('member_id', $member->id)
            ->active()
            ->exists();

        if ($hasCurrentPeriod) {
            return 'ACTIVE';
        }

        $latestEndDate = MembershipPeriod::query()->where('member_id', $member->id)->max('end_date');
        if ($latestEndDate !== null && CarbonImmutable::parse((string) $latestEndDate)->lt(today())) {
            return 'EXPIRED';
        }

        return null;
    }

    private function transitionMemberStatus(Member $member, string $toCode, string $reasonCode, string $notes, ?User $actor): void
    {
        $toStatusId = LookupStatus::query()->where('type', 'membership')->where('code', $toCode)->value('id');
        if ($toStatusId === null) {
            throw new \RuntimeException("{$toCode} membership status is not configured.");
        }

        $fromStatusId = (int) $member->status_id;
        if ($fromStatusId === (int) $toStatusId) {
            return;
        }

        $beforeCode = $member->status()->value('code');
        $member->forceFill(['status_id' => (int) $toStatusId])->save();
        $member->statusHistory()->create([
            'from_status_id' => $fromStatusId,
            'to_status_id' => (int) $toStatusId,
            'reason_code' => $reasonCode,
            'reason_notes' => $notes,
            'effective_at' => now(),
            'actor_id' => $actor?->id,
        ]);

        $this->audit->record('membership_status_changed', $member, before: ['status' => $beforeCode], after: [
            'status' => $toCode,
            'reason_code' => $reasonCode,
        ]);
    }

    private function assertRenewable(Member $member): void
    {
        $status = $member->status?->code ?? $member->status()->value('code');
        if (! in_array($status, ['ACTIVE', 'EXPIRED'], true)) {
            throw ValidationException::withMessages(['status' => 'Only active or expired memberships can enter the renewal lifecycle.']);
        }
        if ($member->membership_plan_id === null) {
            throw ValidationException::withMessages(['membership_plan' => 'Member has no membership plan assigned.']);
        }
    }

    private function paymentStatusId(string $code): int
    {
        $id = LookupStatus::query()->where('type', 'payment')->where('code', $code)->value('id');
        if ($id === null) {
            throw new \RuntimeException("Payment status {$code} is not configured.");
        }

        return (int) $id;
    }
}
