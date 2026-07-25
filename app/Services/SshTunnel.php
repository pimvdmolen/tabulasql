<?php

namespace App\Services;

use App\Models\Connection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Manages ssh -N -L port-forward processes, one per stored connection.
 * State (pid + local port per connection) lives in a cache registry because
 * every Livewire request runs in a fresh PHP process. Tunnel processes
 * survive requests; stale ones are reaped on app boot.
 */
class SshTunnel
{
    private const REGISTRY_KEY = 'ssh_tunnel_registry';

    private const ATTEMPTS = 2;

    /**
     * Ensure a tunnel is running for the connection. Returns the local port.
     */
    public function ensure(Connection $connection): int
    {
        $state = $this->registry()[$connection->id] ?? null;

        if ($state !== null && $this->portOpen($state['port']) && $this->isAlive($state['pid'])) {
            return $state['port'];
        }

        $lastError = null;

        foreach (range(1, self::ATTEMPTS) as $attempt) {
            try {
                return $this->start($connection);
            } catch (RuntimeException $e) {
                $lastError = $e;
            }
        }

        throw $lastError;
    }

    public function start(Connection $connection): int
    {
        $ssh = (new ExecutableFinder)->find('ssh');

        if ($ssh === null) {
            throw new RuntimeException('The `ssh` executable was not found on this system.');
        }

        $localPort = $this->freePort();
        $usePassword = $connection->ssh_auth_type === 'password';

        $command = [$ssh, '-N'];

        if (! $usePassword) {
            // BatchMode would block the password prompt sshpass answers.
            $command = [...$command, '-o', 'BatchMode=yes'];
        }

        if ($connection->ssh_auth_type === 'key' && $connection->ssh_key_path) {
            $command = [...$command, '-i', $connection->ssh_key_path];
        }

        $command = [...$command,
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'ExitOnForwardFailure=yes',
            '-o', 'ConnectTimeout=10',
            '-o', 'ServerAliveInterval=30',
            '-o', 'ServerAliveCountMax=3',
            '-p', (string) $connection->ssh_port,
            '-L', sprintf('%d:%s:%d', $localPort, $connection->host, $connection->port),
            sprintf('%s@%s', $connection->ssh_username, $connection->ssh_host),
        ];

        $env = null;

        if ($usePassword) {
            $sshpass = (new ExecutableFinder)->find('sshpass');

            if ($sshpass === null) {
                throw new RuntimeException(
                    'SSH password authentication requires `sshpass`, which is not installed. '
                    .'Install sshpass or switch this connection to key authentication.'
                );
            }

            $command = [$sshpass, '-e', ...$command];
            $env = array_merge(getenv(), ['SSHPASS' => $connection->ssh_password]);
        }

        // Deliberately proc_open(), not Symfony's Process: Process::__destruct()
        // kills its child as soon as the object goes out of scope, which here
        // is the moment this request ends. Since every Livewire request runs
        // in a fresh PHP process, that killed the tunnel after every single
        // request — defeating the whole point of keeping it alive in between.
        // A raw proc_open() resource detaches cleanly: the ssh process
        // survives after this script exits.
        $errFile = tempnam(sys_get_temp_dir(), 'tabula-ssh-tunnel-');
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', $errFile, 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, null, $env);

        if ($process === false) {
            @unlink($errFile);
            throw new RuntimeException('Failed to start the SSH tunnel process.');
        }

        // Wait for the forward to come up (or the process to die with an error).
        $deadline = microtime(true) + 15;

        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);

            if (! $status['running']) {
                $error = trim((string) file_get_contents($errFile)) ?: 'unknown error';
                @unlink($errFile);
                throw new RuntimeException("SSH tunnel failed: $error");
            }

            if ($this->portOpen($localPort)) {
                $this->remember($connection->id, ['pid' => $status['pid'], 'port' => $localPort]);
                @unlink($errFile);

                return $localPort;
            }

            usleep(150_000);
        }

        proc_terminate($process);
        @unlink($errFile);
        throw new RuntimeException('SSH tunnel timed out while waiting for the forwarded port.');
    }

    public function stop(int $connectionId): void
    {
        $registry = $this->registry();
        $state = $registry[$connectionId] ?? null;

        if ($state !== null && $this->isAlive($state['pid'])) {
            $this->terminate($state['pid']);
        }

        unset($registry[$connectionId]);
        Cache::forever(self::REGISTRY_KEY, $registry);
    }

    /**
     * Kill every registered tunnel. Called on app boot so tunnels from a
     * previous run don't linger (Electron has no PHP shutdown hook).
     */
    public function stopAll(): void
    {
        foreach ($this->registry() as $state) {
            if ($this->isAlive($state['pid'])) {
                $this->terminate($state['pid']);
            }
        }

        Cache::forever(self::REGISTRY_KEY, []);
    }

    /**
     * @return ?array{pid: int, port: int, alive: bool}
     */
    public function status(int $connectionId): ?array
    {
        $state = $this->registry()[$connectionId] ?? null;

        if ($state === null) {
            return null;
        }

        return $state + ['alive' => $this->isAlive($state['pid']) && $this->portOpen($state['port'])];
    }

    private function registry(): array
    {
        return Cache::get(self::REGISTRY_KEY, []);
    }

    private function remember(int $connectionId, array $state): void
    {
        $registry = $this->registry();
        $registry[$connectionId] = $state;
        Cache::forever(self::REGISTRY_KEY, $registry);
    }

    private function isAlive(int $pid): bool
    {
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        // The `posix` extension isn't in the bundled PHP build (neither
        // NativePHP's default nor this app's custom static build includes
        // it), so `posix_kill` doesn't exist here. Shell out to `kill -0`
        // instead, which checks liveness without sending a real signal.
        exec('kill -0 '.escapeshellarg((string) $pid).' 2>/dev/null', result_code: $exitCode);

        return $exitCode === 0;
    }

    private function terminate(int $pid): void
    {
        if (function_exists('posix_kill')) {
            posix_kill($pid, SIGTERM);

            return;
        }

        exec('kill '.escapeshellarg((string) $pid).' 2>/dev/null');
    }

    private function portOpen(int $port): bool
    {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.25);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($socket === false) {
            throw new RuntimeException("Could not allocate a local port: $errstr");
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
