<?php

namespace App\Enums;

enum ProductCondition: string
{
    case New = 'new';
    case Used = 'used';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
