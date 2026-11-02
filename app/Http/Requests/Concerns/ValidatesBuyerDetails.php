<?php

namespace App\Http\Requests\Concerns;

use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * A buyer's name and where they live (district, town or area). The phone number is checked by the SMS code.
 * Asked when signing up, and once from existing accounts that miss any of them.
 */
trait ValidatesBuyerDetails
{
    protected function detailsOwner(): ?User
    {
        return User::where('phone', $this->detailsPhone())->first();
    }

    abstract protected function detailsPhone(): ?string;

    public function needsName(): bool
    {
        return blank($this->detailsOwner()?->name);
    }

    public function needsLocation(): bool
    {
        $user = $this->detailsOwner();

        return blank($user?->district) || blank($user?->area);
    }

    /** @return array<string, list<mixed>> */
    protected function buyerDetailsRules(bool $nameRequired, bool $locationRequired): array
    {
        return [
            'name' => [$nameRequired ? 'required' : 'nullable', 'string', 'min:2', 'max:80'],
            'district' => [$locationRequired ? 'required' : 'nullable', Rule::in(config('wibuy.districts'))],
            'area' => [$locationRequired ? 'required' : 'nullable', 'string', 'min:2', 'max:80'],
        ];
    }

    /** @return array<string, string> */
    protected function buyerDetailsMessages(): array
    {
        return [
            'name.required' => 'Please tell us your full name.',
            'name.min' => 'Please tell us your full name.',
            'district.*' => 'Please choose the district where you live.',
            'area.*' => 'Please type your town or area, e.g. Lumley or Bo town.',
        ];
    }
}
