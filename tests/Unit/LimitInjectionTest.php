<?php

use App\Services\QueryRunner;

$runner = fn () => new QueryRunner(new class extends \App\Services\ConnectionManager
{
    public function __construct() {}
});

it('injects a limit into unlimited selects', function () use ($runner) {
    $result = $runner()->injectLimit('SELECT * FROM users');

    expect($result)->toBe(['sql' => 'SELECT * FROM users LIMIT 1000', 'injected' => true]);
});

it('leaves limited selects alone', function () use ($runner) {
    expect($runner()->injectLimit('SELECT * FROM users LIMIT 5')['injected'])->toBeFalse()
        ->and($runner()->injectLimit('select * from users limit 10, 20')['injected'])->toBeFalse();
});

it('leaves non-selects and locking reads alone', function () use ($runner) {
    expect($runner()->injectLimit('UPDATE users SET a = 1')['injected'])->toBeFalse()
        ->and($runner()->injectLimit('SELECT * FROM users FOR UPDATE')['injected'])->toBeFalse()
        ->and($runner()->injectLimit('SHOW TABLES')['injected'])->toBeFalse();
});
