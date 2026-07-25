<?php

namespace App\Services;

use App\Models\Connection;
use Illuminate\Database\Connection as DatabaseConnection;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * Registers runtime Laravel database connections (conn_{id}) for stored
 * connections, optionally routed through an SSH tunnel.
 */
class ConnectionManager
{
    public function __construct(private SshTunnel $tunnels) {}

    /**
     * Get a live Laravel connection for a stored connection.
     */
    public function db(Connection $connection, ?string $database = null): DatabaseConnection
    {
        $name = $this->configure($connection, $database);

        return DB::connection($name);
    }

    /**
     * Register (or refresh) the runtime config. Returns the connection name.
     */
    public function configure(Connection $connection, ?string $database = null): string
    {
        // One runtime connection per (connection, database) pair. A single
        // shared name would let a later configure() for another database
        // purge and re-target the session a caller is still holding on to
        // (e.g. copying between two databases of the same server).
        $database ??= $connection->database ?? $connection->default_database;
        $name = "conn_{$connection->id}_".($database ?? 'default');
        // "localhost" makes PDO use the MySQL unix socket, which yields the
        // cryptic "No such file or directory" error when no socket exists.
        // Force TCP like other database GUIs do.
        $host = strcasecmp($connection->host, 'localhost') === 0 ? '127.0.0.1' : $connection->host;
        $port = $connection->port;

        if ($connection->use_ssh) {
            $host = '127.0.0.1';
            $port = $this->tunnels->ensure($connection);
        }

        $config = [
            'driver' => 'mysql',
            'host' => $host,
            'port' => $port,
            'database' => $database ?? '',
            'username' => $connection->username,
            'password' => $connection->password ?? '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => false,
            'options' => [
                PDO::ATTR_TIMEOUT => 10,
            ],
        ];

        // Purge so a database switch or tunnel port change takes effect.
        if (config("database.connections.$name") !== $config) {
            config(["database.connections.$name" => $config]);
            DB::purge($name);
        }

        return $name;
    }

    /**
     * Force the next db() call for this (connection, database) pair to open
     * a fresh PDO connection. Needed after changing a server setting (e.g.
     * max_allowed_packet) that only takes effect for new connections. Unlike
     * disconnect(), this leaves any SSH tunnel running.
     */
    public function reconnect(Connection $connection, ?string $database = null): void
    {
        $database ??= $connection->database ?? $connection->default_database;

        DB::purge("conn_{$connection->id}_".($database ?? 'default'));
    }

    public function disconnect(Connection $connection): void
    {
        foreach (array_keys(config('database.connections', [])) as $name) {
            if (str_starts_with($name, "conn_{$connection->id}_")) {
                DB::purge($name);
            }
        }

        if ($connection->use_ssh) {
            $this->tunnels->stop($connection->id);
        }
    }

    /**
     * Test connectivity. Returns [ok, message, serverVersion|null].
     *
     * @return array{ok: bool, message: string, version: ?string}
     */
    public function test(Connection $connection): array
    {
        try {
            $db = $this->db($connection);
            $version = $db->selectOne('SELECT VERSION() AS v')->v;

            return ['ok' => true, 'message' => "Connected successfully (server version $version).", 'version' => $version];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $this->friendlyError($e), 'version' => null];
        }
    }

    private function friendlyError(Throwable $e): string
    {
        $message = $e->getMessage();

        $friendly = match (true) {
            str_contains($message, 'Access denied') => 'Access denied. Check the username and password.',
            str_contains($message, 'Connection refused') => 'Connection refused. Is the server running and the port correct?',
            str_contains($message, 'No such file or directory') => 'Could not reach the server. Check the host and port.',
            str_contains($message, 'getaddrinfo') || str_contains($message, 'Name or service not known') => 'Host not found. Check the hostname.',
            str_contains($message, 'timed out') || str_contains($message, 'timeout') => 'Connection timed out. Check the host, port, and any firewalls.',
            default => $message,
        };

        if ($friendly !== $message
            && ! str_contains($friendly, 'Access denied')
            && $this->phpRunsInContainer()) {
            $friendly .= ' Note: this app\'s PHP runs inside a container, so "localhost" refers to that'
                .' container, not your machine. For a database in another container on the same network,'
                .' use its container name as the host (with the container\'s own port).';
        }

        return $friendly;
    }

    /**
     * Whether PHP itself runs inside a container network namespace, where
     * "localhost" does not mean the user's machine.
     */
    private function phpRunsInContainer(): bool
    {
        if (file_exists('/.dockerenv') || file_exists('/run/.containerenv')) {
            return true;
        }

        $resolv = @file_get_contents('/etc/resolv.conf');

        return $resolv !== false && str_contains($resolv, 'dns.podman');
    }
}
