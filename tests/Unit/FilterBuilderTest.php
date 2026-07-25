<?php

use App\Services\FilterBuilder;

$builder = fn () => new FilterBuilder;

it('builds simple comparisons with bindings', function () use ($builder) {
    $result = $builder()->build([
        ['column' => 'name', 'operator' => '=', 'value' => 'Alice'],
        ['column' => 'age', 'operator' => '>=', 'value' => '18'],
    ]);

    expect($result['where'])->toBe('`name` = ? AND `age` >= ?')
        ->and($result['bindings'])->toBe(['Alice', '18']);
});

it('handles null, in and between operators', function () use ($builder) {
    $result = $builder()->build([
        ['column' => 'deleted_at', 'operator' => 'IS NULL'],
        ['column' => 'status', 'operator' => 'IN', 'value' => 'a, b , c'],
        ['column' => 'total', 'operator' => 'BETWEEN', 'value' => '10', 'value2' => '20'],
    ]);

    expect($result['where'])->toBe('`deleted_at` IS NULL AND `status` IN (?, ?, ?) AND `total` BETWEEN ? AND ?')
        ->and($result['bindings'])->toBe(['a', 'b', 'c', '10', '20']);
});

it('escapes backticks in column names', function () use ($builder) {
    $result = $builder()->build([['column' => 'we`ird', 'operator' => '=', 'value' => 'x']]);

    expect($result['where'])->toBe('`we``ird` = ?');
});

it('rejects unknown operators', function () use ($builder) {
    $builder()->build([['column' => 'a', 'operator' => 'UNION SELECT']]);
})->throws(InvalidArgumentException::class);

it('rejects empty in lists', function () use ($builder) {
    $builder()->build([['column' => 'a', 'operator' => 'IN', 'value' => ' , ']]);
})->throws(InvalidArgumentException::class);

it('describes rules for chips', function () use ($builder) {
    expect($builder()->describe(['column' => 'a', 'operator' => 'LIKE', 'value' => '%x%']))->toBe('a LIKE %x%')
        ->and($builder()->describe(['column' => 'b', 'operator' => 'IS NULL']))->toBe('b IS NULL');
});
