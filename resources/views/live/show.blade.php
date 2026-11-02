@extends('layouts.app')

@section('title', $show->title)

@section('content')
    <div class="grid gap-5 lg:grid-cols-[1fr_22rem]">
        <div class="space-y-3">
            <div>
                <p class="text-sm font-semibold {{ $show->isLive() ? 'text-red-600' : 'text-stone-500' }}" data-live-status>{{ $show->isLive() ? '🔴 LIVE' : ($show->status === 'scheduled' ? 'Starts '.($show->starts_at?->format('D j M, H:i') ?? 'soon') : 'Replay') }}</p>
                <h1 class="text-2xl font-extrabold">{{ $show->title }}</h1>
                <a href="{{ route('shops.show', $show->seller) }}" class="font-semibold text-brand-700">{{ $show->seller->shop_name }}</a>
            </div>
            @if ($show->embed_url)
                <div class="aspect-video overflow-hidden rounded-xl bg-black">
                    <iframe src="{{ $show->embed_url }}" class="size-full" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen title="{{ $show->title }}"></iframe>
                </div>
            @endif
            <a href="{{ $show->stream_url }}" target="_blank" rel="noopener" class="text-sm text-brand-700 underline">Open the video on {{ str_contains($show->stream_url, 'youtu') ? 'YouTube' : 'Facebook' }}</a>

            <div data-pinned class="{{ $feed['pinned'] ? '' : 'hidden' }} card flex flex-wrap items-center justify-between gap-2 border-red-300 bg-red-50 p-4">
                <div><p class="text-sm font-semibold text-red-700">📌 Showing now</p><p class="font-bold" data-pinned-title>{{ $feed['pinned']['title'] ?? '' }}</p><p data-pinned-price>{{ isset($feed['pinned']) ? money($feed['pinned']['price']) : '' }}</p></div>
                <a data-pinned-link href="{{ $feed['pinned']['url'] ?? '#' }}" class="btn btn-primary">Buy now</a>
            </div>

            <h2 class="text-lg font-extrabold">In this show</h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                @foreach ($show->products as $product) <x-product-card :product="$product" /> @endforeach
            </div>
        </div>

        <aside class="card flex h-[32rem] flex-col p-3">
            <h2 class="mb-2 font-bold">Comments</h2>
            <ul class="flex-1 space-y-1 overflow-y-auto text-sm" data-comments>
                @foreach ($feed['comments'] as $comment)
                    <li data-id="{{ $comment['id'] }}"><strong>{{ $comment['name'] }}:</strong> {{ $comment['body'] }}</li>
                @endforeach
            </ul>
            @auth
                @if ($show->isLive())
                    <form class="mt-2 flex gap-2" data-comment-form>
                        <input name="body" maxlength="200" required class="input flex-1" placeholder="Ask about the goods..." aria-label="Your comment">
                        <button class="btn btn-primary btn-sm">Send</button>
                    </form>
                    <p class="hidden text-xs text-red-700" data-comment-error></p>
                @endif
            @else
                <a href="{{ route('login') }}" class="mt-2 text-sm font-semibold text-brand-700">Log in to comment</a>
            @endauth
        </aside>
    </div>

    <script>
        // New comments and the pinned product every few seconds while the show is live.
        (() => {
            const list = document.querySelector('[data-comments]');
            const feedUrl = @json(route('live.feed', $show));
            const commentUrl = @json(route('live.comment', $show));
            let last = Math.max(0, ...[...list.children].map((li) => Number(li.dataset.id)));
            const add = (comments) => comments.forEach((c) => {
                if (c.id <= last) return;
                const li = document.createElement('li');
                li.dataset.id = c.id;
                const name = document.createElement('strong');
                name.textContent = c.name + ': ';
                li.append(name, document.createTextNode(c.body));
                list.append(li);
                last = c.id;
                list.scrollTop = list.scrollHeight;
            });
            const show = (feed) => {
                add(feed.comments);
                const box = document.querySelector('[data-pinned]');
                box.classList.toggle('hidden', !feed.pinned);
                if (feed.pinned) {
                    box.querySelector('[data-pinned-title]').textContent = feed.pinned.title;
                    box.querySelector('[data-pinned-price]').textContent = 'Le ' + Number(feed.pinned.price).toFixed(2);
                    box.querySelector('[data-pinned-link]').href = feed.pinned.url;
                }
            };
            list.scrollTop = list.scrollHeight;
            @if ($show->isLive())
                setInterval(() => fetch(`${feedUrl}?after=${last}`, { headers: { Accept: 'application/json' } }).then((r) => r.json()).then(show).catch(() => {}), 4000);
            @endif
            document.querySelector('[data-comment-form]')?.addEventListener('submit', async (e) => {
                e.preventDefault();
                const form = e.target;
                const error = document.querySelector('[data-comment-error]');
                const response = await fetch(`${commentUrl}?after=${last}`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
                    body: JSON.stringify({ body: form.body.value }),
                });
                const json = await response.json();
                error.classList.toggle('hidden', response.ok);
                if (!response.ok) { error.textContent = json.message; return; }
                form.reset();
                show(json);
            });
        })();
    </script>
@endsection
