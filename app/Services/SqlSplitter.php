<?php

namespace App\Services;

/**
 * Splits a SQL script into individual statements on semicolons that are
 * outside strings, quoted identifiers and comments.
 */
class SqlSplitter
{
    /**
     * @return string[] Trimmed, non-empty statements without trailing semicolons.
     */
    public function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            // Line comments: -- (needs following space/EOL per MySQL) and #
            if ($char === '-' && $next === '-' && (($sql[$i + 2] ?? "\n") === ' ' || ($sql[$i + 2] ?? "\n") === "\t" || ($sql[$i + 2] ?? "\n") === "\n")) {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length : $end;
                $current .= substr($sql, $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($char === '#') {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length : $end;
                $current .= substr($sql, $i, $end - $i);
                $i = $end;

                continue;
            }

            // Block comments
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;
                $current .= substr($sql, $i, $end - $i);
                $i = $end;

                continue;
            }

            // Strings and quoted identifiers
            if ($char === "'" || $char === '"' || $char === '`') {
                $current .= $char;
                $i++;

                while ($i < $length) {
                    $current .= $sql[$i];

                    if ($sql[$i] === '\\' && $char !== '`' && $i + 1 < $length) {
                        // Backslash escape (not valid inside backticks)
                        $current .= $sql[$i + 1];
                        $i += 2;

                        continue;
                    }

                    if ($sql[$i] === $char) {
                        if (($sql[$i + 1] ?? '') === $char) {
                            // Doubled quote escape
                            $current .= $sql[$i + 1];
                            $i += 2;

                            continue;
                        }

                        $i++;
                        break;
                    }

                    $i++;
                }

                continue;
            }

            if ($char === ';') {
                $statements[] = $current;
                $current = '';
                $i++;

                continue;
            }

            $current .= $char;
            $i++;
        }

        $statements[] = $current;

        return array_values(array_filter(
            array_map('trim', $statements),
            fn ($statement) => $statement !== '' && ! $this->isOnlyComments($statement)
        ));
    }

    private function isOnlyComments(string $statement): bool
    {
        $stripped = preg_replace([
            '/--[ \t].*$/m',
            '/#.*$/m',
            '/\/\*.*?\*\//s',
        ], '', $statement);

        return trim((string) $stripped) === '';
    }
}
