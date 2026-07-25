<?php

namespace App\Services;

use App\Models\Connection;

/**
 * Executes a .sql file statement-by-statement with progress and per-
 * statement error collection (the run continues on errors).
 */
class SqlImporter
{
    public const MAX_ERRORS = 50;

    public function __construct(
        private ConnectionManager $manager,
        private SqlSplitter $splitter,
    ) {}

    /**
     * @param  ?callable(string): void  $progress
     * @return array{statements: int, executed: int, failed: int, duration_ms: int, errors: array<int, string>}
     */
    public function import(Connection $connection, ?string $database, string $sql, ?callable $progress = null): array
    {
        $report = fn (string $message) => $progress === null ? null : $progress($message);

        $statements = $this->splitter->split($sql);
        $db = $this->manager->db($connection, $database);
        $start = hrtime(true);

        $executed = 0;
        $failed = 0;
        $errors = [];

        foreach ($statements as $index => $statement) {
            try {
                $db->unprepared($statement);
                $executed++;
            } catch (\Throwable $e) {
                $failed++;

                if (count($errors) < self::MAX_ERRORS) {
                    $summary = mb_substr(preg_replace('/\s+/', ' ', $statement), 0, 80);
                    $errors[$index + 1] = "$summary. ".$e->getMessage();
                }
            }

            if (($index + 1) % 50 === 0) {
                $report(sprintf('%d / %d statements…', $index + 1, count($statements)));
            }
        }

        return [
            'statements' => count($statements),
            'executed' => $executed,
            'failed' => $failed,
            'duration_ms' => (int) ((hrtime(true) - $start) / 1_000_000),
            'errors' => $errors,
        ];
    }
}
