<?php

use App\Services\QueryRunner;

function formatter(): QueryRunner
{
    return new QueryRunner(new class extends \App\Services\ConnectionManager
    {
        public function __construct() {}
    });
}

it('passes through scalars and null', function () {
    $runner = formatter();

    expect($runner->formatValue(null))->toBeNull()
        ->and($runner->formatValue(42))->toBe(42)
        ->and($runner->formatValue(3.14))->toBe(3.14)
        ->and($runner->formatValue('hello'))->toBe('hello');
});

it('collapses long text with size metadata', function () {
    $long = str_repeat('x', 5000);
    $value = formatter()->formatValue($long);

    expect($value)->toBeArray()
        ->and($value['blob'])->toBeFalse()
        ->and($value['size'])->toBe(5000)
        ->and($value['truncated'])->toBeFalse()
        ->and($value['full'])->toBe($long);
});

it('hex-encodes binary values', function () {
    $binary = "\x89PNG\x0D\x0A\x1A\x0A\xFF\xFE";
    $value = formatter()->formatValue($binary);

    expect($value)->toBeArray()
        ->and($value['blob'])->toBeTrue()
        ->and($value['size'])->toBe(strlen($binary))
        ->and($value['preview'])->toBe(strtoupper(bin2hex($binary)));
});

it('truncates values beyond the payload cap', function () {
    $huge = str_repeat('a', QueryRunner::FULL_LIMIT + 100);
    $value = formatter()->formatValue($huge);

    expect($value['truncated'])->toBeTrue()
        ->and(strlen($value['full']))->toBe(QueryRunner::FULL_LIMIT);
});
