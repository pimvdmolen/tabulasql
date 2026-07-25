<?php

use App\Livewire\CopyWizard;
use App\Services\ConnectionManager;
use App\Services\TableCopier;
use Livewire\Livewire;

beforeEach(function () {
    if (! testDatabaseAvailable()) {
        $this->markTestSkipped('Test database container not running');
    }

    $this->connection = makeTestConnection();
    $this->db = app(ConnectionManager::class)->db($this->connection);
    $this->db->statement('DROP DATABASE IF EXISTS copy_target');
    $this->db->statement('CREATE DATABASE copy_target');
});

afterEach(function () {
    $this->db?->statement('DROP DATABASE IF EXISTS copy_target');
    $this->db?->statement('DROP DATABASE IF EXISTS copy_source_wide');
    $this->db?->statement('SET GLOBAL max_allowed_packet = 16777216');
});

it('copies tables and views with data between databases', function () {
    $progress = [];

    $summary = app(TableCopier::class)->copy(
        $this->connection, 'demo',
        [
            ['name' => 'paid_orders', 'type' => 'view'],
            ['name' => 'customers', 'type' => 'table'],
            ['name' => 'orders', 'type' => 'table'],
        ],
        $this->connection, 'copy_target',
        withData: true,
        conflict: 'skip',
        progress: function (string $message) use (&$progress) {
            $progress[] = $message;
        },
    );

    expect($summary['copied'])->toBe(3)
        ->and($summary['failed'])->toBe(0)
        ->and($summary['rows'])->toBeGreaterThanOrEqual(6);

    expect($this->db->selectOne('SELECT COUNT(*) AS n FROM copy_target.customers')->n)->toBe(3)
        ->and($this->db->selectOne('SELECT COUNT(*) AS n FROM copy_target.orders')->n)->toBe(3)
        ->and($this->db->select('SELECT * FROM copy_target.paid_orders'))->not->toBeEmpty();

    // Binary data survives the copy.
    $avatar = $this->db->selectOne('SELECT HEX(avatar) AS h FROM copy_target.customers WHERE name = "Bob"')->h;
    expect($avatar)->toBe('89504E470D0A1A0A0000000D49484452');

    expect($progress)->not->toBeEmpty();
});

it('skips or drops existing objects depending on the conflict mode', function () {
    $copier = app(TableCopier::class);
    $objects = [['name' => 'customers', 'type' => 'table']];

    $copier->copy($this->connection, 'demo', $objects, $this->connection, 'copy_target', false, 'skip');

    $skipRun = $copier->copy($this->connection, 'demo', $objects, $this->connection, 'copy_target', false, 'skip');
    expect($skipRun['skipped'])->toBe(1)->and($skipRun['copied'])->toBe(0);

    $dropRun = $copier->copy($this->connection, 'demo', $objects, $this->connection, 'copy_target', true, 'drop');
    expect($dropRun['copied'])->toBe(1)
        ->and($this->db->selectOne('SELECT COUNT(*) AS n FROM copy_target.customers')->n)->toBe(3);
});

it('reports per-object failures without stopping the run', function () {
    $summary = app(TableCopier::class)->copy(
        $this->connection, 'demo',
        [
            ['name' => 'ghost_table', 'type' => 'table'],
            ['name' => 'customers', 'type' => 'table'],
        ],
        $this->connection, 'copy_target',
        withData: false,
    );

    expect($summary['failed'])->toBe(1)
        ->and($summary['copied'])->toBe(1)
        ->and($summary['errors'])->toHaveKey('ghost_table');
});

it('runs a copy through the wizard component', function () {
    Livewire::test(CopyWizard::class)
        ->dispatch('open-copy-wizard', connectionId: $this->connection->id, database: 'demo', objects: [
            ['name' => 'customers', 'type' => 'table'],
        ])
        ->set('targetConnectionId', $this->connection->id)
        ->assertSet('error', null)
        ->set('targetDatabase', 'copy_target')
        ->call('runCopy')
        ->assertSet('summary.copied', 1)
        ->assertDispatched('log');
});

