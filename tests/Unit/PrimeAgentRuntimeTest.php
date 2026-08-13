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

        (new PrimeAgentRuntime)->create('Test agent', base_path(), 'Test the project.');
    }
}
