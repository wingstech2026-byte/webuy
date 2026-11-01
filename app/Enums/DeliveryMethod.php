<?php

namespace App\Enums;

enum DeliveryMethod: string
{
    case Delivery = 'delivery';
    case Pickup = 'pickup';
    case Station = 'station';          // the buyer collects at a WiBuy pickup station
    case StationDoor = 'station_door'; // a WiBuy rider brings it from the station to the buyer's address

    public function label(): string
    {
        return match ($this) {
            self::Delivery => 'Seller delivers to me',
            self::Pickup => 'I collect from the seller',
            self::Station => 'I collect at a WiBuy station',
            self::StationDoor => 'WiBuy brings it from its station to me',
        };
    }

    /** The seller takes the parcel to a WiBuy station (it does not go straight to the buyer). */
    public function viaStation(): bool
    {
        return $this === self::Station || $this === self::StationDoor;
    }
}
