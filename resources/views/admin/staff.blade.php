@extends('admin.layout')

@section('admin')
    <h1 class="mb-1 text-2xl font-extrabold">Staff and roles</h1>
    <p class="mb-4 text-stone-600">Give each person only what their job needs. They log in with their own phone number as usual.</p>

    @if (session('error')) <p class="card mb-4 border-red-200 bg-red-50 p-3 text-red-800">{{ session('error') }}</p> @endif

    <div class="card mb-6 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left"><th class="th">Name</th><th class="th">Phone</th><th class="th">Role</th><th class="th"></th></tr></thead>
            <tbody>
                @foreach ($staff as $member)
                    <tr>
                        <td class="td">{{ $member->name }} @if ($member->is(auth()->user())) <span class="badge bg-stone-100">you</span> @endif</td>
                        <td class="td">{{ $member->display_phone }}</td>
                        <td class="td">{{ $member->admin_role?->label() ?? 'No role (no access)' }}</td>
                        <td class="td text-right">
                            @unless ($member->is(auth()->user()))
                                <form method="POST" action="{{ route('admin.staff.remove', $member) }}" onsubmit="return confirm('Remove {{ $member->name }} as an admin?')">@csrf<button class="btn btn-outline btn-sm">Remove</button></form>
                            @endunless
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <form method="POST" action="{{ route('admin.staff.store') }}" class="card grid max-w-xl gap-3 p-4">
        @csrf
        <h2 class="text-lg font-extrabold">Add staff or change a role</h2>
        <div><label class="label" for="phone">Their phone number</label><input id="phone" name="phone" required inputmode="tel" class="input" value="{{ old('phone') }}" placeholder="076 123 456">
            @error('phone') <p class="field-error">{{ $message }}</p> @enderror</div>
        <fieldset class="space-y-2">
            <legend class="label">Role</legend>
            @foreach ($roles as $role)
                <label class="choice"><input type="radio" name="role" value="{{ $role->value }}" class="size-5 accent-brand-700" @checked(old('role', 'support') === $role->value)>
                    <span><strong>{{ $role->label() }}</strong><br><span class="text-sm text-stone-600">{{ $role->description() }}</span></span></label>
            @endforeach
            @error('role') <p class="field-error">{{ $message }}</p> @enderror
        </fieldset>
        <div><button class="btn btn-primary">Save role</button></div>
    </form>
@endsection
