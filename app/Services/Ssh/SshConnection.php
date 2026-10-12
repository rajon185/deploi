<?php

namespace App\Services\Ssh;

use App\Models\Server;
use Illuminate\Support\Str;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;

/**
 * A thin wrapper around phpseclib that knows how to reach a Deploi server with its private key,
 * run ad-hoc commands, run whole bash scripts as a given user, and move files.
 */
class SshConnection
{
    protected ?SFTP $ssh = null;

    public function __construct(protected Server $server, protected string $user = 'root') {}

    public function connect(?int $timeout = null): static
    {
        if ($this->ssh?->isConnected()) {
            return $this;
        }

        if (! $this->server->ip_address) {
            throw new SshException("Server [{$this->server->name}] has no IP address yet.");
        }

        $ssh = new SFTP($this->server->ip_address, $this->server->ssh_port ?: 22, $timeout ?? (int) config('deploi.ssh_timeout'));
        $ssh->setKeepAlive(30);

        try {
            $key = PublicKeyLoader::load($this->server->private_key);
            $loggedIn = $ssh->login($this->user, $key);
        } catch (\Throwable $e) {
            throw new SshException("Could not connect to {$this->server->ip_address}:{$this->server->ssh_port} - {$e->getMessage()}", 0, $e);
        }

        if (! $loggedIn) {
            throw new SshException("SSH authentication failed for {$this->user}@{$this->server->ip_address}. Is Deploi's public key in {$this->user}'s authorized_keys?");
        }

        $this->ssh = $ssh;

        return $this;
    }

    /** Run a single command. Output is streamed to $onOutput as it arrives. */
    public function exec(string $command, ?callable $onOutput = null, int $timeout = 60): SshResult
    {
        $this->connect();

        $output = '';
        $this->ssh->setTimeout($timeout);
        $this->ssh->exec($command, function (string $chunk) use (&$output, $onOutput) {
            $output .= $chunk;
            if ($onOutput) {
                $onOutput($chunk);
            }
        });

        $timedOut = $this->ssh->isTimeout();
        $exit = $this->ssh->getExitStatus();

        // phpseclib leaves the channel in a weird state after a timeout; start fresh next time.
        if ($timedOut) {
            $this->disconnect();
        }

        return new SshResult(
            exitCode: $exit === false ? ($timedOut ? 124 : 0) : (int) $exit,
            output: $output,
            timedOut: $timedOut,
        );
    }

    /**
     * Upload a bash script and run it as $runAs (via sudo when it isn't the SSH user).
     * Stderr is merged into stdout so the log reads in order.
     */
    public function runScript(string $script, string $runAs = 'root', ?callable $onOutput = null, int $timeout = 600): SshResult
    {
        $this->connect();

        $path = '/tmp/deploi-'.Str::random(24).'.sh';
        $body = "#!/usr/bin/env bash\nset -eo pipefail\nexport DEBIAN_FRONTEND=noninteractive\n\n".str_replace("\r\n", "\n", $script)."\n";

        $this->put($path, $body, 0700);

        if ($runAs === $this->user) {
            $command = "bash {$path} 2>&1; code=\$?; rm -f {$path}; exit \$code";
        } else {
            $user = escapeshellarg($runAs);
            // "bash -l" rather than "sudo -i": users like www-data have a nologin shell on existing servers.
            $command = "chown {$user}: {$path} && cd /tmp && sudo -u {$user} -H -- bash -l {$path} 2>&1; code=\$?; rm -f {$path}; exit \$code";
        }

        return $this->exec($command, $onOutput, $timeout);
    }

    public function put(string $path, string $contents, int $mode = 0644): void
    {
        $this->connect();

        if (! $this->ssh->put($path, $contents)) {
            throw new SshException("Failed to upload {$path}.");
        }

        $this->ssh->chmod($mode, $path);
    }

    public function get(string $path): ?string
    {
        $this->connect();

        $contents = $this->ssh->get($path);

        return $contents === false ? null : $contents;
    }

    public function disconnect(): void
    {
        $this->ssh?->disconnect();
        $this->ssh = null;
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
