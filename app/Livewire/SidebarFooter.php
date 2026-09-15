<?php

namespace App\Livewire;

use App\Models\Setting;
use App\Services\AiSqlGenerator;
use Livewire\Attributes\On;
use Livewire\Component;

class SidebarFooter extends Component
{
    public const THEMES = ['auto', 'light', 'dark', 'classic'];

    public string $theme = 'auto';

    public bool $showThemeDialog = false;

    public bool $showAiDialog = false;

    public bool $safeMode = false;

    public bool $showMessagesTab = false;

    public string $aiProvider = 'openai';

    public string $aiApiKey = '';

    public string $aiModel = '';

    public string $aiBaseUrl = '';

    public bool $aiHasKey = false;

    public bool $aiModelCustom = false;

    /** @var list<array{label: string, models: list<array{id: string, label: string}}>> */
    public array $aiModelGroups = [];

    public string $aiModelsSource = 'fallback';

    public ?string $aiModelsError = null;

    public bool $aiModelsLoading = false;

    public function mount(): void
    {
        $this->theme = Setting::get('theme', 'auto');
        $this->safeMode = (bool) Setting::get('safe_mode', false);
        $this->showMessagesTab = (bool) Setting::get('show_messages_tab', false);
        $this->loadAiForm();
    }

    #[On('open-ai-settings')]
    public function openAiSettings(): void
    {
        $this->loadAiForm();
        $this->showAiDialog = true;
        $this->showThemeDialog = false;
        $this->refreshAiModels();
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

    public function updatedAiProvider(): void
    {
        $this->aiModelCustom = false;
        $this->aiModel = app(AiSqlGenerator::class)->defaultModel($this->aiProvider);
        $this->persistAiModelChoice($this->aiModel);
        $this->refreshAiModels();
    }

    public function updatedAiModel(string $value): void
    {
        if ($value === '__custom__') {
            $this->aiModelCustom = true;
            $this->aiModel = '';

            return;
        }

        $this->aiModelCustom = false;
        $this->persistAiModelChoice($value);
    }

    public function useModelList(): void
    {
        $this->aiModelCustom = false;
        if ($this->aiModel === '') {
            $this->aiModel = app(AiSqlGenerator::class)->defaultModel($this->aiProvider);
        }
        $this->persistAiModelChoice($this->aiModel);
        $this->refreshAiModels();
    }

    public function updatedAiBaseUrl(): void
    {
        if ($this->aiProvider === 'openai_compatible') {
            $this->refreshAiModels();
        }
    }

    public function refreshAiModels(): void
    {
        $this->aiModelsLoading = true;
        $this->aiModelsError = null;

        $result = app(AiSqlGenerator::class)->listModels(
            $this->aiProvider,
            $this->aiApiKey,
            $this->aiBaseUrl,
        );

        $this->aiModelGroups = $result['groups'];
        $this->aiModelsSource = $result['source'];
        $this->aiModelsError = $result['error'];
        $this->aiModelsLoading = false;

        $known = [];
        foreach ($this->aiModelGroups as $group) {
            foreach ($group['models'] as $model) {
                $known[$model['id']] = true;
            }
        }

        if ($this->aiModel !== '' && ! isset($known[$this->aiModel])) {
            array_unshift($this->aiModelGroups, [
                'label' => 'Current',
                'models' => [['id' => $this->aiModel, 'label' => $this->aiModel]],
            ]);
        }

        if ($this->aiModel === '' && $this->aiModelGroups !== []) {
            $this->aiModel = $this->aiModelGroups[0]['models'][0]['id'] ?? '';
            $this->aiModelCustom = false;
            $this->persistAiModelChoice($this->aiModel);
        }
    }

    public function saveAiSettings(): void
    {
        app(AiSqlGenerator::class)->saveConfig(
            $this->aiProvider,
            $this->aiApiKey,
            trim($this->aiModel),
            trim($this->aiBaseUrl),
        );
        $this->rememberModelCookie(trim($this->aiModel));
        $this->aiApiKey = '';
        $this->loadAiForm();
        $this->showAiDialog = false;
    }

    public function clearAiKey(): void
    {
        app(AiSqlGenerator::class)->clearKey();
        $this->loadAiForm();
        $this->refreshAiModels();
    }

    private function persistAiModelChoice(string $model): void
    {
        $model = trim($model);
        if ($model === '' || $model === '__custom__') {
            return;
        }

        app(AiSqlGenerator::class)->saveConfig(
            $this->aiProvider,
            '',
            $model,
            trim($this->aiBaseUrl),
        );
        $this->rememberModelCookie($model);
    }

    private function rememberModelCookie(string $model): void
    {
        if ($model === '') {
            return;
        }

        cookie()->queue(cookie('tabula_ai_model', $model, 60 * 24 * 365));
    }

    private function loadAiForm(): void
    {
        $generator = app(AiSqlGenerator::class);
        $config = $generator->config();
        $this->aiProvider = $config['provider'];

        $fromCookie = trim((string) request()->cookie('tabula_ai_model', ''));
        $model = $fromCookie !== ''
            ? $generator->normalizeModel($this->aiProvider, $fromCookie)
            : $config['model'];

        $this->aiModel = $model !== '' ? $model : $generator->defaultModel($this->aiProvider);
        $this->aiBaseUrl = $config['base_url'];
        $this->aiHasKey = $config['api_key'] !== '';
        $this->aiApiKey = '';
        $this->aiModelCustom = false;

        // Migrate obsolete saved model ids (e.g. removed sonnet snapshot → haiku).
        if ($config['model'] !== $this->aiModel) {
            $this->persistAiModelChoice($this->aiModel);
        } elseif ($fromCookie !== $this->aiModel) {
            $this->rememberModelCookie($this->aiModel);
        }
    }

    public function render()
    {
        return view('livewire.sidebar-footer');
    }
}
