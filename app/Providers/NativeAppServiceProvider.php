<?php

namespace App\Providers;

use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        // Reap SSH tunnels left over from a previous run.
        app(\App\Services\SshTunnel::class)->stopAll();

        // Keep the query history table from growing forever.
        \App\Models\QueryHistory::where('executed_at', '<', now()->subMonths(3))->delete();

        Window::open()
            ->title('TabulaSQL')
            ->width(1400)
            ->height(900)
            ->minWidth(1200)
            ->minHeight(800)
            // ->hideDevTools() is a no-op when chained straight off open():
            // it only sets the property on an already-created (non-pending)
            // window. showDevTools(false) sets it unconditionally.
            ->showDevTools(false)
            // Windows/Linux: hides the native File/Edit/View/Window menu bar
            // (Electron's autoHideMenuBar; the app has its own in-UI menus,
            // it doesn't need the OS-drawn one). Pressing Alt still reveals
            // it temporarily; that's Electron/Chromium's own behavior, not
            // something NativePHP or this app can turn off. macOS always
            // keeps its own global menu bar regardless; the OS owns that,
            // not the app.
            ->hideMenu();
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        return [
            'memory_limit' => '512M',
            'max_execution_time' => '0',
        ];
    }
}
