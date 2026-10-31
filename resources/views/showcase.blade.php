<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('wibuy.name') }} – {{ config('wibuy.tagline') }}</title>
    <meta name="theme-color" content="#15803d">
    <link rel="icon" type="image/png" href="/icon-192.png">
    <style>
        :root { --green: #15803d; --ink: #1c1917; --frame: #1c1917; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; color: var(--ink);
            font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            background: #fafaf9 radial-gradient(#d6d3d1 1px, transparent 1px) 0 0 / 18px 18px;
        }
        header { text-align: center; padding: 32px 16px 8px; }
        header h1 { margin: 0; font-size: 34px; font-weight: 800; color: var(--green); }
        header p { margin: 6px 0 0; font-size: 17px; color: #57534e; }
        .phones { display: flex; flex-wrap: wrap; justify-content: center; gap: 40px; padding: 28px 16px 48px; }
        figure { margin: 0; text-align: center; }
        .phone {
            position: relative; width: 300px; height: 620px; padding: 12px;
            border: 3px solid var(--frame); border-radius: 46px; background: #fff;
            box-shadow: 0 0 0 7px #fff, 0 0 0 9px var(--frame), 0 24px 50px rgba(0, 0, 0, .18);
        }
        /* side buttons */
        .phone::before, .phone::after { content: ''; position: absolute; width: 4px; background: var(--frame); border-radius: 2px; }
        .phone::before { left: -13px; top: 120px; height: 60px; }
        .phone::after { right: -13px; top: 150px; height: 90px; }
        .screen { position: relative; width: 100%; height: 100%; overflow: hidden; border-radius: 34px; border: 2px solid var(--frame); background: #fafaf9; }
        /* the notch with speaker and camera, like the sketch */
        .notch {
            position: absolute; top: 0; left: 50%; transform: translateX(-50%); z-index: 2;
            width: 130px; height: 24px; background: #fff; border: 2px solid var(--frame); border-top: 0;
            border-radius: 0 0 16px 16px; display: flex; align-items: center; justify-content: center; gap: 10px;
        }
        .notch span:first-child { width: 44px; height: 6px; border: 2px solid var(--frame); border-radius: 4px; }
        .notch span:last-child { width: 9px; height: 9px; border: 2px solid var(--frame); border-radius: 50%; }
        .home-bar { position: absolute; bottom: 6px; left: 50%; transform: translateX(-50%); z-index: 2; width: 90px; height: 5px; border-radius: 3px; background: var(--frame); opacity: .7; }
        /* The app runs at a real phone width (375px) and is scaled to fit the frame. */
        iframe { width: 375px; height: calc(100% / .72); border: 0; transform: scale(.72); transform-origin: 0 0; }
        figcaption { margin-top: 22px; font-weight: 800; font-size: 18px; }
        figcaption small { display: block; margin-top: 2px; font-weight: 500; font-size: 14px; color: #78716c; }
        .open { display: inline-block; margin-top: 10px; padding: 10px 18px; border-radius: 999px; background: var(--green); color: #fff; font-weight: 700; text-decoration: none; }
        footer { text-align: center; padding: 0 16px 40px; color: #57534e; }
    </style>
</head>
<body>
    <header>
        <h1>{{ config('wibuy.name') }}</h1>
        <p>{{ config('wibuy.tagline') }} Tap around inside the phones: it is the real app.</p>
    </header>

    <main class="phones">
        @foreach ($screens as [$title, $subtitle, $url])
            <figure>
                <div class="phone">
                    <div class="screen">
                        <div class="notch" aria-hidden="true"><span></span><span></span></div>
                        <iframe src="{{ $url }}" title="{{ $title }}" loading="lazy"></iframe>
                        <div class="home-bar" aria-hidden="true"></div>
                    </div>
                </div>
                <figcaption>{{ $title }}<small>{{ $subtitle }}</small></figcaption>
            </figure>
        @endforeach
    </main>

    <footer>
        <a class="open" href="{{ route('home') }}">Open the full app</a>
        <p>On a phone, open <strong>{{ url('/') }}</strong> and choose “Add to Home screen”.</p>
    </footer>
</body>
</html>
