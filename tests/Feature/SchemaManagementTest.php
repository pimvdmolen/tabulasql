<?php

use App\Livewire\CreateTableDialog;
use App\Livewire\ForeignKeyManager;
use App\Livewire\IndexManager;
use App\Services\ConnectionManager;
use Livewire\Livewire;

beforeEach(function () {
    if (! testDatabaseAvailable()) {
        $this->markTestSkipped('Test database container not running');
    }

    $this->connection = makeTestConnection();
    $this->db = app(ConnectionManager::class)->db($this->connection);
    $this->db->statement('DROP TABLE IF EXISTS demo.schema_scratch');
});

afterEach(function () {
    $this->db?->statement('DROP TABLE IF EXISTS demo.schema_scratch');
});

it('creates a table through the dialog', function () {
    Livewire::test(CreateTableDialog::class)
        ->dispatch('open-create-table', connectionId: $this->connection->id, database: 'demo')
        ->set('tableName', 'schema_scratch')
        ->call('addColumn')
        ->set('columns.1.name', 'title')
        ->set('columns.1.type', 'VARCHAR(100)')
        ->set('columns.1.nullable', false)
        ->call('create')
        ->assertSet('context', null)
        ->assertDispatched('schema-changed');

    $ddl = $this->db->selectOne('SHOW CREATE TABLE demo.schema_scratch');
    $create = ((array) $ddl)['Create Table'];

    expect($create)->toContain('`id` int')
        ->and($create)->toContain('AUTO_INCREMENT')
        ->and($create)->toContain('PRIMARY KEY (`id`)')
        ->and($create)->toContain('`title` varchar(100) NOT NULL');
});

it('adds and drops indexes', function () {
    $this->db->statement('CREATE TABLE demo.schema_scratch (id INT PRIMARY KEY, email VARCHAR(100), city VARCHAR(50))');

    $component = Livewire::test(IndexManager::class)
        ->dispatch('open-index-manager', connectionId: $this->connection->id, database: 'demo', table: 'schema_scratch')
        ->set('newName', 'idx_email_city')
        ->set('newColumns', ['email', 'city'])
        ->set('newUnique', true)
        ->call('addIndex')
        ->assertSet('error', null);

    $names = array_column($component->get('indexes'), 'name');
    expect($names)->toContain('idx_email_city');

    $component->call('dropIndex', 'idx_email_city');
    expect(array_column($component->get('indexes'), 'name'))->not->toContain('idx_email_city');
});

it('adds and drops foreign keys', function () {
    $this->db->statement('CREATE TABLE demo.schema_scratch (id INT PRIMARY KEY, customer_id INT)');

    $component = Livewire::test(ForeignKeyManager::class)
        ->dispatch('open-fk-manager', connectionId: $this->connection->id, database: 'demo', table: 'schema_scratch')
        ->set('newColumn', 'customer_id')
        ->set('newRefTable', 'customers')
        ->set('newRefColumn', 'id')
        ->set('onDelete', 'CASCADE')
        ->call('addForeignKey')
        ->assertSet('error', null);

    $constraints = $component->get('constraints');
    expect($constraints)->toHaveCount(1)
        ->and($constraints[0]['ref_table'])->toBe('customers')
        ->and($constraints[0]['on_delete'])->toBe('CASCADE');

    $component->call('dropForeignKey', $constraints[0]['name']);
    expect($component->get('constraints'))->toBe([]);
});
