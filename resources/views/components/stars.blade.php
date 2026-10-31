@props(['rating' => 0, 'count' => null])

@php($value = (float) $rating)

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 text-sm']) }} aria-label="{{ number_format($value, 1) }} out of 5 stars">
    <span class="text-amber-500" aria-hidden="true">@for ($i = 1; $i <= 5; $i++){{ $value >= $i - 0.25 ? '★' : '☆' }}@endfor</span>
    @if ($count !== null)
        <span class="text-stone-500">{{ $count > 0 ? number_format($value, 1).' ('.$count.')' : 'No ratings yet' }}</span>
    @endif
</span>
