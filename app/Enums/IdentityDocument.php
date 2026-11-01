<?php

namespace App\Enums;

/** The identity document a shop owner sends before their shop can be approved. */
enum IdentityDocument: string
{
    case NationalId = 'national_id';
    case Passport = 'passport';
    case ResidencePermit = 'residence_permit';
    case Other = 'other';

    public const SIERRA_LEONE = 'Sierra Leone';

    public function label(): string
    {
        return match ($this) {
            self::NationalId => 'National ID card',
            self::Passport => 'Passport',
            self::ResidencePermit => 'Residence permit',
            self::Other => 'Other official ID',
        };
    }

    /** What the photo should show. */
    public function photoHint(): string
    {
        return $this === self::Passport ? 'the passport page with your photo and details' : 'the front of the card';
    }

    /**
     * Sierra Leoneans: their national ID card or passport. Others: passport, residence permit, their own
     * country's national ID card, or another official ID.
     *
     * @return list<self>
     */
    public static function allowedFor(?string $nationality): array
    {
        return $nationality === self::SIERRA_LEONE ? [self::NationalId, self::Passport] : self::cases();
    }
}
