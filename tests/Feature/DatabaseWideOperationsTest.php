<?php

use App\Livewire\ObjectExplorer;
use App\Services\ConnectionManager;
use App\Services\SchemaExplorer;
use Livewire\Livewire;

beforeEach(function () {
    if (! testDatabaseAvailable()) {
        $this->markTestSkipped('Test database container not running');
    }

    $this->connection = makeTestConnection();
    $this->db = app(ConnectionManager::class)->db($this->connection);
    $this->db->statement('DROP DATABASE IF EXISTS wipe_test');
    $this->db->statement('CREATE DATABASE wipe_test');
    $this->db->statement('CREATE TABLE wipe_test.a (id INT PRIMARY KEY)');
    $this->db->statement('CREATE TABLE wipe_test.b (id INT PRIMARY KEY)');
    $this->db->statement('INSERT INTO wipe_test.a VALUES (1), (2)');
    $this->db->statement('INSERT INTO wipe_test.b VALUES (1)');
    $this->db->statement('CREATE VIEW wipe_test.a_view AS SELECT * FROM wipe_test.a');
    $this->db->unprepared('CREATE PROCEDURE wipe_test.noop() BEGIN END');
});

afterEach(function () {
    $this->db?->statement('DROP DATABASE IF EXISTS wipe_test');
});

it('truncates every table in a database but keeps structure and routines', function () {
    Livewire::test(ObjectExplorer::class, ['connectionId' => $this->connection->id])
        ->call('toggleDatabase', 'wipe_test')
        ->call('startOperation', 'truncate-database', 'wipe_test', null)
        ->set('operation.input', 'wipe_test')
        ->call('executeOperation')
        ->assertSet('error', null)
        ->assertSet('operation', null);

    expect($this->db->selectOne('SELECT COUNT(*) AS n FROM wipe_test.a')->n)->toBe(0)
        ->and($this->db->selectOne('SELECT COUNT(*) AS n FROM wipe_test.b')->n)->toBe(0)
        ->and(app(SchemaExplorer::class)->procedures($this->connection, 'wipe_test'))->toBe(['noop']);

    $tables = array_column(app(SchemaExplorer::class)->tables($this->connection, 'wipe_test'), 'name');
    expect($tables)->toContain('a')->toContain('b')->toContain('a_view');
});

it('empties a database of every table, view and routine', function () {
    Livewire::test(ObjectExplorer::class, ['connectionId' => $this->connection->id])
        ->call('toggleDatabase', 'wipe_test')
        ->call('startOperation', 'empty-database', 'wipe_test', null)
        ->set('operation.input', 'wipe_test')
        ->call('executeOperation')
        ->assertSet('error', null)
        ->assertSet('operation', null);

    expect(app(SchemaExplorer::class)->tables($this->connection, 'wipe_test'))->toBe([])
        ->and(app(SchemaExplorer::class)->procedures($this->connection, 'wipe_test'))->toBe([]);

    // The database itself survives; only its contents are gone.
    expect(app(SchemaExplorer::class)->databases($this->connection))->toContain('wipe_test');
});

it('requires typing the exact database name before a database-wide wipe', function () {
    Livewire::test(ObjectExplorer::class, ['connectionId' => $this->connection->id])
        ->call('startOperation', 'empty-database', 'wipe_test', null)
        ->set('operation.input', 'wrong_name')
        ->call('executeOperation')
        ->assertSet('error', 'Type the exact database name to confirm.');

    expect(app(SchemaExplorer::class)->tables($this->connection, 'wipe_test'))->not->toBe([]);
});
