<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Seller;
use App\Models\User;
use App\Services\LedgerService;
use App\Support\Money;
use App\Support\PhoneNumber;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A correction on a shop's or a rider's balance, with a reason the shop or rider sees in their history.
 * Adds (+) or takes away (-); taking away never leaves the balance below zero. Recorded in the audit log.
 */
class BalanceAdjustmentController extends Controller
{
    public function __invoke(Request $request, LedgerService $ledger): RedirectResponse
    {
        $request->merge(['phone' => PhoneNumber::normalize((string) $request->input('phone')) ?? $request->input('phone')]);
        $data = $request->validate([
            'for' => ['required', 'in:seller,rider'],
            'phone' => ['required', 'regex:/^232[1-9]\d{7}$/'],
            'amount' => ['required', 'regex:/^-?\d{1,8}(\.\d{1,2})?$/', 'not_in:0,-0'],
            'reason' => ['required', 'string', 'min:5', 'max:150'],
        ], [
            'phone.regex' => 'Type a Sierra Leone phone number, like 076 123 456.',
            'amount.regex' => 'Type an amount like 20 to add, or -20 to take away.',
            'amount.not_in' => 'The amount cannot be zero.',
            'reason.*' => 'Say why (5 to 150 characters). The shop or rider sees it.',
        ]);

        $user = User::where('phone', $data['phone'])->first();
        $seller = $data['for'] === 'seller' ? $user?->seller : null;

        if (! $user || ($data['for'] === 'seller' && ! $seller)) {
            throw ValidationException::withMessages(['phone' => $data['for'] === 'seller' ? 'No shop belongs to this number.' : 'Nobody has a WiBuy account with this number.']);
        }

        $amount = Money::from($data['amount']);
        $description = "Adjustment by WiBuy: {$data['reason']}";

        $name = DB::transaction(function () use ($ledger, $user, $seller, $amount, $description) {
            // Lock the balance owner so a payout request cannot spend the money at the same moment.
            if ($seller) {
                $seller = Seller::lockForUpdate()->findOrFail($seller->id);
                $this->keepAboveZero($ledger->available($seller), $amount);
                $ledger->adjust($seller, $amount, $description);

                return $seller->shop_name;
            }

            $rider = User::lockForUpdate()->findOrFail($user->id);
            $this->keepAboveZero($ledger->riderAvailable($rider), $amount);
            $ledger->adjustRider($rider, $amount, $description);

            return $rider->name;
        });

        return back()->with('status', ($amount[0] === '-' ? 'Took '.money(ltrim($amount, '-')).' from ' : 'Added '.money($amount).' to ')."{$name}'s balance.");
    }

    private function keepAboveZero(string $available, string $amount): void
    {
        if (Money::cmp(Money::add($available, $amount), '0') < 0) {
            throw ValidationException::withMessages(['amount' => 'Their balance is only '.money($available).', so you cannot take away more than that.']);
        }
    }
}
