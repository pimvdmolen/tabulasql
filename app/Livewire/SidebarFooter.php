<?php

namespace App\Livewire;

use App\Models\Setting;
use Livewire\Component;

class SidebarFooter extends Component
{
    public const THEMES = ['auto', 'light', 'dark', 'classic'];

    public string $theme = 'auto';

    public bool $showThemeDialog = false;

    public bool $safeMode = false;

    public bool $showMessagesTab = false;

    public function mount(): void
    {
        $this->theme = Setting::get('theme', 'auto');
        $this->safeMode = (bool) Setting::get('safe_mode', false);
        $this->showMessagesTab = (bool) Setting::get('show_messages_tab', false);
    }

    public function setTheme(string $theme): void
    {
        if (! in_array($theme, self::THEMES, true)) {
            return;
        }

        $this->theme = $theme;
        Setting::set('theme', $theme);
    }

    public function toggleSafeMode(): void
    {
        $this->safeMode = ! $this->safeMode;
        Setting::set('safe_mode', $this->safeMode);
        $this->dispatch('safe-mode-changed', enabled: $this->safeMode);
    }

    public function toggleMessagesTab(): void
    {
        $this->showMessagesTab = ! $this->showMessagesTab;
        Setting::set('show_messages_tab', $this->showMessagesTab);
        $this->dispatch('show-messages-tab-changed', enabled: $this->showMessagesTab);
    }

    public function render()
    {
        return view('livewire.sidebar-footer');
    }
}
