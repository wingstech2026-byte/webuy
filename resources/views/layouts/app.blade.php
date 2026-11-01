<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@hasSection('title')@yield('title') · @endif{{ config('wibuy.name') }} – {{ config('wibuy.tagline') }}</title>
    <meta name="theme-color" content="#15803d">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Installable on a phone home screen like an app --}}
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/png" sizes="192x192" href="/icon-192.png">
    <link rel="apple-touch-icon" href="/icon-192.png">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="WiBuy">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    @hasSection('meta')
        @yield('meta')
    @else
        <meta name="description" content="{{ config('wibuy.name') }}: {{ config('wibuy.tagline') }} Shops, market traders and wholesalers across Sierra Leone. Pay with Orange Money or Afrimoney.">
        <meta property="og:site_name" content="{{ config('wibuy.name') }}">
        <meta property="og:type" content="website">
        <meta property="og:title" content="{{ config('wibuy.name') }} – {{ config('wibuy.tagline') }}">
        <meta property="og:description" content="Shops, market traders and wholesalers across Sierra Leone. Pay with mobile money.">
        <meta property="og:url" content="{{ url()->current() }}">
    @endif
    @stack('head')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen flex-col">
    <header class="sticky top-0 z-20 bg-brand-700 text-white shadow">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-3 px-4 pt-3">
            <a href="{{ route('home') }}" class="leading-tight">
                <span class="block text-2xl font-extrabold tracking-tight">{{ config('wibuy.name') }}</span>
                <span class="block text-xs text-brand-100">{{ config('wibuy.tagline') }}</span>
            </a>
            <nav class="flex items-center text-sm font-semibold" aria-label="Main">
                @auth
                    <a href="{{ route('cart.index') }}" class="relative whitespace-nowrap rounded-lg px-2 py-2 hover:bg-brand-800">
                        {{ __('Cart') }}
                        @if (($cartCount ?? 0) > 0)
                            <span class="ml-0.5 inline-flex min-w-5 justify-center rounded-full bg-amber-400 px-1.5 text-xs font-extrabold text-stone-900">{{ $cartCount > 99 ? '99+' : $cartCount }}</span>
                        @endif
                    </a>
                    <a href="{{ route('orders.index') }}" class="whitespace-nowrap rounded-lg px-2 py-2 hover:bg-brand-800">{{ __('Orders') }}</a>
                    <a href="{{ route('chat.index') }}" class="hidden whitespace-nowrap rounded-lg px-2 py-2 hover:bg-brand-800 sm:inline-block">{{ __('Chat') }} <span data-unread-badge data-url="{{ route('chat.unread') }}" class="{{ ($chatUnread ?? 0) > 0 ? '' : 'hidden' }} ml-0.5 inline-flex min-w-5 justify-center rounded-full bg-red-500 px-1.5 text-xs font-extrabold text-white">{{ ($chatUnread ?? 0) > 99 ? '99+' : ($chatUnread ?? 0) }}</span></a>
                    <details class="relative">
                        <summary class="cursor-pointer list-none whitespace-nowrap rounded-lg px-2 py-2 hover:bg-brand-800" aria-label="{{ __('Menu') }}">{{ __('Menu') }} ▾</summary>
                        <div class="absolute right-0 mt-1 w-56 overflow-hidden rounded-xl bg-white py-1 text-stone-800 shadow-lg ring-1 ring-stone-200">
                            <p class="border-b border-stone-100 px-4 py-2 text-xs text-stone-500">{{ auth()->user()->name ?: auth()->user()->display_phone }}</p>
                            <a href="{{ route('wishlist.index') }}" class="block px-4 py-3 hover:bg-brand-50">{{ __('Wishlist') }}</a>
                            <a href="{{ route('compare.index') }}" class="block px-4 py-3 hover:bg-brand-50">{{ __('Compare & recently viewed') }}</a>
                            <a href="{{ route('group-buys.index') }}" class="block px-4 py-3 hover:bg-brand-50">👥 Buy together</a>
                            @if (\App\Models\Station::where('user_id', auth()->id())->exists())
                                <a href="{{ route('station.index') }}" class="block px-4 py-3 font-semibold hover:bg-brand-50">📦 My WiBuy station</a>
                            @endif
                            @if (auth()->user()->is_rider)
                                <a href="{{ route('rider.index') }}" class="block px-4 py-3 font-semibold hover:bg-brand-50">🛵 Rider jobs</a>
                            @endif
                            <a href="{{ route('chat.index') }}" class="block px-4 py-3 hover:bg-brand-50 sm:hidden">Chat @if (($chatUnread ?? 0) > 0) <span class="badge bg-red-600 text-white">{{ $chatUnread }}</span> @endif</a>
                            @if (auth()->user()->isSeller())
                                <a href="{{ route('seller.dashboard') }}" class="block px-4 py-3 font-bold text-brand-800 hover:bg-brand-50">{{ __('Seller dashboard') }}</a>
                            @else
                                <a href="{{ route('sell.apply') }}" class="block px-4 py-3 hover:bg-brand-50">Sell on {{ config('wibuy.name') }}</a>
                            @endif
                            @if (auth()->user()->is_admin)
                                <a href="{{ route('admin.dashboard') }}" class="block px-4 py-3 font-bold text-brand-800 hover:bg-brand-50">Admin</a>
                            @endif
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="block w-full px-4 py-3 text-left hover:bg-brand-50">{{ __('Log out') }}</button>
                            </form>
                        </div>
                    </details>
                @else
                    <a href="{{ route('login') }}" class="whitespace-nowrap rounded-lg bg-white px-3 py-2 text-brand-800 hover:bg-brand-50">{{ __('Log in') }}</a>
                @endauth
            </nav>
        </div>
        <form action="{{ route('search') }}" method="GET" role="search" class="mx-auto flex max-w-6xl gap-2 px-4 pb-3 pt-2">
            <label for="site-search" class="sr-only">{{ __('Search products') }}</label>
            <input id="site-search" type="search" name="q" value="{{ request()->routeIs('search', 'categories.show') ? request('q') : '' }}"
                   placeholder="{{ __('Search rice, phones, solar panels…') }}" class="min-h-11 w-full rounded-xl border-0 bg-white px-4 text-base text-stone-900 placeholder:text-stone-500 focus:outline-none focus:ring-4 focus:ring-brand-300">
            @if (\App\Services\Assistant\PhotoSearch::enabled())
                <a href="{{ route('search.photo') }}" class="flex min-h-11 items-center rounded-xl bg-white px-3 text-xl hover:bg-brand-50" title="{{ __('Search with a photo') }}" aria-label="{{ __('Search with a photo') }}">📷</a>
            @endif
            <button class="min-h-11 rounded-xl bg-amber-400 px-4 font-bold text-stone-900 hover:bg-amber-300">{{ __('Search') }}</button>
        </form>
    </header>

    <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-6">
        @include('partials.flash')
        @yield('content')
    </main>

    <footer class="border-t border-stone-200 bg-white">
        <div class="mx-auto max-w-6xl space-y-2 px-4 py-6 text-center text-sm text-stone-600">
            <p class="font-bold text-brand-800">{{ config('wibuy.name') }} – {{ __(config('wibuy.tagline')) }}</p>
            <p>{{ __('Pay safely with Orange Money or Afrimoney. The seller is only paid after you confirm you got your order.') }}</p>
            <p>
                <a class="font-semibold text-brand-700 underline" href="{{ route('sell.apply') }}">{{ __('Sell on WiBuy') }}</a>
                @if (config('wibuy.support_whatsapp'))
                    &middot; <a class="font-semibold text-brand-700 underline" href="https://wa.me/{{ config('wibuy.support_whatsapp') }}">{{ __('Help on WhatsApp') }}</a>
                @endif
            </p>
            <form method="POST" action="{{ route('data-saver') }}">
                @csrf
                <button class="text-xs font-semibold text-brand-700 underline">{{ data_saver() ? __('📶 Save data is on (smaller pictures) · turn off') : __('📶 Save data: use smaller pictures') }}</button>
            </form>
            {{-- English or Krio --}}
            <form method="POST" action="{{ route('language') }}" class="flex items-center justify-center gap-2 text-xs">
                @csrf
                <span>🌍 {{ __('Language') }}:</span>
                @foreach (\App\Http\Middleware\SetLanguage::LANGUAGES as $code => $name)
                    <button name="language" value="{{ $code }}" class="rounded-full px-2 py-1 font-semibold {{ app()->getLocale() === $code ? 'bg-brand-700 text-white' : 'text-brand-700 underline' }}" @if (app()->getLocale() === $code) aria-current="true" @endif>{{ $name }}</button>
                @endforeach
            </form>
            <p class="text-xs text-stone-400">&copy; {{ date('Y') }} {{ config('wibuy.name') }}</p>
        </div>
    </footer>
</body>
</html>
