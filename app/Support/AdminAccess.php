<?php

namespace App\Support;

use App\Enums\AdminRole;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Which admin pages each role may open, by route name. Owners may open everything.
 * A route that is not listed is owner-only, so a new admin page is closed until it is added here.
 */
final class AdminAccess
{
    /** @var array<string, list<AdminRole>> route name pattern => roles (besides owners) */
    private const RULES = [
        'admin.dashboard' => [AdminRole::Support, AdminRole::Finance, AdminRole::Catalogue, AdminRole::Marketing],

        'admin.orders.index' => [AdminRole::Support, AdminRole::Finance],
        'admin.orders.show' => [AdminRole::Support, AdminRole::Finance],
        'admin.orders.retry-refund' => [AdminRole::Finance],
        'admin.payouts.*' => [AdminRole::Finance],

        'admin.disputes.*' => [AdminRole::Support],
        'admin.support.*' => [AdminRole::Support],
        'admin.chat.*' => [AdminRole::Support],
        'admin.users.*' => [AdminRole::Support],
        'admin.inspections.*' => [AdminRole::Support],
        'admin.agents.*' => [AdminRole::Support],
        'admin.riders.index' => [AdminRole::Support, AdminRole::Finance], // Finance pays fees from it
        'admin.riders.*' => [AdminRole::Support],
        'admin.stations.*' => [AdminRole::Support],
        'admin.assistant.*' => [AdminRole::Support],

        'admin.sellers.*' => [AdminRole::Catalogue],
        'admin.products.*' => [AdminRole::Catalogue],
        'admin.categories.*' => [AdminRole::Catalogue],
        'admin.business.*' => [AdminRole::Catalogue],
        'admin.global.*' => [AdminRole::Catalogue],

        'admin.rewards.settings*' => [],
        'admin.rewards*' => [AdminRole::Marketing],
        'admin.group-buys.*' => [AdminRole::Marketing],
    ];

    public static function allows(?User $user, string $routeName): bool
    {
        $role = $user?->is_admin ? $user->admin_role : null;

        if ($role === null) {
            return false;
        }

        if ($role === AdminRole::Owner) {
            return true;
        }

        // The first matching rule decides, so more specific patterns are listed first.
        foreach (self::RULES as $pattern => $roles) {
            if (Str::is($pattern, $routeName)) {
                return in_array($role, $roles, true);
            }
        }

        return false;
    }
}
