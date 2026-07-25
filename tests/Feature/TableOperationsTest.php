<?php

use App\Livewire\ObjectExplorer;
use App\Services\ConnectionManager;
use Livewire\Livewire;

beforeEach(function () {
    if (! testDatabaseAvailable()) {
        $this->markTestSkipped('Test database container not running');
    }

    $this->connection = makeTestConnection();
    $this->db = app(ConnectionManager::class)->db($this->connection, 'demo');
    $this->db->statement('CREATE TABLE IF NOT EXISTS demo.ops_target (id INT PRIMARY KEY)');
});

afterEach(function () {
    $this->db?->statement('DROP TABLE IF EXISTS demo.ops_target');
    $this->db?->statement('DROP TABLE IF EXISTS demo.ops_renamed');
});

it('requires typing the exact table name before dropping', function () {
    Livewire::test(ObjectExplorer::class, ['connectionId' => $this->connection->id])
        ->call('startOperation', 'drop', 'demo', 'ops_target')
        ->set('operation.input', 'wrong_name')
        ->call('executeOperation')
        ->assertSet('error', 'Type the exact name to confirm the drop.');

    expect($this->db->select("SHOW TABLES FROM demo LIKE 'ops_target'"))->toHaveCount(1);
});

it('drops a table after correct confirmation', function () {
    Livewire::test(ObjectExplorer::class, ['connectionId' => $this->connection->id])
        ->call('startOperation', 'drop', 'demo', 'ops_target')
        ->set('operation.input', 'ops_target')
        ->call('executeOperation')
        ->assertSet('operation', null);

    expect($this->db->select("SHOW TABLES FROM demo LIKE 'ops_target'"))->toHaveCount(0);
});

it('clears the active table highlight when it is dropped', function () {
    Livewire::test(ObjectExplorer::class, ['connectionId' => $this->connection->id])
        ->call('selectTable', 'demo', 'ops_target')
        ->assertSet('activeTable', 'demo.ops_target')
        ->call('startOperation', 'drop', 'demo', 'ops_target')
        ->set('operation.input', 'ops_target')
        ->call('executeOperation')
        ->assertSet('activeTable', null);
});

it('renames a table', function () {
    Livewire::test(ObjectExplorer::class, ['connectionId' => $this->connection->id])
        ->call('startOperation', 'rename', 'demo', 'ops_target')
        ->set('operation.input', 'ops_renamed')
        ->call('executeOperation')
        ->assertSet('operation', null);

    expect($this->db->select("SHOW TABLES FROM demo LIKE 'ops_renamed'"))->toHaveCount(1);
});

it('truncates a table', function () {
    $this->db->statement('INSERT INTO demo.ops_target VALUES (1), (2)');

    Livewire::test(ObjectExplorer::class, ['connectionId' => $this->connection->id])
        ->call('startOperation', 'truncate', 'demo', 'ops_target')
        ->call('executeOperation')
        ->assertSet('operation', null);

    expect($this->db->selectOne('SELECT COUNT(*) AS n FROM demo.ops_target')->n)->toBe(0);
});
