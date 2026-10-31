@php
    $rewardTabs = [
        'admin.rewards.index' => 'Overview',
        'admin.rewards.settings' => 'Settings and limits',
        'admin.rewards.spin' => 'Spin wheel',
        'admin.rewards.deals' => 'Lightning deals',
        'admin.rewards.cuts' => 'Cut the price',
        'admin.rewards.farm' => 'WiBuy Farm',
        'admin.rewards.trials' => 'Free trials',
    ];
@endphp
<nav class="mb-4 flex flex-wrap gap-2" aria-label="Rewards">
    @foreach ($rewardTabs as $route => $label)
        <a href="{{ route($route) }}" class="badge py-2 text-sm {{ request()->routeIs($route) ? 'bg-brand-700 text-white' : 'bg-white ring-1 ring-stone-200' }}">{{ $label }}</a>
    @endforeach
</nav>
<p class="mb-4 text-sm text-stone-500">All rewards are coupons or WiBuy Points. They lower order prices and can never be withdrawn as cash. Nobody pays to play.</p>
