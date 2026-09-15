@php
    $plan = $this->explainPlan;
@endphp
@if ($plan !== null)
    <div class="min-h-0 flex-1 overflow-auto p-2">
        @if ($plan['mode'] === 'tree')
            <ul class="space-y-0.5">
                @foreach ($plan['nodes'] ?? [] as $node)
                    @include('livewire.partials.explain-plan-node', ['node' => $node, 'depth' => 0])
                @endforeach
            </ul>
        @else
            <table class="w-full border-collapse text-left font-mono text-[0.72rem]">
                <thead class="sticky top-0 bg-chrome">
                    <tr>
                        @foreach ($plan['columns'] ?? [] as $col)
                            <th class="border border-grid px-2 py-1 font-semibold text-dim">{{ $col }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($plan['rows'] ?? [] as $row)
                        <tr class="hover:bg-raised/50">
                            @foreach ($plan['columns'] ?? [] as $col)
                                <td class="max-w-xs truncate border border-grid px-2 py-1 text-body" title="{{ $row[$col] ?? '' }}">{{ $row[$col] ?? '' }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endif
