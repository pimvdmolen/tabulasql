<?php

use App\Livewire\QueryEditor;
use App\Models\QueryHistory;
use Livewire\Livewire;

beforeEach(function () {
    if (! testDatabaseAvailable()) {
        $this->markTestSkipped('Test database container not running');
    }

    $this->connection = makeTestConnection();
});

it('manages query tabs', function () {
    Livewire::test(QueryEditor::class, ['connectionId' => $this->connection->id])
        ->assertCount('tabs', 1)
        ->call('addTab')
        ->assertCount('tabs', 2)
        ->assertSet('activeTab', 2)
        ->call('updateSql', 2, 'SELECT 1')
        ->assertSet('tabs.1.sql', 'SELECT 1')
        ->call('closeTab', 2)
        ->assertCount('tabs', 1)
        ->assertSet('activeTab', 1)
        ->call('closeTab', 1)
        ->assertCount('tabs', 1);
});

it('runs multiple statements and reports results', function () {
    Livewire::test(QueryEditor::class, ['connectionId' => $this->connection->id])
        ->call('setDatabase', $this->connection->id, 'demo')
        ->call('run', "SELECT name FROM customers ORDER BY id; SELECT COUNT(*) AS total FROM orders;")
        ->assertDispatched('query-result')
        ->assertDispatched('log');

    expect(QueryHistory::where('connection_id', $this->connection->id)->count())->toBe(2);
});

it('reports errors per failing statement and continues', function () {
    Livewire::test(QueryEditor::class, ['connectionId' => $this->connection->id])
        ->call('setDatabase', $this->connection->id, 'demo')
        ->call('run', 'SELECT * FROM does_not_exist; SELECT 1 AS ok;')
        ->assertDispatched('log', type: 'error')
        ->assertDispatched('query-result');
});

it('surfaces the error in Table Data when the query fails outright', function () {
    Livewire::test(QueryEditor::class, ['connectionId' => $this->connection->id])
        ->call('setDatabase', $this->connection->id, 'demo')
        ->call('run', 'SELECT * FROM does_not_exist;')
        ->assertDispatched('log', type: 'error')
        ->assertDispatched('query-result');
});

it('injects a limit into unlimited selects when enabled', function () {
    Livewire::test(QueryEditor::class, ['connectionId' => $this->connection->id])
        ->call('setDatabase', $this->connection->id, 'demo')
        ->call('run', 'SELECT * FROM customers');

    expect(QueryHistory::latest('id')->first()->query)->toContain('LIMIT 1000');
});

it('explains the first statement', function () {
    Livewire::test(QueryEditor::class, ['connectionId' => $this->connection->id])
        ->call('setDatabase', $this->connection->id, 'demo')
        ->call('explain', 'SELECT * FROM orders WHERE customer_id = 1')
        ->assertDispatched('query-result');
});

it('pushes the schema for autocompletion', function () {
    Livewire::test(QueryEditor::class, ['connectionId' => $this->connection->id])
        ->call('setDatabase', $this->connection->id, 'demo')
        ->assertDispatched('sql-schema');
});

it('searches history and dispatches inserts', function () {
    QueryHistory::create([
        'connection_id' => $this->connection->id,
        'database' => 'demo',
        'query' => 'SELECT * FROM customers',
        'duration_ms' => 3,
        'rows_affected' => 3,
        'executed_at' => now(),
    ]);

    Livewire::test(QueryEditor::class, ['connectionId' => $this->connection->id])
        ->set('showHistory', true)
        ->set('historySearch', 'customers')
        ->assertSee('SELECT * FROM customers')
        ->call('insertFromHistory', QueryHistory::first()->id)
        ->assertDispatched('sql-insert');
});
