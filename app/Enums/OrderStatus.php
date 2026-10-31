<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Paid = 'paid';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Disputed = 'disputed';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::PendingPayment => 'Waiting for payment',
            self::Paid => 'Paid, waiting for the seller',
            self::Accepted => 'Seller accepted',
            self::Rejected => 'Seller rejected',
            self::Shipped => 'Shipped',
            self::Delivered => 'Delivered',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::Disputed => 'Problem reported',
            self::Refunded => 'Refunded',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Completed, self::Delivered, self::Accepted, self::Shipped => 'bg-brand-100 text-brand-800',
            self::Paid, self::PendingPayment => 'bg-amber-100 text-amber-800',
            self::Disputed, self::Rejected => 'bg-red-100 text-red-800',
            self::Cancelled, self::Refunded => 'bg-stone-200 text-stone-700',
        };
    }

    /** Orders the seller still needs to do something about or has money coming from. */
    public static function open(): array
    {
        return [self::Paid, self::Accepted, self::Shipped, self::Delivered, self::Disputed];
    }

    /** Statuses where the money is with WiBuy and the seller has not been credited yet. */
    public static function funded(): array
    {
        return [self::Paid, self::Accepted, self::Shipped, self::Delivered, self::Disputed];
    }

    /** The buyer can report a problem while the order is in progress, not after it completes. */
    public static function disputable(): array
    {
        return [self::Accepted, self::Shipped, self::Delivered];
    }
}
