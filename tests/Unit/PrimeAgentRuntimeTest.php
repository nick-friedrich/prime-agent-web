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

        (new PrimeAgentRuntime)->create(base_path(), 'Test the project.', 'goal');
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

        $agent = $runtime->create(base_path(), 'Test the project.', 'goal');

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

    public function test_allocating_a_chat_session_does_not_send_a_message(): void
    {
        $runtime = new class extends PrimeAgentRuntime
        {
            /** @var list<array<string, mixed>> */
            public array $commands = [];

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

                return ['id' => 'saved-1', 'activeSessionId' => 'active-1'];
            }
        };

        $agent = $runtime->createSession(base_path());

        $this->assertSame('active-1', $agent['activeSessionId']);
        $this->assertSame([[
            'type' => 'create',
            'config' => ['cwd' => base_path()],
            'lifecycle' => 'resident',
        ]], $runtime->commands);
    }

    public function test_prompt_sends_images_through_the_daemon_and_queues_if_busy(): void
    {
        $runtime = new class extends PrimeAgentRuntime
        {
            /** @var list<array<string, mixed>> */
            public array $commands = [];

            /** @param array<string, mixed> $command
             * @return array<string, mixed>
             */
            protected function daemonRequest(array $command): array
            {
                $this->commands[] = $command;

                return [];
            }
        };

        $receipt = $runtime->prompt('active-1', 'Review this image.', [[
            'type' => 'image', 'mimeType' => 'image/png', 'data' => 'aW1hZ2U=',
        ]]);

        $this->assertSame(['deliveryStatus' => 'accepted'], $receipt);
        $this->assertSame([[
            'type' => 'prompt',
            'activeSessionId' => 'active-1',
            'message' => 'Review this image.',
            'streamingBehavior' => 'followUp',
            'queueIfBusy' => true,
            'source' => 'rpc',
            'images' => [['type' => 'image', 'mimeType' => 'image/png', 'data' => 'aW1hZ2U=']],
        ]], $runtime->commands);
    }

    public function test_creating_a_chat_session_does_not_seed_a_persistent_goal(): void
    {
        $runtime = new class extends PrimeAgentRuntime
        {
            /** @var list<array<string, mixed>> */
            public array $commands = [];

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

                return ['activeSessionId' => 'active-1'];
            }

            /** @return array<string, mixed> */
            public function send(string $sessionId, string $message): array
            {
                return ['deliveryStatus' => 'accepted'];
            }
        };

        $runtime->create(base_path(), 'Answer one question.', 'chat');

        $this->assertSame([[
            'type' => 'create',
            'config' => ['cwd' => base_path()],
            'lifecycle' => 'resident',
        ]], $runtime->commands);
    }
}
