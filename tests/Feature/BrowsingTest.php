<?php

use App\Livewire\ObjectExplorer;
use App\Livewire\ResultsPanel;
use App\Livewire\Workspace;
use App\Services\QueryRunner;
use App\Services\SchemaExplorer;
use Livewire\Livewire;

beforeEach(function () {
    if (! testDatabaseAvailable()) {
        $this->markTestSkipped('Test database container not running');
    }

    $this->connection = makeTestConnection();
});

it('opens and closes connection tabs', function () {
    Livewire::test(Workspace::class)
        ->dispatch('open-connection', id: $this->connection->id)
        ->assertSet('activeTabId', $this->connection->id)
        ->assertCount('openTabs', 1)
        ->call('closeTab', $this->connection->id)
        ->assertSet('activeTabId', null)
        ->assertCount('openTabs', 0);
});

it('lists databases and lazily loads tables', function () {
    Livewire::test(ObjectExplorer::class, ['connectionId' => $this->connection->id])
        ->assertSee('demo')
        ->call('toggleDatabase', 'demo')
        ->assertSee('customers')
        ->assertSee('orders')
        ->assertSee('paid_orders');
});

it('tracks which table is active in the tree', function () {
    Livewire::test(ObjectExplorer::class, ['connectionId' => $this->connection->id])
        ->call('toggleDatabase', 'demo')
        ->assertSet('activeTable', null)
        ->call('selectTable', 'demo', 'customers')
        ->assertSet('activeTable', 'demo.customers')
        ->call('selectTable', 'demo', 'orders')
        ->assertSet('activeTable', 'demo.orders');
});

it('navigates between tables with the arrow keys, tables then views', function () {
    Livewire::test(ObjectExplorer::class, ['connectionId' => $this->connection->id])
        ->call('toggleDatabase', 'demo')
        ->call('selectTable', 'demo', 'customers')
        ->call('navigateTable', 'down')
        ->assertSet('activeTable', 'demo.orders')
        ->call('navigateTable', 'down')
        ->assertSet('activeTable', 'demo.paid_orders')
        // Past the last item: no-op.
        ->call('navigateTable', 'down')
        ->assertSet('activeTable', 'demo.paid_orders')
        ->call('navigateTable', 'up')
        ->assertSet('activeTable', 'demo.orders')
        ->call('navigateTable', 'up')
        ->assertSet('activeTable', 'demo.customers')
        // Past the first item: no-op.
        ->call('navigateTable', 'up')
        ->assertSet('activeTable', 'demo.customers');
});

it('does nothing to navigate when no table is active yet', function () {
    Livewire::test(ObjectExplorer::class, ['connectionId' => $this->connection->id])
        ->call('toggleDatabase', 'demo')
        ->call('navigateTable', 'down')
        ->assertSet('activeTable', null);
});

it('restricts the tree to a single database when one is configured', function () {
    $restricted = makeTestConnection(['database' => 'demo']);

    expect(app(SchemaExplorer::class)->databases($restricted))->toBe(['demo']);

    Livewire::test(ObjectExplorer::class, ['connectionId' => $restricted->id])
        ->assertSee('demo')
        ->assertSet('databases', ['demo'])
        // The one visible database auto-expands, no click needed.
        ->assertSee('customers');
});

it('filters tables with the search box', function () {
    Livewire::test(ObjectExplorer::class, ['connectionId' => $this->connection->id])
        ->call('toggleDatabase', 'demo')
        ->set('search', 'cust')
        ->assertSee('customers')
        ->assertDontSee('orders');
});

it('shows table data with paging and sorting', function () {
    Livewire::test(ResultsPanel::class, ['connectionId' => $this->connection->id])
        ->dispatch('table-selected', connectionId: $this->connection->id, database: 'demo', table: 'customers')
        ->assertSet('activeTab', 'data')
        ->assertSee('alice@example.com')
        ->assertSee('(NULL)')
        ->call('sortBy', 'name')
        ->assertSet('sortColumn', 'name')
        ->call('sortBy', 'name')
        ->assertSet('sortDirection', 'desc');
});

it('shows column, index and DDL info', function () {
    Livewire::test(ResultsPanel::class, ['connectionId' => $this->connection->id])
        ->dispatch('table-selected', connectionId: $this->connection->id, database: 'demo', table: 'orders')
        ->set('activeTab', 'info')
        ->assertSee('customer_id')
        ->assertSee('PRIMARY')
        ->assertSee('CREATE TABLE');
});

it('reads schema metadata through the SchemaExplorer service', function () {
    $explorer = app(SchemaExplorer::class);

    expect($explorer->databases($this->connection))->toContain('demo');

    $tables = collect($explorer->tables($this->connection, 'demo'));
    expect($tables->firstWhere('name', 'customers')['type'])->toBe('table')
        ->and($tables->firstWhere('name', 'paid_orders')['type'])->toBe('view');

    expect($explorer->primaryKey($this->connection, 'demo', 'orders'))->toBe(['id'])
        ->and($explorer->ddl($this->connection, 'demo', 'customers'))->toContain('CREATE TABLE');

    $indexes = collect($explorer->indexes($this->connection, 'demo', 'orders'));
    expect($indexes->firstWhere('name', 'PRIMARY')['unique'])->toBeTrue();
});

it('formats NULL, blob and long text values for the grid', function () {
    $runner = app(QueryRunner::class);
    $result = $runner->run($this->connection, 'demo', 'SELECT * FROM customers ORDER BY id');

    expect($result['ok'])->toBeTrue()
        ->and($result['row_count'])->toBe(3);

    $bob = $result['rows'][1];
    expect($bob['notes'])->toBeArray()->and($bob['notes']['blob'])->toBeFalse();
    expect($bob['avatar'])->toBeArray()->and($bob['avatar']['blob'])->toBeTrue();

    $carol = $result['rows'][2];
    expect($carol['email'])->toBeNull()
        ->and($carol['notes'])->toBe('short note');
});
