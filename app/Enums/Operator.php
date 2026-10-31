<?php

namespace App\Enums;

enum Operator: string
{
    case Orange = 'ORANGE';
    case Afrimoney = 'AFRIMONEY';

    public function label(): string
    {
        return match ($this) {
            self::Orange => 'Orange Money',
            self::Afrimoney => 'Afrimoney',
        };
    }

    /** The operator code sent to pawaPay, configurable in config/payments.php. */
    public function gatewayCode(): string
    {
        return config('payments.operators.'.$this->value);
    }
}
