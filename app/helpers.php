<?php

use App\Support\Money;
use App\Support\PhoneNumber;

if (! function_exists('money')) {
    function money(string|int|float|null $amount): string
    {
        return Money::format($amount);
    }
}

if (! function_exists('phone_display')) {
    function phone_display(?string $phone): string
    {
        return PhoneNumber::display($phone);
    }
}