it('halves an insert batch around a max_allowed_packet limit instead of failing rows that would fit', function () {
    $this->db->statement('CREATE DATABASE copy_source_wide');
    $this->db->statement('CREATE TABLE copy_source_wide.wide (id INT PRIMARY KEY, blob_col LONGBLOB)');
    $row = str_repeat('a', 300_000);
    $this->db->statement("INSERT INTO copy_source_wide.wide VALUES (1, '$row'), (2, '$row')");

    // Both rows fit individually (~300KB) but not in one ~600KB batch.
    $this->db->statement('SET GLOBAL max_allowed_packet = 524288');
    app(ConnectionManager::class)->reconnect($this->connection, 'copy_target');

    $summary = app(TableCopier::class)->copy(
        $this->connection, 'copy_source_wide',
        [['name' => 'wide', 'type' => 'table']],
        $this->connection, 'copy_target',
        withData: true,
    );

    expect($summary['copied'])->toBe(1)
        ->and($summary['failed'])->toBe(0)
        ->and($summary['packetTooLarge'])->toBe([])
        ->and($this->db->selectOne('SELECT COUNT(*) AS n FROM copy_target.wide')->n)->toBe(2);
});

it('reports a single oversized row with a suggested max_allowed_packet fix', function () {
    $this->db->statement('CREATE DATABASE copy_source_wide');
    $this->db->statement('CREATE TABLE copy_source_wide.wide (id INT PRIMARY KEY, blob_col LONGBLOB)');
    $row = str_repeat('a', 700_000);
    $this->db->statement("INSERT INTO copy_source_wide.wide VALUES (1, '$row')");

    $this->db->statement('SET GLOBAL max_allowed_packet = 524288');
    app(ConnectionManager::class)->reconnect($this->connection, 'copy_target');

    $summary = app(TableCopier::class)->copy(
        $this->connection, 'copy_source_wide',
        [['name' => 'wide', 'type' => 'table']],
        $this->connection, 'copy_target',
        withData: true,
    );

    expect($summary['failed'])->toBe(1)
        ->and($summary['packetTooLarge'])->toHaveKey('wide')
        ->and($summary['packetTooLarge']['wide']['current'])->toBe(524288)
        ->and($summary['packetTooLarge']['wide']['suggested'])->toBeGreaterThan(524288);
});

it('fixes the packet limit and retries the failed table through the wizard', function () {
    $this->db->statement('CREATE DATABASE copy_source_wide');
    $this->db->statement('CREATE TABLE copy_source_wide.wide (id INT PRIMARY KEY, blob_col LONGBLOB)');
    $row = str_repeat('a', 700_000);
    $this->db->statement("INSERT INTO copy_source_wide.wide VALUES (1, '$row')");

    $this->db->statement('SET GLOBAL max_allowed_packet = 524288');
    app(ConnectionManager::class)->reconnect($this->connection, 'copy_target');

    Livewire::test(CopyWizard::class)
        ->dispatch('open-copy-wizard', connectionId: $this->connection->id, database: 'copy_source_wide', objects: [
            ['name' => 'wide', 'type' => 'table'],
        ])
        ->set('targetConnectionId', $this->connection->id)
        ->set('targetDatabase', 'copy_target')
        ->call('runCopy')
        ->assertSet('summary.failed', 1)
        ->call('fixPacketLimitAndRetry')
        ->assertSet('error', null)
        ->assertSet('summary.copied', 1)
        ->assertSet('summary.failed', 0);

    expect($this->db->selectOne('SELECT COUNT(*) AS n FROM copy_target.wide')->n)->toBe(1)
        ->and((int) $this->db->selectOne('SELECT @@global.max_allowed_packet AS v')->v)->toBeGreaterThan(524288);
});

it('toggles a whole group (tables or views) with one click in the wizard', function () {
    Livewire::test(CopyWizard::class)
        ->dispatch('open-copy-wizard', connectionId: $this->connection->id, database: 'demo', objects: [
            ['name' => 'customers', 'type' => 'table'],
            ['name' => 'orders', 'type' => 'table'],
            ['name' => 'paid_orders', 'type' => 'view'],
        ])
        ->assertSet('selected', ['customers', 'orders', 'paid_orders'])
        ->call('toggleGroup', 'table')
        ->assertSet('selected', ['paid_orders'])
        ->call('toggleGroup', 'table')
        ->assertSet('selected', ['paid_orders', 'customers', 'orders'])
        ->call('toggleGroup', 'view')
        ->assertSet('selected', ['customers', 'orders'])
        ->assertSee('Tables (2/2)')
        ->assertSee('Views (0/1)');
});

it('refuses to copy a database onto itself', function () {
    Livewire::test(CopyWizard::class)
        ->dispatch('open-copy-wizard', connectionId: $this->connection->id, database: 'demo', objects: [
            ['name' => 'customers', 'type' => 'table'],
        ])
        ->set('targetConnectionId', $this->connection->id)
        ->set('targetDatabase', 'demo')
        ->call('runCopy')
        ->assertSet('summary', null)
        ->assertSet('error', 'Source and target are the same database.');
});
