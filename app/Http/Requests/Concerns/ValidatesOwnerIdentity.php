<?php

namespace App\Http\Requests\Concerns;

use App\Services\OwnerIdentityService;

/** The shop owner's national ID card: the serial number on the card and a photo of it (front; back optional). */
trait ValidatesOwnerIdentity
{
    use ValidatesImages;

    protected function normalizeOwnerIdNumber(): void
    {
        if (filled($this->input('owner_id_number'))) {
            $this->merge(['owner_id_number' => OwnerIdentityService::normalize((string) $this->input('owner_id_number'))]);
        }
    }

    /** @return array<string, list<mixed>> */
    protected function ownerIdentityRules(bool $required): array
    {
        return [
            'owner_id_number' => [$required ? 'required' : 'nullable', 'string', 'regex:/^[A-Z0-9]{6,20}$/'],
            'owner_id_front' => [...$this->imageRules($required), 'required_with:owner_id_number'],
            'owner_id_back' => $this->imageRules(),
        ];
    }

    /** @return array<string, string> */
    protected function ownerIdentityMessages(): array
    {
        return [
            'owner_id_number.required' => 'Please enter the number of your national ID card (the serial number printed on it).',
            'owner_id_number.regex' => 'Type the ID card number as printed on the card: 6 to 20 letters and numbers.',
            'owner_id_front.required' => 'Please add a clear photo of the front of your national ID card.',
            'owner_id_front.required_with' => 'Please add a clear photo of the front of your national ID card.',
        ] + $this->imageMessages('owner_id_front', 'photo of your ID card') + $this->imageMessages('owner_id_back', 'photo of your ID card');
    }
}
