<?php

namespace App\Services;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

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

    /** @return array{online: bool, error: string|null} */
    public function ensureDaemon(): array
    {
        $binary = $this->binary();

        if ($binary === null) {
            return ['online' => false, 'error' => 'Prime Agent is not installed.'];
        }

        if ($this->canListAgents()) {
            return ['online' => true, 'error' => null];
        }

        $launcher = new Process([
            '/bin/sh', '-c', 'exec "$1" --mode daemon </dev/null >/dev/null 2>&1 &',
            'prime-agent-daemon', $binary,
        ], base_path());
        $launcher->setTimeout(3);

        try {
            $launcher->run();
        } catch (\Throwable $error) {
            return ['online' => false, 'error' => $error->getMessage()];
        }

        $online = false;
        for ($attempt = 0; $attempt < 30; $attempt++) {
            usleep(100_000);
            if ($this->canListAgents()) {
                $online = true;
                break;
            }
        }

        return [
            'online' => $online,
            'error' => $online ? null : 'Timed out while starting the Prime Agent daemon.',
        ];
    }

    /** @return list<array<string, mixed>> */
    public function agents(): array
    {
        $result = $this->run(['list', '--all', '--json']);

        if (! $result['successful']) {
            return [];
        }

        $payload = json_decode($result['output'], true);

        if (! is_array($payload)) {
            return [];
        }

        $rawSessions = $payload['sessions'] ?? null;

        if (! is_array($rawSessions)) {
            return [];
        }

        $sessions = [];
        foreach ($rawSessions as $rawSession) {
            if (! is_array($rawSession)) {
                continue;
            }

            $session = [];
            foreach ($rawSession as $key => $value) {
                if (is_string($key)) {
                    $session[$key] = $value;
                }
            }
            $sessions[] = $session;
        }

        return $sessions;
    }

    /** @return array<string, mixed> */
    public function create(string $name, string $cwd, string $goal): array
    {
        $binary = $this->binary();
        if ($binary === null) {
            throw new \RuntimeException('Prime Agent is not installed.');
        }

        $knownIds = [];
        foreach ($this->agents() as $knownAgent) {
            $knownId = $knownAgent['activeSessionId'] ?? null;
            if (is_string($knownId)) {
                $knownIds[] = $knownId;
            }
        }

        $log = storage_path('logs/prime-agent-'.now()->format('Ymd-His').'.jsonl');
        $launcher = new Process([
            '/bin/sh', '-c', 'exec "$1" --mode json --cwd "$2" --goal "$3" -- "$3" </dev/null >>"$4" 2>&1 &',
            'prime-agent-session', $binary, $cwd, $goal, $log,
        ], base_path());
        $launcher->setTimeout(3);
        $launcher->run();

        if (! $launcher->isSuccessful()) {
            throw new \RuntimeException('Prime Agent could not start the background session.');
        }

        for ($attempt = 0; $attempt < 30; $attempt++) {
            usleep(100_000);
            $agent = collect($this->agents())->first(fn (array $candidate) => ($candidate['cwd'] ?? null) === $cwd
                && ! in_array($candidate['activeSessionId'] ?? null, $knownIds, true)
            );

            if ($agent !== null) {
                $activeSessionId = $agent['activeSessionId'] ?? null;
                if (is_string($activeSessionId)) {
                    $this->run(['rename', $activeSessionId, $name]);
                }

                return $agent;
            }
        }

        return ['activeSessionId' => null, 'sessionName' => $name, 'cwd' => $cwd];
    }

    /** @phpstan-impure */
    private function canListAgents(): bool
    {
        return $this->run(['list', '--all', '--json'], 2)['successful'];
    }

    /**
     * @param  list<string>  $arguments
     * @return array{successful: bool, output: string, error: string}
     */
    private function run(array $arguments, int $timeout = 5): array
    {
        $binary = $this->binary();

        if (! $binary) {
            return ['successful' => false, 'output' => '', 'error' => 'Prime Agent is not installed.'];
        }

        $process = new Process([$binary, ...$arguments], base_path());
        $process->setTimeout($timeout);

        try {
            $process->run();

            return [
                'successful' => $process->isSuccessful(),
                'output' => trim($process->getOutput()),
                'error' => trim($process->getErrorOutput()) ?: trim($process->getOutput()),
            ];
        } catch (\Throwable $error) {
            return ['successful' => false, 'output' => '', 'error' => $error->getMessage()];
        }
    }
}
