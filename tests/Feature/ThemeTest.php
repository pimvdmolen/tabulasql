<?php

use App\Livewire\SidebarFooter;
use App\Models\Setting;
use Livewire\Livewire;

it('defaults to auto and persists a chosen theme', function () {
    Livewire::test(SidebarFooter::class)
        ->assertSet('theme', 'auto')
        ->call('setTheme', 'classic')
        ->assertSet('theme', 'classic');

    expect(Setting::get('theme'))->toBe('classic');

    // A fresh mount (new app start) picks the persisted choice back up.
    Livewire::test(SidebarFooter::class)->assertSet('theme', 'classic');
});

it('rejects unknown theme values', function () {
    Livewire::test(SidebarFooter::class)
        ->call('setTheme', 'classic')
        ->call('setTheme', 'neon')
        ->assertSet('theme', 'classic');

    expect(Setting::get('theme'))->toBe('classic');
});

it('shows all four theme options only while the dialog is open', function () {
    Livewire::test(SidebarFooter::class)
        ->assertDontSee('TabulaSQL Classic')
        ->set('showThemeDialog', true)
        ->assertSee('Auto')
        ->assertSee('Light')
        ->assertSee('Dark')
        ->assertSee('TabulaSQL Classic');
});

it('highlights the active theme option', function () {
    Livewire::test(SidebarFooter::class)
        ->call('setTheme', 'dark')
        ->set('showThemeDialog', true)
        ->assertSet('theme', 'dark');
});
