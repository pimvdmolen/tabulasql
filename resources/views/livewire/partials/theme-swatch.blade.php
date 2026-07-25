{{-- Small static preview of a theme's palette, independent of the theme
     currently applied to the page (these colors are intentionally hardcoded,
     not var()-based, since they must render the same regardless of the
     active theme). --}}
@php
    $palettes = [
        'light' => ['chrome' => '#f4f4f5', 'surface' => '#ffffff', 'text' => '#d4d4d8', 'accent' => '#0284c7'],
        'dark' => ['chrome' => '#09090b', 'surface' => '#18181b', 'text' => '#3f3f46', 'accent' => '#0284c7'],
        'classic' => ['chrome' => '#e7ecf2', 'surface' => '#ffffff', 'text' => '#dde4ea', 'accent' => '#0f62b3'],
    ];
    $left = $palettes[$theme] ?? $palettes['light'];
    $right = $theme === 'auto' ? $palettes['dark'] : $left;
@endphp
<span class="block h-12 w-full overflow-hidden rounded border border-black/10">
    <span class="flex h-full w-full">
        <span class="relative block h-full w-1/2 overflow-hidden" style="background: {{ $left['surface'] }}">
            <span class="absolute inset-x-0 top-0 block h-3" style="background: {{ $left['chrome'] }}"></span>
            <span class="absolute bottom-1.5 left-1.5 block h-1.5 w-4 rounded-sm" style="background: {{ $left['text'] }}"></span>
            <span class="absolute bottom-1.5 right-1.5 block size-2 rounded-full" style="background: {{ $left['accent'] }}"></span>
        </span>
        <span class="relative block h-full w-1/2 overflow-hidden border-l border-black/10" style="background: {{ $right['surface'] }}">
            <span class="absolute inset-x-0 top-0 block h-3" style="background: {{ $right['chrome'] }}"></span>
            <span class="absolute bottom-1.5 left-1.5 block h-1.5 w-4 rounded-sm" style="background: {{ $right['text'] }}"></span>
            <span class="absolute bottom-1.5 right-1.5 block size-2 rounded-full" style="background: {{ $right['accent'] }}"></span>
        </span>
    </span>
</span>
