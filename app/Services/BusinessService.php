<?php

namespace App\Services;

use App\Exceptions\MarketplaceException;
use App\Models\BusinessProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/** Business buyer accounts: register with papers, WiBuy verifies, then business-only prices unlock. */
class BusinessService
{
    public function __construct(private ImageService $images) {}

    /**
     * @param  array{business_name: string, registration_no: string, district?: ?string, address?: ?string}  $data
     * @param  array<int, UploadedFile>  $documents
     *
     * @throws MarketplaceException
     */
    public function register(User $user, array $data, array $documents): BusinessProfile
    {
        $profile = $user->businessProfile;

        if ($profile?->isVerified()) {
            throw new MarketplaceException('Your business is already verified.');
        }

        return DB::transaction(function () use ($user, $data, $documents, $profile) {
            $profile = BusinessProfile::updateOrCreate(['user_id' => $user->id], $data + [
                'status' => 'pending',
                'rejection_reason' => null,
            ]);

            foreach ($documents as $position => $file) {
                $this->images->attach($profile, $file, 'business_doc', $user->id, $position);
            }

            $user->setRelation('businessProfile', $profile);

            return $profile;
        });
    }

    public function verify(BusinessProfile $profile): void
    {
        $profile->forceFill(['status' => 'verified', 'verified_at' => now(), 'rejection_reason' => null])->save();
        $profile->user->unsetRelation('businessProfile');
    }

    public function reject(BusinessProfile $profile, ?string $reason): void
    {
        $profile->forceFill(['status' => 'rejected', 'rejection_reason' => $reason ?: 'The papers could not be checked.'])->save();
    }
}
