<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Membership;
use App\Models\PointTransaction;
use App\Models\Share;
use App\Models\User;

/** What the engagement features cost and do: coupons by source, points, shares, WiBuy Pass. */
class EngagementController extends Controller
{
    public function index()
    {
        return view('admin.rewards.index', [
            'coupons' => Coupon::selectRaw("source, count(*) as issued, sum(case when status = 'used' then 1 else 0 end) as used, coalesce(sum(case when status = 'used' then discount_given else 0 end), 0) as cost")
                ->groupBy('source')->orderBy('source')->get(),
            'points' => [
                'earned' => (int) PointTransaction::where('points', '>', 0)->sum('points'),
                'spent' => (int) -PointTransaction::where('points', '<', 0)->sum('points'),
                'balance' => (int) User::sum('points_balance'),
            ],
            'shares' => [
                'made' => Share::count(),
                'opened' => Share::whereNotNull('rewarded_at')->count(),
                'signups' => User::whereNotNull('referred_by_id')->count(),
            ],
            'memberships' => Membership::with('user')->latest('id')->limit(30)->get(),
            'activeMembers' => Membership::where('status', 'active')->where('ends_at', '>', now())->distinct('user_id')->count('user_id'),
            'passIncome' => Membership::where('status', 'active')->sum('price'),
        ]);
    }
}
