<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WiBuy phone preview</title>
    <link rel="icon" type="image/png" href="/icon-192.png">
    <style>
        /* One phone, sized to the panel's height, running the real app at a real phone width (375px). */
        :root { --frame: #2b2f33; --bezel: #3a3f44; --bg: #f4f5f4; --ink: #1c1917; --muted: #6b6f72; --green: #15803d; }
        @media (prefers-color-scheme: dark) { :root { --bg: #1e1e1e; --ink: #e7e7e7; --muted: #a0a4a8; } }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {

            
            display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 10px;
            background: var(--bg); color: var(--ink); font: 14px system-ui, -apple-system, 'Segoe UI', sans-serif;
        }
        .bar { display: flex; flex-wrap: wrap; justify-content: center; gap: 6px; }
        .bar a, .bar button {
            border: 1px solid #c8cbc9; border-radius: 999px; padding: 6px 12px; background: #fff; color: #1c1917;
            font: 600 13px system-ui, sans-serif; text-decoration: none; cursor: pointer;
        }
        .bar a.on { background: var(--green); border-color: var(--green); color: #fff; }
        .stage { flex: 1; min-height: 0; width: 100%; display: flex; justify-content: center; align-items: flex-start; }
        /* Phone shell like the picture: thick dark rounded bezel */
        .phone {
            --h: min(100%, 860px);
            height: var(--h); aspect-ratio: 395 / 820; max-width: 100%;
            padding: 3.2%; border-radius: 12% / 6%; background: var(--frame);
            box-shadow: inset 0 0 0 3px var(--bezel), 0 22px 40px -16px rgba(0, 0, 0, .45);
            display: flex;
        }
        .screen { position: relative; flex: 1; overflow: hidden; border-radius: 9% / 4.4%; background: #fafaf9; container-type: size; }
        /* The app is drawn at 375px wide and scaled to the screen's width. */
        iframe {
            position: absolute; top: 0; left: 0; border: 0;
            width: 375px; height: calc(375px * 100cqh / 100cqw);
            transform: scale(calc(100cqw / 375px)); transform-origin: 0 0;
        }
        @supports not (width: 1cqw) {
            iframe { width: 100%; height: 100%; transform: none; }
        }
        .hint { color: var(--muted); font-size: 12px; text-align: center; margin: 0; }
    </style>
</head>
<body>
    <nav class="bar" aria-label="Screens">
        @foreach ($screens as $label => $path)
            <a href="{{ route('phone', ['open' => $path]) }}" class="{{ $open === $path ? 'on' : '' }}">{{ $label }}</a>
        @endforeach
        <button type="button" onclick="document.getElementById('app').contentWindow.location.reload()">↻ Reload</button>
    </nav>

    <div class="stage">
        <div class="phone">
            <div class="screen">
                <iframe id="app" src="{{ url($open) }}" title="WiBuy"></iframe>
            </div>
        </div>
    </div>

    <p class="hint">The real app. With <code>npm run dev</code> running it reloads by itself when you save a file.</p>
</body>
</html>
