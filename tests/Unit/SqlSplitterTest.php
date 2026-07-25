<?php

use App\Services\SqlSplitter;

$split = fn (string $sql) => (new SqlSplitter)->split($sql);

it('splits simple statements', function () use ($split) {
    expect($split('SELECT 1; SELECT 2;'))->toBe(['SELECT 1', 'SELECT 2']);
});

it('keeps semicolons inside strings', function () use ($split) {
    expect($split("SELECT 'a;b'; SELECT \"c;d\";"))->toBe(["SELECT 'a;b'", 'SELECT "c;d"']);
});

it('handles escaped and doubled quotes', function () use ($split) {
    expect($split("SELECT 'it''s;fine'; SELECT 'back\\';slash';"))
        ->toBe(["SELECT 'it''s;fine'", "SELECT 'back\\';slash'"]);
});

it('keeps semicolons inside backtick identifiers', function () use ($split) {
    expect($split('SELECT `weird;col` FROM t;'))->toBe(['SELECT `weird;col` FROM t']);
});

it('ignores semicolons in comments', function () use ($split) {
    $sql = "SELECT 1 -- comment; with semicolon\n; SELECT 2 /* block; comment */;";

    expect($split($sql))->toBe(["SELECT 1 -- comment; with semicolon", 'SELECT 2 /* block; comment */']);
});

it('handles hash comments', function () use ($split) {
    expect($split("SELECT 1 # trailing; note\n;"))->toBe(['SELECT 1 # trailing; note']);
});

it('drops empty and comment-only statements', function () use ($split) {
    expect($split('; ;; -- just a comment\n;'))->toBe([]);
});

it('returns the final statement without trailing semicolon', function () use ($split) {
    expect($split('SELECT 1'))->toBe(['SELECT 1']);
});
