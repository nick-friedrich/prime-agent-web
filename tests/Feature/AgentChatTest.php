<?php

namespace Tests\Feature;

use App\Services\PrimeAgentRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ViewErrorBag;
use Mockery;
use Tests\TestCase;

class AgentChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_sessions_link_to_the_chat_workspace(): void
    {
        $agent = ['id' => 'saved-1', 'activeSessionId' => 'active-1', 'sessionName' => 'Release Pilot', 'cwd' => base_path(), 'messageCount' => 2];

        $this->view('dashboard', [
            'projects' => collect(),
            'activeProject' => null,
            'agents' => collect([$agent]),
            'primeAgentAvailable' => true,
            'primeAgentBinary' => '/usr/local/bin/prime-agent',
            'daemonOnline' => true,
            'daemonError' => null,
            'errors' => new ViewErrorBag,
        ])->assertSee('Release Pilot')
            ->assertSee(route('agents.show', ['sessionId' => 'saved-1']), false);
    }

    public function test_chat_page_and_transcript_endpoint_use_the_resolved_session_file(): void
    {
        $path = $this->transcript();
        $agent = ['id' => 'saved-1', 'activeSessionId' => 'active-1', 'sessionName' => 'Release Pilot', 'cwd' => base_path(), 'sessionFile' => $path, 'messageCount' => 1, 'activity' => 'idle'];
        $runtime = $this->mockRuntime([$agent], 3);
        $runtime->shouldReceive('binary')->andReturn('/usr/local/bin/prime-agent');

        try {
            $this->get('/agents/saved-1')
                ->assertOk()
                ->assertSee('Release Pilot')
                ->assertSee('Send a message to this agent');

            $response = $this->getJson('/agents/saved-1/transcript')
                ->assertOk()
                ->assertJsonPath('transcript.items.0.text', 'Hello agent');
            $etag = $response->headers->get('ETag');
            $this->assertNotNull($etag);

            $this->withHeader('If-None-Match', $etag)->get('/agents/saved-1/transcript')->assertStatus(304);
        } finally {
            File::delete($path);
        }
    }

    public function test_unknown_sessions_return_not_found(): void
    {
        $this->mockRuntime([]);
        $this->get('/agents/not-real')->assertNotFound();
    }

    public function test_messages_are_validated_and_sent_to_the_active_session(): void
    {
        $runtime = $this->mockRuntime([['id' => 'saved-1', 'activeSessionId' => 'active-1']]);
        $runtime->shouldReceive('send')->once()->with('active-1', 'Continue safely.')
            ->andReturn(['deliveryStatus' => 'queued']);

        $this->postJson('/agents/saved-1/messages', ['message' => 'Continue safely.'])
            ->assertStatus(202)
            ->assertJsonPath('receipt.deliveryStatus', 'queued');

        $this->postJson('/agents/saved-1/messages', ['message' => str_repeat('x', 16385)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');
    }

    public function test_saved_session_id_is_used_when_no_active_id_exists_and_runtime_failures_are_reported(): void
    {
        $runtime = $this->mockRuntime([['id' => 'saved-1']]);
        $runtime->shouldReceive('send')->once()->with('saved-1', 'Wake up')
            ->andThrow(new \RuntimeException('Daemon rejected the message.'));

        $this->postJson('/agents/saved-1/messages', ['message' => 'Wake up'])
            ->assertServiceUnavailable()
            ->assertJsonPath('message', 'Daemon rejected the message.');
    }

    /** @param list<array<string, mixed>> $agents */
    private function mockRuntime(array $agents, int $agentCalls = 1): PrimeAgentRuntime&Mockery\MockInterface
    {
        $runtime = Mockery::mock(PrimeAgentRuntime::class);
        $runtime->shouldReceive('isAvailable')->times($agentCalls)->andReturn(true);
        $runtime->shouldReceive('ensureDaemon')->times($agentCalls)->andReturn(['online' => true, 'error' => null]);
        $runtime->shouldReceive('agents')->times($agentCalls)->andReturn($agents);
        $this->app->instance(PrimeAgentRuntime::class, $runtime);

        return $runtime;
    }

    private function transcript(): string
    {
        $path = sys_get_temp_dir().'/prime-agent-feature-'.bin2hex(random_bytes(6)).'.jsonl';
        File::put($path, implode("\n", [
            json_encode(['type' => 'session', 'id' => 'saved-1', 'timestamp' => '2026-01-01T00:00:00Z', 'cwd' => base_path()]),
            json_encode(['type' => 'message', 'id' => 'message-1', 'parentId' => null, 'timestamp' => '2026-01-01T00:00:01Z', 'message' => ['role' => 'user', 'content' => 'Hello agent']]),
        ])."\n");

        return $path;
    }
}
