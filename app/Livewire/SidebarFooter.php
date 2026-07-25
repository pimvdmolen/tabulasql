<?php

namespace App\Livewire;

use App\Models\Setting;
use Livewire\Component;

class SidebarFooter extends Component
{
    public const THEMES = ['auto', 'light', 'dark', 'classic'];

    public string $theme = 'auto';

    public bool $showThemeDialog = false;

    public function mount(): void
    {
        $this->theme = Setting::get('theme', 'auto');
    }

    public function setTheme(string $theme): void
    {
        if (! in_array($theme, self::THEMES, true)) {
            return;
        }

        $this->theme = $theme;
        Setting::set('theme', $theme);
    }

    public function render()
    {
        return view('livewire.sidebar-footer');
    }
}
