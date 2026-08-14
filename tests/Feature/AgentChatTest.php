<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\PrimeAgentRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Mockery;
use Tests\TestCase;

class AgentChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_sessions_link_to_the_chat_workspace(): void
    {
        $agent = ['id' => 'saved-1', 'activeSessionId' => 'active-1', 'firstMessage' => 'Prepare the release', 'cwd' => base_path(), 'messageCount' => 2];

        $this->view('dashboard', [
            'projects' => collect(),
            'activeProject' => null,
            'agents' => collect([$agent]),
            'primeAgentAvailable' => true,
            'primeAgentBinary' => '/usr/local/bin/prime-agent',
            'daemonOnline' => true,
            'daemonError' => null,
            'errors' => new ViewErrorBag,
        ])->assertSee('Prepare the release')
            ->assertDontSee('Stop agent')
            ->assertDontSee('Agent name')
            ->assertSee(route('agents.show', ['sessionId' => 'saved-1']), false);
    }

    public function test_chat_page_and_transcript_endpoint_use_the_resolved_session_file(): void
    {
        $path = $this->transcript();
        $agent = ['id' => 'saved-1', 'activeSessionId' => 'active-1', 'firstMessage' => '(no messages)', 'cwd' => base_path(), 'sessionFile' => $path, 'messageCount' => 1, 'activity' => 'idle'];
        $runtime = $this->mockRuntime([$agent], 3);
        $runtime->shouldReceive('binary')->andReturn('/usr/local/bin/prime-agent');

        try {
            $this->get('/agents/saved-1')
                ->assertOk()
                ->assertSee('Hello agent')
                ->assertDontSee('(no messages)')
                ->assertSee('Stop agent')
                ->assertSee('Send a message to this agent');

            $response = $this->getJson('/agents/saved-1/transcript')
                ->assertOk()
                ->assertJsonPath('transcript.items.0.text', 'Hello agent')
                ->assertJsonPath('agent.firstMessage', 'Hello agent')
                ->assertJsonPath('transcript.currentActivity.label', 'Ready for input')
                ->assertJsonMissingPath('agent.streamingMessage');
            $etag = $response->headers->get('ETag');
            $this->assertNotNull($etag);

            $this->withHeader('If-None-Match', $etag)->get('/agents/saved-1/transcript')->assertStatus(304);
        } finally {
            File::delete($path);
        }
    }

    public function test_transcript_etag_changes_when_only_live_agent_state_changes(): void
    {
        $path = $this->transcript();
        $base = ['id' => 'saved-1', 'activeSessionId' => 'active-1', 'sessionFile' => $path, 'activity' => 'working'];
        $runtime = Mockery::mock(PrimeAgentRuntime::class);
        $runtime->shouldReceive('isAvailable')->twice()->andReturn(true);
        $runtime->shouldReceive('ensureDaemon')->twice()->andReturn(['online' => true, 'error' => null]);
        $runtime->shouldReceive('agents')->once()->andReturn([$base + [
            'isStreaming' => true,
            'streamingMessage' => ['role' => 'assistant', 'content' => [['type' => 'thinking', 'thinking' => '**Inspecting files**']]],
        ]]);
        $runtime->shouldReceive('agents')->once()->andReturn([$base + ['isCompacting' => true]]);
        $this->app->instance(PrimeAgentRuntime::class, $runtime);

        try {
            $first = $this->getJson('/agents/saved-1/transcript')
                ->assertOk()
                ->assertJsonPath('transcript.currentActivity.kind', 'thinking');
            $second = $this->withHeader('If-None-Match', (string) $first->headers->get('ETag'))
                ->getJson('/agents/saved-1/transcript')
                ->assertOk()
                ->assertJsonPath('transcript.currentActivity.kind', 'compacting');

            $this->assertNotSame($first->headers->get('ETag'), $second->headers->get('ETag'));
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

    public function test_images_and_files_can_be_uploaded_with_or_without_message_text(): void
    {
        Storage::fake('local');
        $runtime = $this->mockRuntime([['id' => 'saved-1', 'activeSessionId' => 'active-1']], 2);
        $prompt = null;
        $runtime->shouldReceive('prompt')->once()->with(
            'active-1',
            Mockery::on(function (string $message) use (&$prompt): bool {
                $prompt = $message;

                return str_contains($message, '<prime-agent-web-attachments>');
            }),
            Mockery::on(fn (array $images): bool => count($images) === 1
                && ($images[0]['type'] ?? null) === 'image'
                && ($images[0]['mimeType'] ?? null) === 'image/png'
                && is_string($images[0]['data'] ?? null)),
        )->andReturn(['deliveryStatus' => 'accepted']);

        $this->post(route('agents.messages.store', ['sessionId' => 'saved-1']), [
            'message' => '',
            'attachments' => [
                UploadedFile::fake()->image('diagram.png', 30, 20),
                UploadedFile::fake()->createWithContent('notes.txt', 'Important notes'),
            ],
        ], ['Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('receipt.deliveryStatus', 'accepted');

        $this->assertIsString($prompt);
        preg_match('/<prime-agent-web-attachments>(.*?)<\/prime-agent-web-attachments>/s', $prompt, $match);
        $attachments = json_decode($match[1] ?? '', true);
        $this->assertIsArray($attachments);
        $this->assertCount(2, $attachments);
        $this->assertSame('diagram.png', $attachments[0]['name']);
        $this->assertTrue($attachments[0]['image']);
        $this->assertSame('notes.txt', $attachments[1]['name']);
        $this->assertFalse($attachments[1]['image']);
        $this->assertFileExists($attachments[1]['path']);

        $download = $this->get(route('agents.attachments.show', [
            'sessionId' => 'saved-1',
            'attachmentId' => $attachments[1]['id'],
        ]))->assertOk()
            ->assertHeader('Content-Disposition', "attachment; filename*=UTF-8''notes.txt");
        $this->assertSame('Important notes', file_get_contents($download->baseResponse->getFile()->getPathname()));
    }

    public function test_a_message_requires_text_or_an_attachment_and_limits_attachment_count(): void
    {
        $this->postJson('/agents/saved-1/messages', ['message' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');

        $files = array_map(
            fn (int $index): UploadedFile => UploadedFile::fake()->create("file-{$index}.txt", 1),
            range(1, 6),
        );
        $this->post('/agents/saved-1/messages', ['attachments' => $files], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachments');
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

    public function test_an_active_agent_can_be_stopped_from_the_web_ui(): void
    {
        $runtime = $this->mockRuntime([['id' => 'saved-1', 'activeSessionId' => 'active-1']]);
        $runtime->shouldReceive('stop')->once()->with('active-1');

        $this->delete('/agents/saved-1')
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success', 'The agent was stopped. Its session remains available to resume later.');
    }

    public function test_the_prompt_area_can_stop_an_active_agent_with_json(): void
    {
        $runtime = $this->mockRuntime([['id' => 'saved-1', 'activeSessionId' => 'active-1']]);
        $runtime->shouldReceive('stop')->once()->with('active-1');

        $this->deleteJson('/agents/saved-1')
            ->assertOk()
            ->assertJsonPath('redirect', route('dashboard'));
    }

    public function test_a_saved_but_inactive_session_cannot_be_stopped(): void
    {
        $this->mockRuntime([['id' => 'saved-1']]);

        $this->delete('/agents/saved-1')->assertStatus(409);
    }

    public function test_starting_an_agent_requires_no_title(): void
    {
        $project = Project::create([
            'name' => 'Prime Agent Web',
            'slug' => 'prime-agent-web',
            'path' => base_path(),
            'color' => '#C8FF58',
        ]);
        $runtime = Mockery::mock(PrimeAgentRuntime::class);
        $runtime->shouldReceive('isAvailable')->once()->andReturn(true);
        $runtime->shouldReceive('ensureDaemon')->once()->andReturn(['online' => true, 'error' => null]);
        $runtime->shouldReceive('create')->once()->with(base_path(), 'Review the application.', 'chat')
            ->andReturn(['id' => 'saved-1', 'activeSessionId' => 'active-1']);
        $this->app->instance(PrimeAgentRuntime::class, $runtime);

        $this->post(route('agents.store'), [
            'project_id' => $project->id,
            'goal' => 'Review the application.',
            'session_mode' => 'chat',
        ])->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'The agent was started in Prime Agent.');
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
