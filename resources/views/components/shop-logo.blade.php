@props(['seller'])

@php($logo = $seller->logo)

@if ($logo?->isReady())
    <img src="{{ $logo->thumb_url }}" alt="{{ $seller->shop_name }}" loading="lazy" decoding="async" {{ $attributes->merge(['class' => 'object-cover bg-stone-100']) }}>
@else
    <span {{ $attributes->merge(['class' => 'flex items-center justify-center bg-brand-700 text-xl font-extrabold text-white']) }} aria-hidden="true">
        {{ mb_strtoupper(mb_substr($seller->shop_name, 0, 1)) }}
    </span>
@endif
