<?php

namespace App\Services;

use App\Models\Connection;
use RuntimeException;
use Throwable;

/**
 * MySQL/MariaDB user and privilege helpers. Not used for PostgreSQL/SQLite.
 */
class MysqlUserManager
{
    public function __construct(private ConnectionManager $manager) {}

    /**
     * @return array<int, array{user: string, host: string, plugin: ?string}>
     */
    public function listUsers(Connection $connection): array
    {
        $this->assertMysql($connection);

        try {
            $rows = $this->manager->db($connection)->select(
                'SELECT User AS user, Host AS host, plugin FROM mysql.user ORDER BY User, Host'
            );
        } catch (Throwable $e) {
            throw new RuntimeException('Cannot read mysql.user (need GRANT privilege): '.$e->getMessage(), 0, $e);
        }

        return array_map(fn ($row) => [
            'user' => $row->user,
            'host' => $row->host,
            'plugin' => $row->plugin ?? null,
        ], $rows);
    }

    /**
     * @return string[] privilege lines from SHOW GRANTS
     */
    public function grants(Connection $connection, string $user, string $host): array
    {
        $this->assertMysql($connection);
        $account = $this->quoteAccount($user, $host);

        $rows = $this->manager->db($connection)->select("SHOW GRANTS FOR {$account}");
        $grants = [];

        foreach ($rows as $row) {
            $arr = (array) $row;
            $grants[] = (string) reset($arr);
        }

        return $grants;
    }

    public function createUser(Connection $connection, string $user, string $host, string $password): void
    {
        $this->assertMysql($connection);
        $account = $this->quoteAccount($user, $host);
        $escaped = $this->escapeLiteral($password);

        $this->manager->db($connection)->statement("CREATE USER {$account} IDENTIFIED BY {$escaped}");
    }

    public function dropUser(Connection $connection, string $user, string $host): void
    {
        $this->assertMysql($connection);
        $this->manager->db($connection)->statement('DROP USER '.$this->quoteAccount($user, $host));
    }

    /**
     * Grant common database privileges. $privs e.g. ALL PRIVILEGES or SELECT,INSERT.
     */
    public function grantOnDatabase(Connection $connection, string $user, string $host, string $database, string $privs = 'ALL PRIVILEGES'): void
    {
        $this->assertMysql($connection);
        $account = $this->quoteAccount($user, $host);
        $db = '`'.str_replace('`', '``', $database).'`';
        $privs = $this->sanitizePrivList($privs);

        $this->manager->db($connection)->statement("GRANT {$privs} ON {$db}.* TO {$account}");
        $this->manager->db($connection)->statement('FLUSH PRIVILEGES');
    }

    private function assertMysql(Connection $connection): void
    {
        if (! $connection->isMysql()) {
            throw new RuntimeException('User management is only available for MySQL / MariaDB connections.');
        }
    }

    private function quoteAccount(string $user, string $host): string
    {
        return "'".str_replace("'", "''", $user)."'@'".str_replace("'", "''", $host)."'";
    }

    private function escapeLiteral(string $value): string
    {
        return "'".str_replace(["\\", "'"], ["\\\\", "\\'"], $value)."'";
    }

    private function sanitizePrivList(string $privs): string
    {
        $allowed = [
            'ALL', 'ALL PRIVILEGES', 'SELECT', 'INSERT', 'UPDATE', 'DELETE',
            'CREATE', 'DROP', 'REFERENCES', 'INDEX', 'ALTER', 'CREATE TEMPORARY TABLES',
            'LOCK TABLES', 'EXECUTE', 'CREATE VIEW', 'SHOW VIEW', 'CREATE ROUTINE',
            'ALTER ROUTINE', 'EVENT', 'TRIGGER', 'PROCESS', 'RELOAD', 'SHOW DATABASES',
        ];

        $parts = array_map('trim', explode(',', strtoupper($privs)));
        $clean = [];

        foreach ($parts as $part) {
            if (in_array($part, $allowed, true)) {
                $clean[] = $part === 'ALL' ? 'ALL PRIVILEGES' : $part;
            }
        }

        return $clean === [] ? 'SELECT' : implode(', ', array_unique($clean));
    }
}
