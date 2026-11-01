<?php

namespace App\Http\Requests\Concerns;

use App\Enums\IdentityDocument;
use App\Services\OwnerIdentityService;
use Illuminate\Validation\Rule;

/** The shop owner's identity document: nationality, type, number and a photo (front or passport photo page; back optional). */
trait ValidatesOwnerIdentity
{
    use ValidatesImages;

    protected function normalizeOwnerIdNumber(): void
    {
        if (filled($this->input('owner_id_number'))) {
            $this->merge(['owner_id_number' => OwnerIdentityService::normalize((string) $this->input('owner_id_number'))]);
        }
    }

    /**
     * Sierra Leoneans: national ID card or passport. Others: passport, residence permit, their own national ID
     * card or another official ID.
     *
     * @return array<string, list<mixed>>
     */
    protected function ownerIdentityRules(bool $required): array
    {
        $allowed = array_map(fn (IdentityDocument $d) => $d->value, IdentityDocument::allowedFor($this->input('owner_nationality')));

        return [
            'owner_nationality' => [$required ? 'required' : 'nullable', 'required_with:owner_id_number', Rule::in(config('countries'))],
            'owner_id_type' => [$required ? 'required' : 'nullable', 'required_with:owner_id_number', Rule::in($allowed)],
            'owner_id_number' => [$required ? 'required' : 'nullable', 'string', 'regex:/^[A-Z0-9]{6,20}$/'],
            'owner_id_front' => [...$this->imageRules($required), 'required_with:owner_id_number'],
            'owner_id_back' => $this->imageRules(),
        ];
    }

    /** @return array<string, string> */
    protected function ownerIdentityMessages(): array
    {
        return [
            'owner_nationality.*' => 'Please choose your nationality.',
            'owner_id_type.required' => 'Please choose which document you are sending.',
            'owner_id_type.required_with' => 'Please choose which document you are sending.',
            'owner_id_type.in' => 'Sierra Leoneans send their national ID card or passport.',
            'owner_id_number.required' => 'Please enter the number printed on your ID card or passport.',
            'owner_id_number.regex' => 'Type the number as printed on the document: 6 to 20 letters and numbers.',
            'owner_id_front.required' => 'Please add a clear photo of your document (for a passport, the page with your photo).',
            'owner_id_front.required_with' => 'Please add a clear photo of your document (for a passport, the page with your photo).',
        ] + $this->imageMessages('owner_id_front', 'photo of your ID card') + $this->imageMessages('owner_id_back', 'photo of your ID card');
    }
}
