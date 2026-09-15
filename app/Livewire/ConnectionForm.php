<?php

namespace App\Livewire;

use App\Models\Connection;
use App\Services\ConnectionManager;
use App\Support\ConnectionColor;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;
use Native\Desktop\Dialog;
use Throwable;

class ConnectionForm extends Component
{
    public bool $open = false;

    public ?int $connectionId = null;

    public string $name = '';

    public string $driver = 'mysql';

    public ?string $color = null;

    public string $host = 'localhost';

    public int $port = 3306;

    public string $username = 'root';

    public string $password = '';

    public bool $use_ssh = false;

    public string $ssh_host = '';

    public int $ssh_port = 22;

    public string $ssh_username = '';

    public string $ssh_auth_type = 'password';

    public string $ssh_password = '';

    public string $ssh_key_path = '';

    public string $database = '';

    public string $default_database = '';

    public ?array $testResult = null;

    public ?string $browseMessage = null;

    #[On('create-connection')]
    public function create(): void
    {
        $this->resetForm();
        $this->color = ConnectionColor::resolve(null, 'new');
        $this->open = true;
    }

    #[On('edit-connection')]
    public function edit(int $id): void
    {
        $connection = Connection::find($id);

        if ($connection === null) {
            return;
        }

        $this->resetForm();
        $this->connectionId = $connection->id;
        $this->name = $connection->name;
        $this->driver = $connection->driverName();
        $this->color = $connection->color;
        $this->host = $connection->host;
        $this->port = $connection->port;
        $this->username = $connection->username;
        $this->password = $connection->password ?? '';
        $this->use_ssh = $connection->use_ssh;
        $this->ssh_host = $connection->ssh_host ?? '';
        $this->ssh_port = $connection->ssh_port;
        $this->ssh_username = $connection->ssh_username ?? '';
        $this->ssh_auth_type = $connection->ssh_auth_type;
        $this->ssh_password = $connection->ssh_password ?? '';
        $this->ssh_key_path = $connection->ssh_key_path ?? '';
        $this->database = $connection->database ?? '';
        $this->default_database = $connection->default_database ?? '';
        $this->open = true;
    }

    public function updatedDriver(string $value): void
    {
        if (! in_array($value, ['mysql', 'pgsql', 'sqlite'], true)) {
            return;
        }

        $this->port = match ($value) {
            'pgsql' => 5432,
            'sqlite' => 0,
            default => 3306,
        };

        if ($value === 'sqlite') {
            $this->use_ssh = false;
            $this->username = $this->username === 'root' ? '' : $this->username;
            if ($this->host === 'localhost') {
                $this->host = '';
            }
        } else {
            if ($this->host === '') {
                $this->host = 'localhost';
            }
            if ($this->username === '') {
                $this->username = 'root';
            }
        }
    }

    /**
     * Opens the OS's native file picker (Explorer/Finder/whatever file
     * manager the user has) to choose the SSH private key file. Only works
     * inside the desktop app, the Electron shell is what actually shows
     * the dialog, so there is nothing to open when previewing in a browser.
     */
    public function browseForPrivateKey(): void
    {
        $this->browseMessage = null;

        if (! config('nativephp-internal.running')) {
            $this->browseMessage = 'Browsing for files only works in the desktop app. Type the path manually here.';

            return;
        }

        $home = PHP_OS_FAMILY === 'Windows' ? getenv('USERPROFILE') : getenv('HOME');
        $sshDir = $home ? rtrim($home, '/\\').DIRECTORY_SEPARATOR.'.ssh' : null;

        $dialog = Dialog::new()
            ->title('Select your SSH private key')
            ->button('Select')
            ->files();

        if ($sshDir !== null && is_dir($sshDir)) {
            $dialog->defaultPath($sshDir);
        }

        try {
            $path = $dialog->open();
        } catch (Throwable $e) {
            $this->browseMessage = "Couldn't open the file browser: {$e->getMessage()}";

            return;
        }

        if ($path !== null) {
            if (str_ends_with($path, '.pub')) {
                $this->browseMessage = 'That looks like the public key. Pick the matching private key file instead (usually the same name without ".pub").';

                return;
            }

            $this->ssh_key_path = $path;
        }
    }

    public function testConnection(): void
    {
        $this->validate();

        $connection = new Connection($this->payload());
        $connection->id = $this->connectionId ?? 0;

        $this->testResult = app(ConnectionManager::class)->test($connection);
    }

    public function save(): void
    {
        $this->validate();

        $connection = $this->connectionId !== null
            ? Connection::findOrFail($this->connectionId)
            : new Connection;

        $payload = $this->payload();
        $payload['color'] = ConnectionColor::resolve($payload['color'] ?? null, $payload['name']);

        if ($this->connectionId === null) {
            $payload['sort_order'] = ((int) Connection::max('sort_order')) + 1;
        }

        $connection->fill($payload)->save();

        $this->open = false;
        $this->dispatch('connection-saved', id: $connection->id);
    }

    public function close(): void
    {
        $this->open = false;
    }

    protected function rules(): array
    {
        $sqlite = $this->driver === 'sqlite';

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('connections', 'name')->ignore($this->connectionId)],
            'driver' => ['required', 'in:mysql,pgsql,sqlite'],
            'color' => ['nullable', 'string', 'max:20'],
            'host' => ['required', 'string', 'max:255'],
            'port' => $sqlite ? ['nullable', 'integer', 'between:0,65535'] : ['required', 'integer', 'between:1,65535'],
            'username' => $sqlite ? ['nullable', 'string', 'max:255'] : ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string'],
            'use_ssh' => ['boolean'],
            'ssh_host' => ['required_if:use_ssh,true', 'nullable', 'string', 'max:255'],
            'ssh_port' => ['integer', 'between:1,65535'],
            'ssh_username' => ['required_if:use_ssh,true', 'nullable', 'string', 'max:255'],
            'ssh_auth_type' => ['in:password,key'],
            'ssh_password' => ['nullable', 'string'],
            'ssh_key_path' => ['nullable', 'string', 'max:1024'],
            'database' => ['nullable', 'string', 'max:64'],
            'default_database' => ['nullable', 'string', 'max:64'],
        ];
    }

    private function payload(): array
    {
        return [
            'name' => $this->name,
            'driver' => $this->driver,
            'color' => $this->color ?: null,
            'host' => $this->host,
            'port' => $this->driver === 'sqlite' ? max(0, (int) $this->port) : $this->port,
            'username' => $this->driver === 'sqlite' ? ($this->username ?: '') : $this->username,
            'password' => $this->password === '' ? null : $this->password,
            'use_ssh' => $this->driver === 'sqlite' ? false : $this->use_ssh,
            'ssh_host' => $this->ssh_host ?: null,
            'ssh_port' => $this->ssh_port,
            'ssh_username' => $this->ssh_username ?: null,
            'ssh_auth_type' => $this->ssh_auth_type,
            'ssh_password' => $this->ssh_password === '' ? null : $this->ssh_password,
            'ssh_key_path' => $this->ssh_key_path ?: null,
            'database' => $this->database ?: null,
            // Mutually exclusive with `database`: a restricted connection
            // has nothing left to "default" to, so drop any stale value.
            'default_database' => $this->database ? null : ($this->default_database ?: null),
        ];
    }

    private function resetForm(): void
    {
        $this->reset();
    }

    public function render()
    {
        return view('livewire.connection-form');
    }
}
