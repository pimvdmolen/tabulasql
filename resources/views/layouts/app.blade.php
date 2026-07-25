<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? config('app.name', 'TabulaSQL') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <script>
        // Apply the theme before first paint to avoid a flash.
        // 'auto' resolves to 'light' or 'dark' from the OS preference; the
        // other options (light/dark/classic) are concrete themes. The .dark
        // class only ever reflects the resolved 'dark' case (it drives every
        // Tailwind dark: variant); data-theme always carries the concrete
        // theme so CSS can also target e.g. [data-theme="classic"] directly.
        (function () {
            const media = window.matchMedia('(prefers-color-scheme: dark)');
            const resolve = (theme) => theme === 'auto' ? (media.matches ? 'dark' : 'light') : theme;
            const apply = (theme) => {
                const effective = resolve(theme);
                document.documentElement.classList.toggle('dark', effective === 'dark');
                document.documentElement.setAttribute('data-theme', effective);
            };
            window.__theme = @js(\App\Models\Setting::get('theme', 'auto'));
            window.__applyTheme = (theme) => {
                window.__theme = theme;
                apply(theme);
                window.dispatchEvent(new CustomEvent('themechange'));
            };
            media.addEventListener('change', () => {
                apply(window.__theme);
                window.dispatchEvent(new CustomEvent('themechange'));
            });
            apply(window.__theme);
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-screen overflow-hidden bg-surface text-body antialiased select-none">
    {{ $slot }}
</body>
</html>
