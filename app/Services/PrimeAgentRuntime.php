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
    public function create(string $cwd, string $goal): array
    {
        if ($this->binary() === null) {
            throw new \RuntimeException('Prime Agent is not installed.');
        }

        $agent = $this->daemonRequest([
            'type' => 'create',
            'config' => [
                'cwd' => $cwd,
                'initialGoal' => ['objective' => $goal],
            ],
            'lifecycle' => 'resident',
        ]);
        $activeSessionId = $agent['activeSessionId'] ?? null;
        if (! is_string($activeSessionId) || $activeSessionId === '') {
            throw new \RuntimeException('Prime Agent returned an invalid session identifier.');
        }

        try {
            $this->send($activeSessionId, $goal);
        } catch (\RuntimeException $error) {
            $this->run(['stop', $activeSessionId, '--json']);

            throw $error;
        }

        return $agent;
    }

    /** @return array<string, mixed> */
    public function send(string $sessionId, string $message): array
    {
        $result = $this->run(['send', '--json', $sessionId, $message], 35);
        if (! $result['successful']) {
            throw new \RuntimeException($result['error'] ?: 'Prime Agent did not accept the message.');
        }

        $payload = json_decode($result['output'], true);
        if (! is_array($payload)) {
            throw new \RuntimeException('Prime Agent returned an invalid message receipt.');
        }

        $receipt = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $receipt[$key] = $value;
            }
        }

        return $receipt;
    }

    public function stop(string $activeSessionId): void
    {
        $result = $this->run(['stop', $activeSessionId, '--json'], 35);
        if (! $result['successful']) {
            throw new \RuntimeException($result['error'] ?: 'Prime Agent could not stop the agent.');
        }
    }

    /** @phpstan-impure */
    private function canListAgents(): bool
    {
        return $this->run(['list', '--all', '--json'], 2)['successful'];
    }

    /**
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     */
    protected function daemonRequest(array $command): array
    {
        $status = $this->run(['status', '--json']);
        $daemons = json_decode($status['output'], true);
        if (! $status['successful'] || ! is_array($daemons)) {
            throw new \RuntimeException($status['error'] ?: 'Prime Agent daemon status is unavailable.');
        }

        $socketPath = null;
        foreach ($daemons as $daemon) {
            if (is_array($daemon) && ($daemon['isDefault'] ?? false) === true && is_string($daemon['socketPath'] ?? null)) {
                $socketPath = $daemon['socketPath'];
                break;
            }
        }
        if ($socketPath === null) {
            throw new \RuntimeException('Prime Agent did not report a default daemon socket.');
        }

        $errorNumber = 0;
        $errorMessage = '';
        $socket = @stream_socket_client('unix://'.$socketPath, $errorNumber, $errorMessage, 3);
        if ($socket === false) {
            throw new \RuntimeException($errorMessage ?: 'Could not connect to the Prime Agent daemon.');
        }

        stream_set_timeout($socket, 35);

        try {
            $hello = $this->readDaemonMessage($socket);
            $protocol = $hello['protocol'] ?? null;
            $protocolVersion = is_array($protocol) ? ($protocol['version'] ?? null) : null;
            if (($hello['type'] ?? null) !== 'daemon_hello' || ! is_int($protocolVersion) || $protocolVersion < 7) {
                throw new \RuntimeException('Prime Agent returned an incompatible daemon handshake.');
            }

            $requestId = 'prime-agent-web-'.bin2hex(random_bytes(12));
            $clientId = 'prime-agent-web:'.bin2hex(random_bytes(12));
            $command['id'] = $requestId;
            $envelope = [
                'type' => 'command',
                'id' => $requestId,
                'protocol' => ['name' => 'prime-agent.daemon', 'version' => min(7, $protocolVersion)],
                'clientId' => $clientId,
                'command' => $command,
            ];
            $encoded = json_encode($envelope, JSON_THROW_ON_ERROR)."\n";
            if (fwrite($socket, $encoded) === false || ! fflush($socket)) {
                throw new \RuntimeException('Could not write to the Prime Agent daemon.');
            }

            do {
                $response = $this->readDaemonMessage($socket);
            } while (($response['type'] ?? null) !== 'response' || ($response['id'] ?? null) !== $requestId);

            $this->acknowledgeDaemonResult($socket, $clientId, $protocolVersion, $requestId);

            if (($response['success'] ?? false) !== true) {
                $error = $response['error'] ?? null;
                throw new \RuntimeException(is_string($error) && $error !== '' ? $error : 'Prime Agent rejected the daemon request.');
            }

            $data = $response['data'] ?? null;
            if (! is_array($data)) {
                throw new \RuntimeException('Prime Agent returned an invalid daemon response.');
            }

            $result = [];
            foreach ($data as $key => $value) {
                if (is_string($key)) {
                    $result[$key] = $value;
                }
            }

            return $result;
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket
     * @return array<string, mixed>
     */
    private function readDaemonMessage($socket): array
    {
        $line = fgets($socket);
        if ($line === false) {
            $metadata = stream_get_meta_data($socket);
            throw new \RuntimeException($metadata['timed_out']
                ? 'Timed out waiting for the Prime Agent daemon.'
                : 'The Prime Agent daemon closed the connection.');
        }

        $message = json_decode($line, true);
        if (! is_array($message)) {
            throw new \RuntimeException('Prime Agent returned invalid daemon JSON.');
        }

        $result = [];
        foreach ($message as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** @param resource $socket */
    private function acknowledgeDaemonResult($socket, string $clientId, int $protocolVersion, string $commandId): void
    {
        $acknowledgementId = 'prime-agent-web-ack-'.bin2hex(random_bytes(12));
        $acknowledgement = [
            'type' => 'command',
            'id' => $acknowledgementId,
            'protocol' => ['name' => 'prime-agent.daemon', 'version' => min(7, $protocolVersion)],
            'clientId' => $clientId,
            'command' => [
                'id' => $acknowledgementId,
                'type' => 'ack_result',
                'commandId' => $commandId,
            ],
        ];

        fwrite($socket, json_encode($acknowledgement, JSON_THROW_ON_ERROR)."\n");
        fflush($socket);
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
