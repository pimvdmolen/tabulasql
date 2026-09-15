<?php

namespace App\Livewire;

use App\Models\Connection;
use App\Models\SavedQuery;
use App\Services\SchemaExplorer;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class CommandPalette extends Component
{
    public bool $open = false;

    public string $query = '';

    public int $activeConnectionId = 0;

    public ?string $activeDatabase = null;

    /** @var array<int, array{type: string, label: string, hint: string, action: string, payload: array}> */
    public array $items = [];

    public int $highlight = 0;

    #[On('open-command-palette')]
    public function openPalette(?int $connectionId = null, ?string $database = null): void
    {
        $this->activeConnectionId = $connectionId ?? $this->activeConnectionId;
        $this->activeDatabase = $database ?? $this->activeDatabase;
        $this->query = '';
        $this->highlight = 0;
        $this->rebuild();
        $this->open = true;
    }

    #[On('workspace-context')]
    public function setContext(int $connectionId, ?string $database = null): void
    {
        $this->activeConnectionId = $connectionId;
        $this->activeDatabase = ($database !== null && $database !== '') ? $database : null;
    }

    public function updatedQuery(): void
    {
        $this->highlight = 0;
        $this->rebuild();
    }

    public function moveHighlight(int $delta): void
    {
        $count = count($this->items);

        if ($count === 0) {
            return;
        }

        $this->highlight = ($this->highlight + $delta + $count) % $count;
    }

    public function runHighlighted(): void
    {
        if (! isset($this->items[$this->highlight])) {
            return;
        }

        $this->runItem($this->highlight);
    }

    public function runItem(int $index): void
    {
        $item = $this->items[$index] ?? null;

        if ($item === null) {
            return;
        }

        $this->open = false;
        $payload = $item['payload'];

        match ($item['action']) {
            'select-table' => $this->dispatch(
                'table-selected',
                connectionId: $payload['connectionId'],
                database: $payload['database'],
                table: $payload['table'],
            ),
            'open-query-tab' => $this->dispatch(
                'open-in-query-tab',
                connectionId: $payload['connectionId'],
                database: $payload['database'],
                table: $payload['table'],
            ),
            'insert-saved' => $this->dispatch(
                'sql-insert',
                connectionId: $payload['connectionId'],
                sql: $payload['sql'],
            ),
            'ai-prompt' => $this->dispatch('open-ai-prompt', connectionId: $this->activeConnectionId),
            'er-diagram' => $this->dispatch(
                'open-er-diagram',
                connectionId: $payload['connectionId'],
                database: $payload['database'],
            ),
            'user-manager' => $this->dispatch('open-user-manager', connectionId: $payload['connectionId']),
            'ai-settings' => $this->dispatch('open-ai-settings'),
            default => null,
        };
    }

    public function close(): void
    {
        $this->open = false;
    }

    private function rebuild(): void
    {
        $items = [];
        $needle = mb_strtolower(trim($this->query));

        $items[] = [
            'type' => 'action',
            'label' => 'Ask AI for SQL…',
            'hint' => 'AI',
            'action' => 'ai-prompt',
            'payload' => [],
        ];
        $items[] = [
            'type' => 'action',
            'label' => 'AI settings…',
            'hint' => 'Settings',
            'action' => 'ai-settings',
            'payload' => [],
        ];

        if ($this->activeConnectionId > 0) {
            $connection = Connection::find($this->activeConnectionId);

            if ($connection !== null) {
                if ($connection->isMysql()) {
                    $items[] = [
                        'type' => 'action',
                        'label' => 'Manage MySQL users…',
                        'hint' => 'Users',
                        'action' => 'user-manager',
                        'payload' => ['connectionId' => $connection->id],
                    ];
                }

                $database = $this->activeDatabase
                    ?? $connection->default_database
                    ?? $connection->database;

                if ($database) {
                    $items[] = [
                        'type' => 'action',
                        'label' => 'ER diagram for '.$database,
                        'hint' => 'Schema',
                        'action' => 'er-diagram',
                        'payload' => ['connectionId' => $connection->id, 'database' => $database],
                    ];

                    try {
                        foreach (app(SchemaExplorer::class)->tableNames($connection, $database) as $table) {
                            $items[] = [
                                'type' => 'table',
                                'label' => $database.'.'.$table,
                                'hint' => 'Open table',
                                'action' => 'select-table',
                                'payload' => [
                                    'connectionId' => $connection->id,
                                    'database' => $database,
                                    'table' => $table,
                                ],
                            ];
                            $items[] = [
                                'type' => 'table',
                                'label' => 'Query '.$database.'.'.$table,
                                'hint' => 'New query',
                                'action' => 'open-query-tab',
                                'payload' => [
                                    'connectionId' => $connection->id,
                                    'database' => $database,
                                    'table' => $table,
                                ],
                            ];
                        }
                    } catch (Throwable) {
                        // Palette still useful for actions.
                    }

                    foreach (SavedQuery::where('connection_id', $connection->id)->latest()->limit(40)->get() as $saved) {
                        $items[] = [
                            'type' => 'saved',
                            'label' => $saved->title,
                            'hint' => 'Saved query',
                            'action' => 'insert-saved',
                            'payload' => [
                                'connectionId' => $connection->id,
                                'sql' => $saved->sql,
                            ],
                        ];
                    }
                }
            }
        }

        if ($needle !== '') {
            $items = array_values(array_filter(
                $items,
                fn (array $item) => str_contains(mb_strtolower($item['label']), $needle)
                    || str_contains(mb_strtolower($item['hint']), $needle)
            ));
        }

        $this->items = array_slice($items, 0, 80);
        if ($this->highlight >= count($this->items)) {
            $this->highlight = 0;
        }
    }

    public function render()
    {
        return view('livewire.command-palette');
    }
}
