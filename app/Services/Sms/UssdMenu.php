<?php

namespace App\Services\Sms;

use App\Models\Product;
use App\Support\Money;

/**
 * The USSD menu (dial e.g. *123#), in the common gateway format: every answer so far comes as "1*123*2*1".
 * Replies start with "CON " (ask more) or "END " (done).
 */
class UssdMenu
{
    public function __construct(private SmsOrdering $ordering) {}

    public function reply(string $phone, string $text): string
    {
        $steps = $text === '' ? [] : explode('*', $text);

        return match ($steps[0] ?? null) {
            null => "CON Welcome to WiBuy\n1. Buy with a product code\n2. My last order\n3. How it works",
            '1' => $this->buy($phone, array_slice($steps, 1)),
            '2' => 'END '.$this->ordering->status($phone),
            '3' => 'END Every product on WiBuy has a code (its number on the website or app). Choose it here, pay with mobile money, and the shop is paid only after you get your order. New? Text JOIN your name, area, district to '.(config('services.sms_ordering.number') ?: 'WiBuy').'.',
            default => 'END Please choose 1, 2 or 3.',
        };
    }

    /** @param list<string> $steps code, how many, operator choice */
    private function buy(string $phone, array $steps): string
    {
        if (! isset($steps[0])) {
            return 'CON Enter the product code (the number on the product page)';
        }

        $product = ctype_digit($steps[0]) ? Product::find((int) $steps[0]) : null;

        if (! $product || ! $product->isListed()) {
            return "END There is no product with code {$steps[0]}.";
        }

        if (! isset($steps[1])) {
            return "CON {$product->title}\n".Money::format($product->price).($product->unit ? " per {$product->unit}" : '')."\nHow many?";
        }

        if (! ctype_digit($steps[1]) || (int) $steps[1] < 1 || (int) $steps[1] > 999) {
            return 'END Please enter a number of items.';
        }

        if (! isset($steps[2])) {
            return "CON Pay with:\n1. Orange Money\n2. Afrimoney";
        }

        $operator = SmsOrdering::operator(['1' => 'ORANGE', '2' => 'AFRI'][$steps[2]] ?? '');

        return $operator ? 'END '.$this->ordering->buy($phone, $product->id, (int) $steps[1], $operator) : 'END Please choose 1 or 2.';
    }
}
