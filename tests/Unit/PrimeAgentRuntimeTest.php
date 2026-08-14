<?php

namespace Tests\Unit;

use App\Services\PrimeAgentRuntime;
use RuntimeException;
use Tests\TestCase;

class PrimeAgentRuntimeTest extends TestCase
{
    public function test_starting_an_agent_without_the_binary_fails_cleanly(): void
    {
        config()->set('services.prime_agent.binary', '/definitely/not/prime-agent');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Prime Agent is not installed.');

        (new PrimeAgentRuntime)->create(base_path(), 'Test the project.');
    }

    public function test_creating_an_agent_uses_a_resident_daemon_session_and_sends_the_goal(): void
    {
        $runtime = new class extends PrimeAgentRuntime
        {
            /** @var list<array<string, mixed>> */
            public array $commands = [];

            /** @var list<array{sessionId: string, message: string}> */
            public array $messages = [];

            public function binary(): ?string
            {
                return '/usr/local/bin/prime-agent';
            }

            /** @param array<string, mixed> $command
             * @return array<string, mixed>
             */
            protected function daemonRequest(array $command): array
            {
                $this->commands[] = $command;

                return ['id' => 'saved-1', 'activeSessionId' => 'active-1', 'cwd' => base_path()];
            }

            /** @return array<string, mixed> */
            public function send(string $sessionId, string $message): array
            {
                $this->messages[] = ['sessionId' => $sessionId, 'message' => $message];

                return ['deliveryStatus' => 'accepted'];
            }
        };

        $agent = $runtime->create(base_path(), 'Test the project.');

        $this->assertSame('active-1', $agent['activeSessionId']);
        $this->assertSame([[
            'type' => 'create',
            'config' => [
                'cwd' => base_path(),
                'initialGoal' => ['objective' => 'Test the project.'],
            ],
            'lifecycle' => 'resident',
        ]], $runtime->commands);
        $this->assertSame([
            ['sessionId' => 'active-1', 'message' => 'Test the project.'],
        ], $runtime->messages);
    }
}
