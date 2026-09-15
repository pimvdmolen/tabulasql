<?php

namespace App\Livewire;

use App\Models\Connection;
use App\Services\MysqlUserManager;
use Livewire\Attributes\On;
use Livewire\Component;
use Throwable;

class UserManager extends Component
{
    public bool $open = false;

    public int $connectionId = 0;

    /** @var array<int, array{user: string, host: string, plugin: ?string}> */
    public array $users = [];

    public ?string $selectedUser = null;

    public ?string $selectedHost = null;

    /** @var string[] */
    public array $grants = [];

    public ?string $error = null;

    public bool $showCreate = false;

    public string $newUser = '';

    public string $newHost = '%';

    public string $newPassword = '';

    public string $grantDatabase = '';

    public string $grantPrivs = 'SELECT, INSERT, UPDATE, DELETE';

    #[On('open-user-manager')]
    public function open(int $connectionId): void
    {
        $this->connectionId = $connectionId;
        $this->error = null;
        $this->selectedUser = null;
        $this->selectedHost = null;
        $this->grants = [];
        $this->showCreate = false;
        $this->open = true;
        $this->refreshUsers();
    }

    public function refreshUsers(): void
    {
        try {
            $this->users = app(MysqlUserManager::class)->listUsers(Connection::findOrFail($this->connectionId));
            $this->error = null;
        } catch (Throwable $e) {
            $this->users = [];
            $this->error = $e->getMessage();
        }
    }

    public function selectUser(string $user, string $host): void
    {
        $this->selectedUser = $user;
        $this->selectedHost = $host;
        $this->grantDatabase = '';

        try {
            $this->grants = app(MysqlUserManager::class)->grants(
                Connection::findOrFail($this->connectionId),
                $user,
                $host
            );
            $this->error = null;
        } catch (Throwable $e) {
            $this->grants = [];
            $this->error = $e->getMessage();
        }
    }

    public function createUser(): void
    {
        try {
            app(MysqlUserManager::class)->createUser(
                Connection::findOrFail($this->connectionId),
                trim($this->newUser),
                trim($this->newHost) ?: '%',
                $this->newPassword
            );
            $this->showCreate = false;
            $this->newUser = '';
            $this->newPassword = '';
            $this->refreshUsers();
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function dropSelected(): void
    {
        if ($this->selectedUser === null || $this->selectedHost === null) {
            return;
        }

        try {
            app(MysqlUserManager::class)->dropUser(
                Connection::findOrFail($this->connectionId),
                $this->selectedUser,
                $this->selectedHost
            );
            $this->selectedUser = null;
            $this->selectedHost = null;
            $this->grants = [];
            $this->refreshUsers();
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function grantOnDatabase(): void
    {
        if ($this->selectedUser === null || $this->selectedHost === null || trim($this->grantDatabase) === '') {
            return;
        }

        try {
            app(MysqlUserManager::class)->grantOnDatabase(
                Connection::findOrFail($this->connectionId),
                $this->selectedUser,
                $this->selectedHost,
                trim($this->grantDatabase),
                $this->grantPrivs
            );
            $this->selectUser($this->selectedUser, $this->selectedHost);
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
        }
    }

    public function close(): void
    {
        $this->open = false;
    }

    public function render()
    {
        return view('livewire.user-manager');
    }
}
