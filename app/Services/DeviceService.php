<?php

namespace App\Services;

use App\Http\Middleware\RememberVisitor;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\Request;

/**
 * Remembers which phones and browsers each account used. The app sends a random id it keeps
 * (X-Device-Id); the website keeps one in a cookie. Only a hash is stored.
 * Used to stop self-cuts, self-referrals and many accounts on one device.
 */
class DeviceService
{
    /** The app sends X-Device-Id; the website uses its device cookie. */
    public function hashFromRequest(Request $request): ?string
    {
        return $this->hash($request->header('X-Device-Id') ?: $request->cookie(RememberVisitor::DEVICE_COOKIE));
    }

    public function hash(?string $deviceId): ?string
    {
        $deviceId = trim((string) $deviceId);

        return $deviceId === '' ? null : hash('sha256', 'wibuy-device|'.$deviceId);
    }

    public function record(User $user, ?string $deviceHash): void
    {
        if (! $deviceHash) {
            return;
        }

        UserDevice::updateOrCreate(['user_id' => $user->id, 'device_hash' => $deviceHash], ['last_seen_at' => now()]);
    }

    /** True when the two accounts were ever used on the same device. */
    public function shareADevice(User $a, User $b): bool
    {
        return UserDevice::where('user_id', $a->id)
            ->whereIn('device_hash', UserDevice::where('user_id', $b->id)->select('device_hash'))
            ->exists();
    }

    /** Other accounts seen on this device. */
    public function otherAccountIds(string $deviceHash, User $except): array
    {
        return UserDevice::where('device_hash', $deviceHash)->where('user_id', '!=', $except->id)->pluck('user_id')->all();
    }
}
