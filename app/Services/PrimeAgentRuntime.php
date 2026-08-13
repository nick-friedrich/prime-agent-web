<?php

namespace App\Services;

use Symfony\Component\Process\ExecutableFinder;

class PrimeAgentRuntime
{
    public function binary(): ?string
    {
        $configured = config('services.prime_agent.binary');

        if (is_string($configured) && $configured !== '') {
            return is_file($configured) && is_executable($configured) ? $configured : null;
        }

        return (new ExecutableFinder)->find('prime-agent');
    }

    public function isAvailable(): bool
    {
        return $this->binary() !== null;
    }
}
