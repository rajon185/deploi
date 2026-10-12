<?php

namespace App\Services\Ssh;

class SshResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $output,
        public readonly bool $timedOut = false,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0 && ! $this->timedOut;
    }

    public function throw(string $message = 'Remote command failed'): static
    {
        if (! $this->successful()) {
            throw new SshException($message.' (exit '.$this->exitCode.'): '.trim($this->output));
        }

        return $this;
    }
}
