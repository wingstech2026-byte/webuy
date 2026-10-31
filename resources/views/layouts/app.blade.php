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
                        Cart
                        @if (($cartCount ?? 0) > 0)
                            <span class="ml-0.5 inline-flex min-w-5 justify-center rounded-full bg-amber-400 px-1.5 text-xs font-extrabold text-stone-900">{{ $cartCount > 99 ? '99+' : $cartCount }}</span>
                        @endif
                    </a>
                    <a href="{{ route('orders.index') }}" class="whitespace-nowrap rounded-lg px-2 py-2 hover:bg-brand-800">Orders</a>
                    <a href="{{ route('chat.index') }}" class="hidden whitespace-nowrap rounded-lg px-2 py-2 hover:bg-brand-800 sm:inline-block">Chat <span data-unread-badge data-url="{{ route('chat.unread') }}" class="{{ ($chatUnread ?? 0) > 0 ? '' : 'hidden' }} ml-0.5 inline-flex min-w-5 justify-center rounded-full bg-red-500 px-1.5 text-xs font-extrabold text-white">{{ ($chatUnread ?? 0) > 99 ? '99+' : ($chatUnread ?? 0) }}</span></a>
                    <details class="relative">
                        <summary class="cursor-pointer list-none whitespace-nowrap rounded-lg px-2 py-2 hover:bg-brand-800" aria-label="Menu">Menu ▾</summary>
                        <div class="absolute right-0 mt-1 w-56 overflow-hidden rounded-xl bg-white py-1 text-stone-800 shadow-lg ring-1 ring-stone-200">
                            <p class="border-b border-stone-100 px-4 py-2 text-xs text-stone-500">{{ auth()->user()->name ?: auth()->user()->display_phone }}</p>
                            <a href="{{ route('wishlist.index') }}" class="block px-4 py-3 hover:bg-brand-50">Wishlist</a>
                            <a href="{{ route('chat.index') }}" class="block px-4 py-3 hover:bg-brand-50 sm:hidden">Chat @if (($chatUnread ?? 0) > 0) <span class="badge bg-red-600 text-white">{{ $chatUnread }}</span> @endif</a>
                            @if (auth()->user()->isSeller())
                                <a href="{{ route('seller.dashboard') }}" class="block px-4 py-3 font-bold text-brand-800 hover:bg-brand-50">Seller dashboard</a>
                            @else
                                <a href="{{ route('sell.apply') }}" class="block px-4 py-3 hover:bg-brand-50">Sell on {{ config('wibuy.name') }}</a>
                            @endif
                            @if (auth()->user()->is_admin)
                                <a href="{{ route('admin.dashboard') }}" class="block px-4 py-3 font-bold text-brand-800 hover:bg-brand-50">Admin</a>
                            @endif
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button class="block w-full px-4 py-3 text-left hover:bg-brand-50">Log out</button>
                            </form>
                        </div>
                    </details>
                @else
                    <a href="{{ route('login') }}" class="whitespace-nowrap rounded-lg bg-white px-3 py-2 text-brand-800 hover:bg-brand-50">Log in</a>
                @endauth
            </nav>
        </div>
        <form action="{{ route('search') }}" method="GET" role="search" class="mx-auto flex max-w-6xl gap-2 px-4 pb-3 pt-2">
            <label for="site-search" class="sr-only">Search products</label>
            <input id="site-search" type="search" name="q" value="{{ request()->routeIs('search', 'categories.show') ? request('q') : '' }}"
                   placeholder="Search rice, phones, solar panels…" class="min-h-11 w-full rounded-xl border-0 bg-white px-4 text-base text-stone-900 placeholder:text-stone-500 focus:outline-none focus:ring-4 focus:ring-brand-300">
            <button class="min-h-11 rounded-xl bg-amber-400 px-4 font-bold text-stone-900 hover:bg-amber-300">Search</button>
        </form>
    </header>

    <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-6">
        @include('partials.flash')
        @yield('content')
    </main>

    <footer class="border-t border-stone-200 bg-white">
        <div class="mx-auto max-w-6xl space-y-2 px-4 py-6 text-center text-sm text-stone-600">
            <p class="font-bold text-brand-800">{{ config('wibuy.name') }} – {{ config('wibuy.tagline') }}</p>
            <p>Pay safely with Orange Money or Afrimoney. The seller is only paid after you confirm you got your order.</p>
            <p>
                <a class="font-semibold text-brand-700 underline" href="{{ route('sell.apply') }}">Sell on {{ config('wibuy.name') }}</a>
                @if (config('wibuy.support_whatsapp'))
                    &middot; <a class="font-semibold text-brand-700 underline" href="https://wa.me/{{ config('wibuy.support_whatsapp') }}">Help on WhatsApp</a>
                @endif
            </p>
            <p class="text-xs text-stone-400">&copy; {{ date('Y') }} {{ config('wibuy.name') }}</p>
        </div>
    </footer>
</body>
</html>
