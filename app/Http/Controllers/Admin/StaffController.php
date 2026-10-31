<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PhoneNumber;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Owners give staff an admin role, change it or remove it. Every change is in the audit log. */
class StaffController extends Controller
{
    public function index(): View
    {
        return view('admin.staff', [
            'staff' => User::where('is_admin', true)->orderBy('admin_role')->orderBy('name')->get(),
            'roles' => AdminRole::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['phone' => PhoneNumber::normalize((string) $request->input('phone')) ?? $request->input('phone')]);
        $data = $request->validate([
            'phone' => ['required', 'regex:/^232[1-9]\d{7}$/'],
            'role' => ['required', Rule::enum(AdminRole::class)],
        ], ['phone.regex' => 'Type a Sierra Leone phone number, like 076 123 456.']);

        $user = User::where('phone', $data['phone'])->first();

        if (! $user) {
            throw ValidationException::withMessages(['phone' => 'Nobody has a WiBuy account with this number yet. Ask them to log in once, then try again.']);
        }

        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['phone' => 'You cannot change your own role. Ask another owner.']);
        }

        $user->forceFill(['is_admin' => true, 'admin_role' => AdminRole::from($data['role'])])->save();

        return back()->with('status', "{$user->name} is now {$user->admin_role->label()}.");
    }

    public function remove(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->is_admin, 404);

        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot remove yourself. Ask another owner.');
        }

        $user->forceFill(['is_admin' => false, 'admin_role' => null])->save();

        return back()->with('status', "{$user->name} is no longer an admin.");
    }
}
