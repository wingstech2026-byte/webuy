<?php

namespace App\Services;

use App\Exceptions\MarketplaceException;
use App\Models\CheckIn;
use App\Models\User;
use App\Support\RewardSettings;
use Illuminate\Database\UniqueConstraintViolationException;

/** Daily check-in: points that grow with the streak (up to a limit), and water for the farm. */
class CheckInService
{
    public function __construct(private PointsService $points, private FarmService $farms) {}

    public function today(User $user): ?CheckIn
    {
        return CheckIn::where('user_id', $user->id)->where('day', today()->toDateString())->first();
    }

    /** The streak someone would be on if they checked in today. */
    public function currentStreak(User $user): int
    {
        $yesterday = CheckIn::where('user_id', $user->id)->where('day', today()->subDay()->toDateString())->first();

        return $this->today($user)?->streak ?? ($yesterday ? $yesterday->streak + 1 : 1);
    }

    /** @throws MarketplaceException */
    public function checkIn(User $user): CheckIn
    {
        if ($this->today($user)) {
            throw new MarketplaceException('You already checked in today. Come back tomorrow.');
        }

        $streak = $this->currentStreak($user);
        $counted = min($streak, max(1, RewardSettings::int('check_in_max_streak')));
        $points = RewardSettings::int('check_in_points') + ($counted - 1) * RewardSettings::int('check_in_streak_bonus');

        try {
            $checkIn = CheckIn::create(['user_id' => $user->id, 'day' => today()->toDateString(), 'streak' => $streak, 'points' => $points]);
        } catch (UniqueConstraintViolationException) {
            throw new MarketplaceException('You already checked in today. Come back tomorrow.');
        }

        $this->points->award($user, $points, "Daily check-in (day {$streak})");
        $this->farms->mission($user, 'check_in');

        return $checkIn;
    }
}
