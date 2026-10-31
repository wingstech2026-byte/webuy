@extends('admin.layout')

@section('admin')
    <h1 class="mb-4 text-2xl font-extrabold">Users</h1>

    <form method="GET" class="mb-4 flex flex-wrap gap-2">
        <input type="search" name="q" value="{{ $term }}" placeholder="Name or phone" class="input max-w-xs">
        <select name="role" class="input max-w-40" aria-label="Role">
            <option value="">Everyone</option>
            @foreach (['buyer' => 'Buyers', 'seller' => 'Sellers', 'admin' => 'Admins'] as $value => $label)
                <option value="{{ $value }}" @selected($role === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <button class="btn btn-outline btn-sm">Filter</button>
    </form>

    <div class="card overflow-x-auto">
        <table class="w-full">
            <thead class="border-b border-stone-200"><tr><th class="th">Name</th><th class="th">Phone</th><th class="th">Role</th><th class="th text-right">Orders</th><th class="th">Joined</th><th class="th"></th></tr></thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($users as $user)
                    <tr>
                        <td class="td font-semibold">{{ $user->name ?: '—' }} @if ($user->isChatBanned()) <span class="badge bg-red-100 text-red-800">chat blocked</span> @endif</td>
                        <td class="td">{{ $user->display_phone }}</td>
                        <td class="td">
                            {{ $user->role_label }}
                            @if ($user->seller) <a href="{{ route('admin.sellers.show', $user->seller) }}" class="block text-xs text-brand-700">{{ $user->seller->shop_name }} ({{ $user->seller->status->label() }})</a> @endif
                        </td>
                        <td class="td text-right">{{ $user->orders_count }}</td>
                        <td class="td whitespace-nowrap">{{ $user->created_at->format('j M Y') }}</td>
                        <td class="td text-right">
                            @unless ($user->is_admin)
                                <form method="POST" action="{{ route('admin.chat.users.ban', $user) }}">@csrf
                                    <button class="text-xs font-semibold text-red-700 underline">{{ $user->isChatBanned() ? 'Unblock chat' : 'Block chat' }}</button>
                                </form>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr><td class="td text-stone-500" colspan="6">No users found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $users->links() }}</div>
@endsection
