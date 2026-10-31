@props(['title', 'action' => null, 'href' => null])

<div class="card p-8 text-center">
    <p class="text-lg font-bold">{{ $title }}</p>
    @if (trim($slot) !== '')
        <p class="mt-1 text-stone-600">{{ $slot }}</p>
    @endif
    @if ($action && $href)
        <a href="{{ $href }}" class="btn btn-primary mx-auto mt-4 max-w-xs">{{ $action }}</a>
    @endif
</div>
