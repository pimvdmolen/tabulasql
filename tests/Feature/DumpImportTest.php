<?php

use App\Livewire\ResultsPanel;
use App\Services\ConnectionManager;
use App\Services\SqlDumper;
use App\Services\SqlImporter;
use Livewire\Livewire;

beforeEach(function () {
    if (! testDatabaseAvailable()) {
        $this->markTestSkipped('Test database container not running');
    }

    $this->connection = makeTestConnection();
    $this->db = app(ConnectionManager::class)->db($this->connection);
    $this->db->statement('DROP DATABASE IF EXISTS dump_target');
});

afterEach(function () {
    $this->db?->statement('DROP DATABASE IF EXISTS dump_target');
});

it('dumps a database and re-imports it faithfully', function () {
    $stream = fopen('php://temp', 'w+');

    $summary = app(SqlDumper::class)->dump(
        $this->connection, 'demo', null, $stream,
        structure: true, data: true, dropIfExists: true, createDatabase: false,
    );

    expect($summary['tables'])->toBe(2)
        ->and($summary['views'])->toBe(1)
        ->and($summary['rows'])->toBeGreaterThanOrEqual(6)
        ->and($summary['errors'])->toBe([]);

    rewind($stream);
    $sql = stream_get_contents($stream);
    fclose($stream);

    expect($sql)->toContain('SET FOREIGN_KEY_CHECKS = 0')
        ->and($sql)->toContain('DROP TABLE IF EXISTS `customers`')
        ->and($sql)->toContain('CREATE TABLE `customers`')
        ->and($sql)->toContain('0x89504E470D0A1A0A0000000D49484452')
        ->and($sql)->not->toContain('DEFINER=');

    // Round-trip into a fresh database.
    $this->db->statement('CREATE DATABASE dump_target');
    $result = app(SqlImporter::class)->import($this->connection, 'dump_target', $sql);

    expect($result['failed'])->toBe(0);

    expect($this->db->selectOne('SELECT COUNT(*) AS n FROM dump_target.customers')->n)->toBe(3)
        ->and($this->db->selectOne('SELECT HEX(avatar) AS h FROM dump_target.customers WHERE name = "Bob"')->h)
        ->toBe('89504E470D0A1A0A0000000D49484452')
        ->and($this->db->selectOne('SELECT notes FROM dump_target.customers WHERE name = "Bob"')->notes)
        ->toBe(str_repeat('long note ', 100));
});

it('collects per-statement import errors without stopping', function () {
    $this->db->statement('CREATE DATABASE dump_target');

    $result = app(SqlImporter::class)->import(
        $this->connection, 'dump_target',
        "CREATE TABLE ok_table (id INT PRIMARY KEY);\nSELECT * FROM missing_table;\nINSERT INTO ok_table VALUES (1);"
    );

    expect($result['statements'])->toBe(3)
        ->and($result['executed'])->toBe(2)
        ->and($result['failed'])->toBe(1)
        ->and($result['errors'])->toHaveKey(2);
});

it('exports table data as csv, json and sql inserts', function () {
    $component = Livewire::test(ResultsPanel::class, ['connectionId' => $this->connection->id])
        ->dispatch('table-selected', connectionId: $this->connection->id, database: 'demo', table: 'orders');

    $panel = $component->instance();

    $reflect = new ReflectionMethod($panel, 'rowsForExport');
    [$columns, $rows] = $reflect->invoke($panel);

    expect($columns)->toContain('customer_id')->and($rows)->toHaveCount(3);

    $format = new ReflectionMethod($panel, 'formatExport');

    $csv = $format->invoke($panel, 'csv', $columns, $rows);
    expect($csv)->toContain('id,customer_id,total,status')->and($csv)->toContain('19.99');

    $json = json_decode($format->invoke($panel, 'json', $columns, $rows), true);
    expect($json)->toHaveCount(3)->and($json[0]['status'])->toBe('paid');

    $sql = $format->invoke($panel, 'sql', $columns, $rows);
    expect($sql)->toContain('INSERT INTO `orders`')->and($sql)->toContain("'paid'");
});
