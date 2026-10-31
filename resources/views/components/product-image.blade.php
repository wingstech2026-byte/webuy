@props(['product', 'full' => false])

{{-- Thumbnails by default (light on data); the full picture only where asked for. --}}
@php($image = $product->cover)

@if ($image)
    <img src="{{ $full ? $image->url : $image->thumb_url }}" alt="{{ $product->title }}" loading="lazy" decoding="async"
         {{ $attributes->merge(['class' => 'object-cover bg-stone-100']) }}>
@else
    <div {{ $attributes->merge(['class' => 'flex items-center justify-center bg-brand-50 text-4xl font-extrabold text-brand-700']) }} aria-hidden="true">
        {{ mb_strtoupper(mb_substr($product->title, 0, 1)) }}
    </div>
@endif
