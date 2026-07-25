<?php

use App\Livewire\ConnectionPorterDialog;
use App\Models\Connection;
use App\Services\ConnectionPorter;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

function porterConnections(): void
{
    Connection::create(['name' => 'Alpha', 'host' => 'a.example.com', 'username' => 'root', 'password' => 's3cret']);
    Connection::create(['name' => 'Beta', 'host' => 'b.example.com', 'port' => 3307, 'username' => 'app']);
}

it('round-trips an encrypted export', function () {
    porterConnections();
    $porter = app(ConnectionPorter::class);

    $file = $porter->export(Connection::all(), 'hunter2');

    expect($porter->isEncrypted($file))->toBeTrue()
        ->and($file)->not->toContain('s3cret')
        ->and($file)->not->toContain('a.example.com');

    $imported = $porter->import($file, 'hunter2');

    expect($imported)->toHaveCount(2)
        ->and($imported[0]['name'])->toBe('Alpha')
        ->and($imported[0]['password'])->toBe('s3cret')
        ->and($imported[1]['port'])->toBe(3307);
});

it('round-trips a plain export with a readable warning payload', function () {
    porterConnections();
    $porter = app(ConnectionPorter::class);

    $file = $porter->export(Connection::all());

    expect($porter->isEncrypted($file))->toBeFalse()
        ->and($file)->toContain('a.example.com');

    expect($porter->import($file))->toHaveCount(2);
});

it('rejects a wrong passphrase', function () {
    porterConnections();
    $porter = app(ConnectionPorter::class);

    $file = $porter->export(Connection::all(), 'right');

    $porter->import($file, 'wrong');
})->throws(RuntimeException::class, 'Wrong passphrase');

it('rejects files that are not exports', function () {
    app(ConnectionPorter::class)->import('{"hello": "world"}');
})->throws(RuntimeException::class, 'not a TabulaSQL connection export');

it('rejects exports from a newer format version', function () {
    $file = json_encode(['format' => 'dbmconn', 'format_version' => 99, 'encrypted' => false, 'payload' => ['connections' => []]]);

    app(ConnectionPorter::class)->import($file);
})->throws(RuntimeException::class, 'newer version');

it('imports with conflict handling through the dialog', function () {
    porterConnections();
    $file = app(ConnectionPorter::class)->export(Connection::all());

    // Change Alpha locally so overwrite is observable; delete Beta so it imports fresh.
    Connection::firstWhere('name', 'Alpha')->update(['host' => 'old.example.com']);
    Connection::firstWhere('name', 'Beta')->delete();

    $upload = UploadedFile::fake()->createWithContent('connections.json', $file);

    Livewire::test(ConnectionPorterDialog::class)
        ->dispatch('open-import-connections')
        ->set('upload', $upload)
        ->assertSet('preview.0.exists', true)
        ->assertSet('preview.0.action', 'skip')
        ->assertSet('preview.1.exists', false)
        ->set('preview.0.action', 'overwrite')
        ->call('import')
        ->assertSet('summary', 'Imported 1, overwrote 1, skipped 0.')
        ->assertDispatched('connection-saved');

    expect(Connection::firstWhere('name', 'Alpha')->host)->toBe('a.example.com')
        ->and(Connection::firstWhere('name', 'Beta'))->not->toBeNull();
});

it('imports conflicting names as a copy', function () {
    porterConnections();
    $file = app(ConnectionPorter::class)->export(Connection::all());
    $upload = UploadedFile::fake()->createWithContent('connections.json', $file);

    Livewire::test(ConnectionPorterDialog::class)
        ->dispatch('open-import-connections')
        ->set('upload', $upload)
        ->set('preview.0.action', 'import')
        ->set('preview.1.action', 'skip')
        ->call('import');

    expect(Connection::where('name', 'Alpha (2)')->exists())->toBeTrue();
});
