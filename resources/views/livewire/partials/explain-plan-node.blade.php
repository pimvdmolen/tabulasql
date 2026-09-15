<li class="list-none" style="margin-left: {{ $depth * 14 }}px">
    <div class="border-l border-edge py-0.5 pl-2">
        <div class="font-mono text-[0.75rem] font-medium text-strong">{{ $node['label'] ?? 'node' }}</div>
        @if (! empty($node['detail']))
            <div class="font-mono text-[0.68rem] text-muted">{{ $node['detail'] }}</div>
        @endif
    </div>
    @foreach ($node['children'] ?? [] as $child)
        @include('livewire.partials.explain-plan-node', ['node' => $child, 'depth' => $depth + 1])
    @endforeach
</li>
