<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** "My details": the buyer's name and where they live (the phone number is their login, checked by SMS). */
class AccountDetailsController extends Controller
{
    public function edit(Request $request): View
    {
        return view('account.details', ['user' => $request->user(), 'districts' => config('wibuy.districts')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'district' => ['required', Rule::in(config('wibuy.districts'))],
            'area' => ['required', 'string', 'min:2', 'max:80'],
        ], [
            'name.*' => 'Please type your full name.',
            'district.*' => 'Please choose the district where you live.',
            'area.*' => 'Please type your town or area, e.g. Lumley or Bo town.',
        ]);

        $request->user()->forceFill(['name' => trim($data['name']), 'district' => $data['district'], 'area' => trim($data['area'])])->save();

        return redirect()->intended(route('account.details'))->with('status', 'Your details are saved.');
    }
}
