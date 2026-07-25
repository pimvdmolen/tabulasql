@php
    $menu = $kind === 'trigger' ? 'treeTriggerMenu' : 'treeRoutineMenu';
@endphp
<div
    wire:key="rtn-{{ $kind }}-{{ $database }}-{{ $name }}"
    x-on:contextmenu.prevent="$store.ctx.open($event, window.{{ $menu }}($wire, { connectionId: {{ $connectionId }}, database: @js($database), name: @js($name), kind: @js($kind) }))"
    class="flex min-w-0 items-center gap-1.5 rounded px-1 py-0.5 text-left text-body hover:bg-raised"
    title="{{ $name }}{{ isset($subtitle) ? " on `$subtitle`" : '' }}"
>
    <span class="w-3 shrink-0"></span>
    <span class="shrink-0 text-amber-600/80 dark:text-amber-400/80">{{ $icon }}</span>
    <span class="truncate">{{ $name }}</span>
    @isset($subtitle)
        <span class="ml-auto shrink-0 truncate pl-1 text-[0.72rem] text-faint">on `{{ $subtitle }}`</span>
    @endisset
</div>
