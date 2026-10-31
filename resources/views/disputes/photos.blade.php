@if ($photos->isNotEmpty())
    <div class="mt-3 flex flex-wrap gap-2">
        @foreach ($photos as $photo)
            @if ($photo->isReady())
                <a href="{{ $photo->url }}" target="_blank" rel="noopener"><img src="{{ $photo->thumb_url }}" alt="Photo" loading="lazy" class="size-24 rounded-lg object-cover"></a>
            @else
                <span class="flex size-24 items-center justify-center rounded-lg bg-stone-100 text-xs text-stone-500">Processing…</span>
            @endif
        @endforeach
    </div>
@endif
