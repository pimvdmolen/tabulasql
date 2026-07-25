<?php

use App\Models\Connection;

/**
 * The local MariaDB test container:
 * docker run -d --name dbmanager-test -e MARIADB_ROOT_PASSWORD=secret -p 33061:3306 mariadb:11
 *
 * The published port is tried first; the container IP is a fallback for
 * hosts where docker's port proxy is unavailable.
 *
 * @return ?array{host: string, port: int}
 */
function testDatabaseEndpoint(): ?array
{
    static $endpoint = false;

    if ($endpoint !== false) {
        return $endpoint;
    }

    // PHP may itself run inside a container (e.g. via a lerd/docker
    // wrapper), in which case 127.0.0.1 is not the host: try the published
    // port via localhost and the docker bridge gateway, then common bridge
    // IPs for the container itself.
    $candidates = array_filter([
        env('TEST_DB_HOST') ? ['host' => env('TEST_DB_HOST'), 'port' => (int) env('TEST_DB_PORT', 3306)] : null,
        ['host' => '127.0.0.1', 'port' => 33061],
        ['host' => '172.17.0.1', 'port' => 33061],
        ['host' => '172.17.0.2', 'port' => 3306],
        ['host' => '172.17.0.3', 'port' => 3306],
    ]);

    foreach ($candidates as $candidate) {
        $socket = @fsockopen($candidate['host'], $candidate['port'], $errno, $errstr, 0.5);

        if ($socket !== false) {
            fclose($socket);

            return $endpoint = $candidate;
        }
    }

    return $endpoint = null;
}

function testDatabaseAvailable(): bool
{
    return testDatabaseEndpoint() !== null;
}

function makeTestConnection(array $attributes = []): Connection
{
    $endpoint = testDatabaseEndpoint() ?? ['host' => '127.0.0.1', 'port' => 33061];

    return Connection::create($attributes + [
        'name' => 'Test '.uniqid(),
        'host' => $endpoint['host'],
        'port' => $endpoint['port'],
        'username' => 'root',
        'password' => 'secret',
    ]);
}
