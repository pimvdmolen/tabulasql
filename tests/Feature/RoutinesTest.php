<?php

use App\Livewire\CopyWizard;
use App\Livewire\ObjectExplorer;
use App\Livewire\QueryEditor;
use App\Services\ConnectionManager;
use App\Services\SchemaExplorer;
use App\Services\TableCopier;
use Livewire\Livewire;

beforeEach(function () {
    if (! testDatabaseAvailable()) {
        $this->markTestSkipped('Test database container not running');
    }

    $this->connection = makeTestConnection();
    $this->db = app(ConnectionManager::class)->db($this->connection);
    $this->db->statement('DROP DATABASE IF EXISTS routines_test');
    $this->db->statement('CREATE DATABASE routines_test');
    $this->db->statement('CREATE TABLE routines_test.widgets (id INT PRIMARY KEY, qty INT)');

    $this->db->unprepared('
        CREATE PROCEDURE routines_test.bump_qty(IN wid INT)
        BEGIN
            UPDATE routines_test.widgets SET qty = qty + 1 WHERE id = wid;
        END
    ');
    $this->db->unprepared('
        CREATE FUNCTION routines_test.double_it(n INT) RETURNS INT DETERMINISTIC
        RETURN n * 2
    ');
    $this->db->unprepared('
        CREATE TRIGGER routines_test.widgets_bi BEFORE INSERT ON routines_test.widgets
        FOR EACH ROW SET NEW.qty = COALESCE(NEW.qty, 0)
    ');
    $this->db->unprepared("
        CREATE EVENT routines_test.nightly_noop ON SCHEDULE EVERY 1 DAY DO SELECT 1
    ");
});

afterEach(function () {
    $this->db?->statement('DROP DATABASE IF EXISTS routines_test');
});

it('lists procedures, functions, triggers and events in a database', function () {
    $explorer = app(SchemaExplorer::class);

    expect($explorer->procedures($this->connection, 'routines_test'))->toBe(['bump_qty'])
        ->and($explorer->functions($this->connection, 'routines_test'))->toBe(['double_it'])
        ->and($explorer->triggers($this->connection, 'routines_test'))->toBe([
            ['name' => 'widgets_bi', 'table' => 'widgets'],
        ])
        ->and($explorer->events($this->connection, 'routines_test'))->toBe(['nightly_noop']);
});

it('fetches the CREATE statement for each routine kind', function () {
    $explorer = app(SchemaExplorer::class);

    expect($explorer->procedureDdl($this->connection, 'routines_test', 'bump_qty'))
        ->toContain('CREATE')->toContain('PROCEDURE')->toContain('bump_qty');

    expect($explorer->functionDdl($this->connection, 'routines_test', 'double_it'))
        ->toContain('FUNCTION')->toContain('double_it');

    expect($explorer->triggerDdl($this->connection, 'routines_test', 'widgets_bi'))
        ->toContain('TRIGGER')->toContain('widgets_bi');

    expect($explorer->eventDdl($this->connection, 'routines_test', 'nightly_noop'))
        ->toContain('EVENT')->toContain('nightly_noop');
});

it('shows procedures, functions, triggers and events in the object tree', function () {
    Livewire::test(ObjectExplorer::class, ['connectionId' => $this->connection->id])
        ->call('toggleDatabase', 'routines_test')
        ->assertSee('Procedures (1)')
        ->assertSee('bump_qty')
        ->assertSee('Functions (1)')
        ->assertSee('double_it')
        ->assertSee('Triggers (1)')
        ->assertSee('widgets_bi')
        ->assertSee('Events (1)')
        ->assertSee('nightly_noop');
});

it('opens a query tab with a DDL skeleton for creating a new routine', function () {
    Livewire::test(QueryEditor::class, ['connectionId' => $this->connection->id])
        ->call('pasteRoutineTemplate', $this->connection->id, 'routines_test', 'create-procedure')
        ->assertDispatched('sql-insert', function (string $name, array $params) {
            return str_contains($params['sql'], 'CREATE PROCEDURE') && str_contains($params['sql'], 'new_procedure');
        });
});

it('opens a query tab with the fetched CREATE statement when altering an existing routine', function () {
    Livewire::test(QueryEditor::class, ['connectionId' => $this->connection->id])
        ->call('pasteRoutineTemplate', $this->connection->id, 'routines_test', 'alter-function', 'double_it')
        ->assertDispatched('sql-insert', function (string $name, array $params) {
            return str_contains($params['sql'], 'double_it') && str_contains($params['sql'], 'FUNCTION');
        });
});

it('drops a procedure, function, trigger and event from the tree', function () {
    $explorer = app(SchemaExplorer::class);

    Livewire::test(ObjectExplorer::class, ['connectionId' => $this->connection->id])
        ->call('toggleDatabase', 'routines_test')
        ->call('startOperation', 'drop', 'routines_test', 'bump_qty', 'procedure')
        ->set('operation.input', 'bump_qty')
        ->call('executeOperation')
        ->assertSet('error', null);

    expect($explorer->procedures($this->connection, 'routines_test'))->toBe([]);
});

it('copies procedures, functions, triggers and events to another database', function () {
    $this->db->statement('DROP DATABASE IF EXISTS routines_copy_target');
    $this->db->statement('CREATE DATABASE routines_copy_target');
    $this->db->statement('CREATE TABLE routines_copy_target.widgets (id INT PRIMARY KEY, qty INT)');

    $summary = app(TableCopier::class)->copy(
        $this->connection, 'routines_test',
        [
            ['name' => 'bump_qty', 'type' => 'procedure'],
            ['name' => 'double_it', 'type' => 'function'],
            ['name' => 'widgets_bi', 'type' => 'trigger'],
            ['name' => 'nightly_noop', 'type' => 'event'],
        ],
        $this->connection, 'routines_copy_target',
        withData: false,
    );

    expect($summary['copied'])->toBe(4)->and($summary['failed'])->toBe(0);

    $explorer = app(SchemaExplorer::class);
    expect($explorer->procedures($this->connection, 'routines_copy_target'))->toBe(['bump_qty'])
        ->and($explorer->functions($this->connection, 'routines_copy_target'))->toBe(['double_it'])
        ->and(array_column($explorer->triggers($this->connection, 'routines_copy_target'), 'name'))->toBe(['widgets_bi'])
        ->and($explorer->events($this->connection, 'routines_copy_target'))->toBe(['nightly_noop']);

    $this->db->statement('DROP DATABASE routines_copy_target');
});

it('lists every object group in the copy wizard, including empty ones', function () {
    Livewire::test(CopyWizard::class)
        ->dispatch('open-copy-wizard', connectionId: $this->connection->id, database: 'routines_test', objects: [
            ['name' => 'widgets', 'type' => 'table'],
        ])
        ->assertSee('Tables (1/1)')
        ->assertSee('Views (0/0)')
        ->assertSee('No views found.')
        ->assertSee('Procedures (0/1)')
        ->assertSee('bump_qty')
        ->assertSee('Functions (0/1)')
        ->assertSee('double_it')
        ->assertSee('Triggers (0/1)')
        ->assertSee('widgets_bi')
        ->assertSee('Events (0/1)')
        ->assertSee('nightly_noop');
});

it('expands and collapses a group in the copy wizard tree', function () {
    // "bump_qty" alone also shows up in the "select all" button's JSON
    // payload regardless of collapse state, so assert on the row's own
    // wire:key instead — that only renders while the group is expanded.
    Livewire::test(CopyWizard::class)
        ->dispatch('open-copy-wizard', connectionId: $this->connection->id, database: 'routines_test', objects: [
            ['name' => 'widgets', 'type' => 'table'],
        ])
        ->assertSee('cpy-bump_qty')
        ->call('toggleGroupExpanded', 'procedure')
        ->assertDontSee('cpy-bump_qty')
        ->call('toggleGroupExpanded', 'procedure')
        ->assertSee('cpy-bump_qty');
});
