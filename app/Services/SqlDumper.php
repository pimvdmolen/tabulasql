<?php

namespace App\Services;

use App\Models\Connection;

/**
 * Pure-PHP SQL dump writer (no mysqldump dependency). Streams to a file
 * handle: header comment, FOREIGN_KEY_CHECKS wrapper, optional CREATE
 * DATABASE / DROP IF EXISTS, structure via SHOW CREATE, data as batched
 * multi-row INSERTs capped around 1 MB per statement. Binary values are
 * written as 0x… hex literals.
 */
class SqlDumper
{
    public const MAX_STATEMENT_BYTES = 1_000_000;

    public const CHUNK_ROWS = 1000;

    public function __construct(
        private ConnectionManager $manager,
        private SchemaExplorer $explorer,
    ) {}

    /**
     * @param  ?array<int, array{name: string, type: string}>  $objects  null = whole database
     * @param  resource  $stream
     * @param  ?callable(string): void  $progress
     * @return array{tables: int, views: int, rows: int, errors: array<string, string>}
     */
    public function dump(
        Connection $connection,
        string $database,
        ?array $objects,
        $stream,
        bool $structure = true,
        bool $data = true,
        bool $dropIfExists = false,
        bool $createDatabase = false,
        ?callable $progress = null,
    ): array {
        $report = fn (string $message) => $progress === null ? null : $progress($message);
        $write = fn (string $text) => fwrite($stream, $text);

        $db = $this->manager->db($connection, $database);
        $objects ??= $this->explorer->tables($connection, $database);

        usort($objects, fn ($a, $b) => ($a['type'] === 'view') <=> ($b['type'] === 'view'));

        $version = $db->selectOne('SELECT VERSION() AS v')->v;

        $write("-- TabulaSQL dump\n");
        $write('-- Server version: '.$version."\n");
        $write('-- Database: `'.$database."`\n");
        $write('-- Generated: '.now()->toDateTimeString()."\n\n");
        $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

        if ($createDatabase) {
            $write(sprintf(
                "CREATE DATABASE IF NOT EXISTS %s DEFAULT CHARACTER SET utf8mb4;\nUSE %s;\n\n",
                $this->explorer->quote($database), $this->explorer->quote($database)
            ));
        }

        $summary = ['tables' => 0, 'views' => 0, 'rows' => 0, 'errors' => []];

        foreach ($objects as $object) {
            $name = $object['name'];
            $isView = $object['type'] === 'view';

            try {
                if ($structure) {
                    $write('-- ----------------------------\n-- '.($isView ? 'View' : 'Table').": `$name`\n-- ----------------------------\n");

                    if ($dropIfExists) {
                        $write(sprintf("DROP %s IF EXISTS %s;\n", $isView ? 'VIEW' : 'TABLE', $this->explorer->quote($name)));
                    }

                    $ddl = $this->explorer->ddl($connection, $database, $name);

                    if ($isView) {
                        // Strip the definer and the source-database qualifier
                        // so the dump imports cleanly into any database.
                        $ddl = preg_replace('/DEFINER=\S+\s+/', '', $ddl);
                        $ddl = str_replace($this->explorer->quote($database).'.', '', $ddl);
                    }

                    $write($ddl.";\n\n");
                }

                if ($data && ! $isView) {
                    $rows = $this->dumpTableData($db, $database, $name, $write, $report);
                    $summary['rows'] += $rows;
                }

                $isView ? $summary['views']++ : $summary['tables']++;
                $report(($isView ? 'View' : 'Table')." `$name` dumped.");
            } catch (\Throwable $e) {
                $summary['errors'][$name] = $e->getMessage();
                $report("FAILED `$name`. ".$e->getMessage());
            }
        }

        $write("SET FOREIGN_KEY_CHECKS = 1;\n");

        return $summary;
    }

    private function dumpTableData($db, string $database, string $table, callable $write, callable $report): int
    {
        $quotedTarget = $this->explorer->quote($database).'.'.$this->explorer->quote($table);
        $quotedTable = $this->explorer->quote($table);
        $pdo = $db->getPdo();
        $offset = 0;
        $total = 0;

        while (true) {
            $rows = $db->select(sprintf('SELECT * FROM %s LIMIT %d, %d', $quotedTarget, $offset, self::CHUNK_ROWS));

            if ($rows === []) {
                break;
            }

            $columns = array_keys((array) $rows[0]);
            $prefix = sprintf(
                'INSERT INTO %s (%s) VALUES',
                $quotedTable,
                implode(', ', array_map($this->explorer->quote(...), $columns))
            );

            $buffer = '';

            foreach ($rows as $row) {
                $values = array_map(fn ($value) => $this->literal($pdo, $value), (array) $row);
                $tuple = '('.implode(', ', $values).')';

                if ($buffer === '') {
                    $buffer = $prefix."\n".$tuple;
                } elseif (strlen($buffer) + strlen($tuple) + 2 > self::MAX_STATEMENT_BYTES) {
                    $write($buffer.";\n");
                    $buffer = $prefix."\n".$tuple;
                } else {
                    $buffer .= ",\n".$tuple;
                }
            }

            if ($buffer !== '') {
                $write($buffer.";\n");
            }

            $total += count($rows);
            $report("  `$table`: $total row(s)…");

            if (count($rows) < self::CHUNK_ROWS) {
                break;
            }

            $offset += self::CHUNK_ROWS;
        }

        if ($total > 0) {
            $write("\n");
        }

        return $total;
    }

    /**
     * A single SQL literal: NULL, bare numbers, 0x… hex for binary, or a
     * PDO-quoted string.
     */
    public function literal(\PDO $pdo, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $value = (string) $value;

        if (! preg_match('//u', $value)) {
            return '0x'.strtoupper(bin2hex($value));
        }

        return $pdo->quote($value);
    }
}
