<?php

namespace App\Services\Queue;

use App\Models\TimingRule;

/**
 * .claude/QUEUE.md: "ONLY if within the tenant's local business hours +
 * timezone. Never blast." Used by both ReleasePendingContacts (gating a
 * fresh step-1 release) and SendReviewRequest (gating a self-chained
 * step-2/3 send, which bypasses the command's release gate entirely) —
 * shared so the two can never silently drift into different definitions
 * of "business hours."
 */
class BusinessHoursGate
{
    public function isOpen(TimingRule $timingRule): bool
    {
        $now = now($timingRule->timezone)->format('H:i:s');
        $start = $timingRule->business_hours_start;
        $end = $timingRule->business_hours_end;

        if ($start <= $end) {
            return $now >= $start && $now <= $end;
        }

        // Overnight window (e.g. 22:00-06:00) — outside the default config
        // shipped today, but a tenant could set one, so handled correctly
        // rather than silently mishandled.
        return $now >= $start || $now <= $end;
    }

    public function minutesUntilNextOpen(TimingRule $timingRule): int
    {
        $now = now($timingRule->timezone);
        $todayStart = $now->copy()->setTimeFromTimeString($timingRule->business_hours_start);

        $nextStart = $now->lessThan($todayStart) ? $todayStart : $todayStart->addDay();

        return max(1, (int) $now->diffInMinutes($nextStart, absolute: true));
    }
}
