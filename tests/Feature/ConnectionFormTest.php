<?php

use App\Livewire\ConnectionForm;
use App\Models\Connection;
use Livewire\Livewire;

it('validates required fields', function () {
    Livewire::test(ConnectionForm::class)
        ->dispatch('create-connection')
        ->set('name', '')
        ->set('host', '')
        ->call('save')
        ->assertHasErrors(['name', 'host']);
});

it('saves a new connection with an encrypted password', function () {
    Livewire::test(ConnectionForm::class)
        ->dispatch('create-connection')
        ->set('name', 'Local dev')
        ->set('host', '127.0.0.1')
        ->set('port', 33061)
        ->set('username', 'root')
        ->set('password', 'secret')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('connection-saved');

    $connection = Connection::firstWhere('name', 'Local dev');

    expect($connection)->not->toBeNull()
        ->and($connection->password)->toBe('secret')
        ->and($connection->getRawOriginal('password'))->not->toBe('secret');
});

it('rejects duplicate names', function () {
    Connection::create(['name' => 'Dup', 'host' => 'a', 'username' => 'u']);

    Livewire::test(ConnectionForm::class)
        ->dispatch('create-connection')
        ->set('name', 'Dup')
        ->set('host', 'b')
        ->set('username', 'u')
        ->call('save')
        ->assertHasErrors(['name']);
});

it('loads an existing connection for editing', function () {
    $connection = Connection::create([
        'name' => 'Edit me', 'host' => 'db.example.com', 'port' => 3307,
        'username' => 'admin', 'password' => 'hunter2',
    ]);

    Livewire::test(ConnectionForm::class)
        ->dispatch('edit-connection', id: $connection->id)
        ->assertSet('name', 'Edit me')
        ->assertSet('host', 'db.example.com')
        ->assertSet('port', 3307)
        ->assertSet('password', 'hunter2')
        ->assertSet('open', true);
});

it('clears default_database when a restricted database is set', function () {
    Livewire::test(ConnectionForm::class)
        ->dispatch('create-connection')
        ->set('name', 'Restricted')
        ->set('host', 'a')
        ->set('username', 'u')
        ->set('default_database', 'leftover')
        ->set('database', 'my_database')
        ->call('save')
        ->assertHasNoErrors();

    $connection = Connection::firstWhere('name', 'Restricted');

    expect($connection->database)->toBe('my_database')
        ->and($connection->default_database)->toBeNull();
});

it('hides the default database field once a database is set', function () {
    Livewire::test(ConnectionForm::class)
        ->dispatch('create-connection')
        ->assertSee('Default database')
        ->set('database', 'my_database')
        ->assertDontSee('Default database')
        ->set('database', '')
        ->assertSee('Default database');
});

it('explains that browsing for a key file needs the desktop app', function () {
    Livewire::test(ConnectionForm::class)
        ->dispatch('create-connection')
        ->set('use_ssh', true)
        ->set('ssh_auth_type', 'key')
        ->call('browseForPrivateKey')
        ->assertSet('ssh_key_path', '')
        ->assertSee('only works in the desktop app');
});

it('fails gracefully when the native dialog cannot be reached', function () {
    config(['nativephp-internal.running' => true, 'nativephp-internal.api_url' => 'http://127.0.0.1:1/']);

    Livewire::test(ConnectionForm::class)
        ->dispatch('create-connection')
        ->set('use_ssh', true)
        ->set('ssh_auth_type', 'key')
        ->call('browseForPrivateKey')
        ->assertSet('ssh_key_path', '')
        ->assertSee("Couldn't open the file browser");
});

it('tests connectivity against a live server', function () {
    $endpoint = testDatabaseEndpoint();

    Livewire::test(ConnectionForm::class)
        ->dispatch('create-connection')
        ->set('name', 'Live')
        ->set('host', $endpoint['host'])
        ->set('port', $endpoint['port'])
        ->set('username', 'root')
        ->set('password', 'secret')
        ->call('testConnection')
        ->assertSet('testResult.ok', true);
})->skip(fn () => ! testDatabaseAvailable(), 'Test database container not running');

it('reports a friendly error for wrong credentials', function () {
    $endpoint = testDatabaseEndpoint();

    Livewire::test(ConnectionForm::class)
        ->dispatch('create-connection')
        ->set('name', 'Bad creds')
        ->set('host', $endpoint['host'])
        ->set('port', $endpoint['port'])
        ->set('username', 'root')
        ->set('password', 'wrong')
        ->call('testConnection')
        ->assertSet('testResult.ok', false);
})->skip(fn () => ! testDatabaseAvailable(), 'Test database container not running');
