@extends('layouts.app')

@section('title', 'Admin')

@section('content')
    @php
        $supportUnread = app(\App\Services\ChatService::class)->supportUnreadTotal();
        $tabs = [
            'admin.dashboard' => ['Dashboard', 'admin.dashboard'],
            'admin.sellers.*' => ['Sellers', 'admin.sellers.index'],
            'admin.orders.*' => ['Orders', 'admin.orders.index'],
            'admin.disputes.*' => ['Disputes', 'admin.disputes.index'],
            'admin.payouts.*' => ['Payouts', 'admin.payouts.index'],
            'admin.products.*' => ['Products', 'admin.products.index'],
            'admin.categories.*' => ['Categories', 'admin.categories.index'],
            'admin.users.*' => ['Users', 'admin.users.index'],
            'admin.rewards*' => ['Rewards', 'admin.rewards.index'],
            'admin.business.*' => ['Businesses', 'admin.business.index'],
            'admin.inspections.*' => ['Inspections', 'admin.inspections.index'],
            'admin.riders.*' => ['Riders', 'admin.riders.index'],
            'admin.global.*' => ['WiBuy Global', 'admin.global.index'],
            'admin.assistant.*' => ['AI assistant', 'admin.assistant.index'],
            'admin.support.*' => ['Support', 'admin.support.index'],
            'admin.chat.*' => ['Reports', 'admin.chat.reports'],
            'admin.group-buys.*' => ['Group buys', 'admin.group-buys.index'],
            'admin.audit' => ['Audit log', 'admin.audit'],
            'admin.staff.*' => ['Staff', 'admin.staff.index'],
            'admin.settings*' => ['Settings', 'admin.settings'],
        ];
        // Only the pages this admin's role may open.
        $tabs = array_filter($tabs, fn ($tab) => \App\Support\AdminAccess::allows(auth()->user(), $tab[1]));
    @endphp

    <nav class="-mx-4 mb-6 flex gap-1 overflow-x-auto px-4 pb-1" aria-label="Admin">
        @foreach ($tabs as $pattern => [$label, $route])
            <a href="{{ route($route) }}"
               class="whitespace-nowrap rounded-full px-4 py-2 text-sm font-bold {{ request()->routeIs($pattern) ? 'bg-brand-700 text-white' : 'bg-white text-stone-700 ring-1 ring-stone-200 hover:bg-brand-50' }}">
                {{ $label }}
                @if ($route === 'admin.support.index' && $supportUnread > 0)
                    <span class="ml-1 rounded-full bg-red-600 px-1.5 text-xs font-extrabold text-white">{{ $supportUnread }}</span>
                @endif
            </a>
        @endforeach
    </nav>

    @yield('admin')
@endsection
