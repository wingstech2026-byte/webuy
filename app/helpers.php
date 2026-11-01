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

if (! function_exists('data_saver')) {
    /** "Save data" is on: show small pictures instead of full-size ones (a cookie set from the page footer). */
    function data_saver(): bool
    {
        return request()->cookie('data_saver') === '1';
    }
}
