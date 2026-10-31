<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\RewardSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Every reward amount and limit of the engagement features, in one form. */
class RewardSettingsController extends Controller
{
    public function edit()
    {
        $values = [];

        foreach (RewardSettings::FIELDS as $key => [, , $type]) {
            $values[$key] = match ($type) {
                'int' => RewardSettings::int($key),
                'money' => RewardSettings::money($key),
                'bool' => RewardSettings::bool($key),
                default => RewardSettings::text($key),
            };
        }

        return view('admin.rewards.settings', [
            'groups' => collect(RewardSettings::FIELDS)->groupBy(fn ($field) => $field[0], preserveKeys: true),
            'values' => $values,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [];

        foreach (RewardSettings::FIELDS as $key => [, , $type]) {
            $rules[$key] = match ($type) {
                'int' => ['required', 'integer', 'min:0', 'max:100000'],
                'money' => ['required', 'numeric', 'min:0', 'max:1000000'],
                'bool' => ['nullable', 'boolean'],
                default => ['required', 'string', 'max:500'],
            };
        }

        $data = $request->validate($rules);

        foreach (RewardSettings::FIELDS as $key => [, , $type]) {
            RewardSettings::put($key, $type === 'bool' ? $request->boolean($key) : $data[$key]);
        }

        return redirect()->route('admin.rewards.settings')->with('status', 'Reward settings saved.');
    }
}
