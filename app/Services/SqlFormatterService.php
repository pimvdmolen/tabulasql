<?php

namespace App\Services;

use Doctrine\SqlFormatter\NullHighlighter;
use Doctrine\SqlFormatter\SqlFormatter;

class SqlFormatterService
{
    public function format(string $sql): string
    {
        $sql = trim($sql);

        if ($sql === '') {
            return '';
        }

        return (new SqlFormatter(new NullHighlighter))->format($sql);
    }
}
