<?php

use App\Livewire\Workspace;
use App\Models\Connection;
use App\Models\Setting;
use Livewire\Livewire;

it('persists and restores open tabs across sessions', function () {
    $a = Connection::create(['name' => 'A', 'host' => 'a', 'username' => 'u']);
    $b = Connection::create(['name' => 'B', 'host' => 'b', 'username' => 'u']);

    Livewire::test(Workspace::class)
        ->dispatch('open-connection', id: $a->id)
        ->dispatch('open-connection', id: $b->id)
        ->call('activateTab', $a->id);

    expect(Setting::get('open_tabs'))->toBe(['ids' => [$a->id, $b->id], 'active' => $a->id]);

    // A fresh workspace (new app start) restores them.
    Livewire::test(Workspace::class)
        ->assertCount('openTabs', 2)
        ->assertSet('activeTabId', $a->id);
});

it('marks open connections in the sidebar and closes them from there', function () {
    $a = Connection::create(['name' => 'A', 'host' => 'a', 'username' => 'u']);

    Livewire::test(Workspace::class)
        ->dispatch('open-connection', id: $a->id)
        ->assertDispatched('tabs-changed');

    Livewire::test(\App\Livewire\ConnectionSidebar::class)
        ->assertSee('open');

    Livewire::test(Workspace::class)
        ->dispatch('close-connection', id: $a->id)
        ->assertCount('openTabs', 0);

    Livewire::test(\App\Livewire\ConnectionSidebar::class)
        ->assertDontSee('>open<', false);
});

it('skips restoring deleted connections', function () {
    $a = Connection::create(['name' => 'A', 'host' => 'a', 'username' => 'u']);
    Setting::set('open_tabs', ['ids' => [$a->id, 999], 'active' => 999]);

    Livewire::test(Workspace::class)
        ->assertCount('openTabs', 1)
        ->assertSet('activeTabId', $a->id);
});
