<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Member;
use App\Models\MemberCredential;
use App\Models\MembershipPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MemberCredentialService
{
    public function __construct(private readonly AuditService $audit) {}

    public function issue(Member $member, User $actor): MemberCredential
    {
        return DB::transaction(function () use ($member, $actor): MemberCredential {
            $locked = Member::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            $locked->load(['status', 'membershipPlan', 'periods', 'organisation']);

            if ($locked->status?->code !== 'ACTIVE') {
                throw ValidationException::withMessages(['membership' => 'Only ACTIVE members can receive a current membership credential.']);
            }

            $coverage = $this->continuousCoverage($locked);
            if ($coverage === null) {
                throw ValidationException::withMessages(['membership' => 'No paid membership period currently covers this member.']);
            }

            $type = $locked->type === 'corporate' ? 'certificate' : 'card';
            $existing = MemberCredential::query()
                ->where('member_id', $locked->id)
                ->where('credential_type', $type)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->valid_until->isSameDay($coverage['end'])) {
                return $existing;
            }

            if ($existing !== null) {
                $existing->forceFill(['revoked_at' => now()])->save();
                $this->audit->record('member_credential_revoked', $existing, after: ['reason' => 'coverage_changed']);
            }

            $credential = new MemberCredential();
            $credential->forceFill([
                'member_id' => $locked->id,
                'credential_type' => $type,
                'verification_code' => (string) Str::uuid(),
                'valid_from' => $coverage['start']->toDateString(),
                'valid_until' => $coverage['end']->toDateString(),
                'issued_at' => now(),
                'issued_by' => $actor->id,
                'meta' => [
                    'registration_number' => $locked->registration_number,
                    'membership_plan' => $locked->membershipPlan?->name,
                ],
            ])->save();

            $this->audit->record('member_credential_issued', $credential, after: [
                'member_id' => $locked->id,
                'credential_type' => $type,
                'valid_until' => $credential->valid_until->toDateString(),
                'verification_code' => $credential->verification_code,
            ]);

            return $credential->load(['member.status', 'member.membershipPlan', 'member.organisation']);
        });
    }

    public function active(Member $member): ?MemberCredential
    {
        return MemberCredential::query()
            ->where('member_id', $member->id)
            ->whereNull('revoked_at')
            ->latest('issued_at')
            ->first();
    }

    /** @return array{start: \Carbon\CarbonImmutable, end: \Carbon\CarbonImmutable}|null */
    private function continuousCoverage(Member $member): ?array
    {
        $periods = $member->periods
            ->sortBy(fn (MembershipPeriod $period) => $period->start_date->format('Y-m-d'))
            ->values();

        $current = $periods->first(fn (MembershipPeriod $period): bool => $period->start_date->lte(today()) && $period->end_date->gte(today()));
        if ($current === null) {
            return null;
        }

        $start = $current->start_date;
        $end = $current->end_date;

        foreach ($periods as $period) {
            if ($period->id === $current->id || $period->end_date->lte($end)) {
                continue;
            }
            if ($period->start_date->lte($end->addDay())) {
                $end = $period->end_date;
            }
        }

        return ['start' => $start, 'end' => $end];
    }

    public function renderSvg(Member $member, MemberCredential $credential): string
    {
        $member->loadMissing(['membershipPlan', 'organisation']);
        $name = $this->xml($member->display_name);
        $number = $this->xml($member->registration_number);
        $plan = $this->xml($member->membershipPlan?->name ?? 'ICGU Membership');
        $validUntil = $credential->valid_until->format('d M Y');
        $verification = $this->xml($credential->verification_code);
        $verifyUrl = $this->xml(rtrim((string) config('app.url'), '/').'/membership/verify/'.$credential->verification_code);

        if ($credential->credential_type === 'certificate') {
            return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="800" viewBox="0 0 1200 800">
  <rect width="1200" height="800" fill="#ffffff"/>
  <rect x="24" y="24" width="1152" height="752" rx="18" fill="none" stroke="#12315a" stroke-width="8"/>
  <rect x="42" y="42" width="1116" height="716" rx="12" fill="none" stroke="#f58220" stroke-width="2"/>
  <text x="600" y="135" text-anchor="middle" font-family="Georgia,serif" font-size="58" font-weight="700" fill="#12315a">ICGU</text>
  <text x="600" y="190" text-anchor="middle" font-family="Arial,sans-serif" font-size="22" fill="#5d6775">INSTITUTE OF CORPORATE GOVERNANCE UGANDA</text>
  <text x="600" y="295" text-anchor="middle" font-family="Georgia,serif" font-size="46" fill="#12315a">Membership Certificate</text>
  <text x="600" y="365" text-anchor="middle" font-family="Arial,sans-serif" font-size="22" fill="#5d6775">This certifies that</text>
  <text x="600" y="430" text-anchor="middle" font-family="Georgia,serif" font-size="42" font-weight="700" fill="#12315a">{$name}</text>
  <text x="600" y="485" text-anchor="middle" font-family="Arial,sans-serif" font-size="24" fill="#5d6775">{$plan}</text>
  <text x="600" y="545" text-anchor="middle" font-family="Arial,sans-serif" font-size="22" fill="#12315a">Membership No. {$number}</text>
  <text x="600" y="585" text-anchor="middle" font-family="Arial,sans-serif" font-size="20" fill="#12315a">Valid until {$validUntil}</text>
  <line x1="220" y1="635" x2="980" y2="635" stroke="#f58220" stroke-width="2"/>
  <text x="600" y="675" text-anchor="middle" font-family="Arial,sans-serif" font-size="15" fill="#5d6775">Verification: {$verification}</text>
  <text x="600" y="705" text-anchor="middle" font-family="Arial,sans-serif" font-size="13" fill="#5d6775">{$verifyUrl}</text>
</svg>
SVG;
        }

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="600" viewBox="0 0 1000 600">
  <rect width="1000" height="600" rx="36" fill="#12315a"/>
  <rect x="24" y="24" width="952" height="552" rx="28" fill="none" stroke="#f58220" stroke-width="3"/>
  <text x="70" y="110" font-family="Georgia,serif" font-size="58" font-weight="700" fill="#ffffff">ICGU</text>
  <text x="70" y="150" font-family="Arial,sans-serif" font-size="18" fill="#d7dde8">INSTITUTE OF CORPORATE GOVERNANCE UGANDA</text>
  <text x="70" y="250" font-family="Arial,sans-serif" font-size="22" fill="#f58220">MEMBERSHIP CARD</text>
  <text x="70" y="315" font-family="Georgia,serif" font-size="42" font-weight="700" fill="#ffffff">{$name}</text>
  <text x="70" y="365" font-family="Arial,sans-serif" font-size="22" fill="#d7dde8">{$plan}</text>
  <text x="70" y="420" font-family="Arial,sans-serif" font-size="22" fill="#ffffff">Member No. {$number}</text>
  <text x="70" y="462" font-family="Arial,sans-serif" font-size="20" fill="#ffffff">Valid until {$validUntil}</text>
  <text x="70" y="520" font-family="Arial,sans-serif" font-size="14" fill="#b9c2d0">Verify: {$verification}</text>
  <text x="70" y="548" font-family="Arial,sans-serif" font-size="12" fill="#b9c2d0">{$verifyUrl}</text>
</svg>
SVG;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
