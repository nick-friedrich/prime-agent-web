<?php

namespace Tests\Unit;

use App\Services\AgentTranscriptReader;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AgentTranscriptReaderTest extends TestCase
{
    public function test_it_reads_current_branch_and_normalizes_messages_and_tools(): void
    {
        $path = $this->transcript([
            ['type' => 'session', 'id' => 'session-1', 'timestamp' => '2026-01-01T00:00:00Z', 'cwd' => '/tmp'],
            ['type' => 'message', 'id' => 'user-1', 'parentId' => null, 'timestamp' => '2026-01-01T00:00:01Z', 'message' => ['role' => 'user', 'content' => "<script>bad()</script>\n\n**Build it**"]],
            ['type' => 'message', 'id' => 'old-branch', 'parentId' => 'user-1', 'timestamp' => '2026-01-01T00:00:02Z', 'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Discarded branch']]]],
            ['type' => 'message', 'id' => 'assistant-1', 'parentId' => 'user-1', 'timestamp' => '2026-01-01T00:00:03Z', 'message' => ['role' => 'assistant', 'content' => [
                ['type' => 'thinking', 'thinking' => 'hidden reasoning'],
                ['type' => 'text', 'text' => 'I will **inspect** it.'],
                ['type' => 'toolCall', 'id' => 'call-1', 'name' => 'shell', 'arguments' => ['cmd' => 'pwd']],
            ]]],
            ['type' => 'message', 'id' => 'result-1', 'parentId' => 'assistant-1', 'timestamp' => '2026-01-01T00:00:04Z', 'message' => ['role' => 'toolResult', 'toolCallId' => 'call-1', 'toolName' => 'shell', 'content' => [['type' => 'text', 'text' => '/tmp']], 'isError' => false]],
            ['type' => 'custom_message', 'id' => 'custom-1', 'parentId' => 'result-1', 'timestamp' => '2026-01-01T00:00:05Z', 'customType' => 'agent_message', 'display' => true, 'content' => 'wrapped', 'details' => ['message' => 'Follow up']],
            ['type' => 'compaction', 'id' => 'compact-1', 'parentId' => 'custom-1', 'timestamp' => '2026-01-01T00:00:06Z', 'summary' => 'Earlier work summarized.', 'firstKeptEntryId' => 'user-1', 'tokensBefore' => 100],
            "{invalid-json",
        ]);

        try {
            $result = (new AgentTranscriptReader)->read(['id' => 'session-1', 'sessionFile' => $path]);

            $this->assertTrue($result['available']);
            $this->assertCount(6, $result['items']);
            $this->assertSame(['message', 'thinking', 'message', 'tool', 'message', 'message'], array_column($result['items'], 'type'));
            $this->assertSame(['user', null, 'assistant', null, 'user', 'system'], array_map(
                fn (array $item): mixed => $item['role'] ?? null,
                $result['items']
            ));
            $this->assertStringNotContainsString('<script>', $result['items'][0]['html']);
            $this->assertStringContainsString('<strong>Build it</strong>', $result['items'][0]['html']);
            $this->assertStringContainsString('hidden reasoning', $result['items'][1]['html']);
            $this->assertSame('hidden reasoning', $result['items'][1]['summary']);
            $this->assertSame('shell', $result['items'][3]['name']);
            $this->assertSame('/tmp', $result['items'][3]['output']);
            $this->assertSame('Follow up', $result['items'][4]['text']);
            $this->assertSame('Conversation compacted', $result['items'][5]['label']);
            $this->assertStringNotContainsString('Discarded branch', json_encode($result['items']) ?: '');
        } finally {
            File::delete($path);
        }
    }

    public function test_it_extracts_safe_attachment_metadata_without_returning_paths_or_image_data(): void
    {
        $attachmentId = '123e4567-e89b-12d3-a456-426614174000';
        $metadata = json_encode([[
            'id' => $attachmentId,
            'name' => 'diagram.png',
            'mimeType' => 'image/png',
            'size' => 1234,
            'image' => true,
            'path' => '/private/agent-uploads/diagram.png',
        ]], JSON_UNESCAPED_SLASHES);
        $path = $this->transcript([
            ['type' => 'session', 'id' => 'session-1'],
            ['type' => 'message', 'id' => 'user', 'parentId' => null, 'message' => ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => "Please inspect this.\n\n<prime-agent-web-attachments>{$metadata}</prime-agent-web-attachments>"],
                ['type' => 'image', 'mimeType' => 'image/png', 'data' => 'very-secret-base64'],
            ]]],
        ]);

        try {
            $reader = new AgentTranscriptReader;
            $result = $reader->read(['id' => 'session-1', 'sessionFile' => $path]);
            $item = $result['items'][0];

            $this->assertSame('Please inspect this.', $item['text']);
            $this->assertSame([[
                'id' => $attachmentId,
                'name' => 'diagram.png',
                'mimeType' => 'image/png',
                'size' => 1234,
                'image' => true,
            ]], $item['attachments']);
            $this->assertStringNotContainsString('/private/', json_encode($item) ?: '');
            $this->assertStringNotContainsString('very-secret-base64', json_encode($item) ?: '');
            $agent = $reader->withDisplayTitle(['firstMessage' => "Please inspect this.\n{$metadata}<prime-agent-web-attachments>"], $result);
            $this->assertSame('Please inspect this.', $agent['firstMessage']);
        } finally {
            File::delete($path);
        }
    }

    public function test_it_summarizes_thinking_and_ipython_activity_with_structured_details(): void
    {
        $path = $this->transcript([
            ['type' => 'session', 'id' => 'session-1'],
            ['type' => 'message', 'id' => 'assistant', 'parentId' => null, 'message' => ['role' => 'assistant', 'content' => [
                ['type' => 'thinking', 'thinking' => "**Inspecting repository**\nNotes\n\n## Writing the fix"],
                ['type' => 'toolCall', 'id' => 'call-1', 'name' => 'ipython', 'arguments' => ['code' => "%%bash\nprintf 'hello'\nprintf 'world'"]],
            ]]],
            ['type' => 'message', 'id' => 'result', 'parentId' => 'assistant', 'message' => [
                'role' => 'toolResult', 'toolCallId' => 'call-1', 'content' => [], 'isError' => false,
                'details' => ['stdout' => "hello\nworld\n", 'durationMs' => 1250, 'diffs' => [['path' => 'app.php']]],
            ]],
        ]);

        try {
            $result = (new AgentTranscriptReader)->read(['id' => 'session-1', 'sessionFile' => $path]);
            $thinking = $result['items'][0];
            $tool = $result['items'][1];

            $this->assertSame('Writing the fix', $thinking['summary']);
            $this->assertSame('bash', $tool['language']);
            $this->assertSame("printf 'hello'", $tool['preview']);
            $this->assertSame(3, $tool['inputLines']);
            $this->assertSame(2, $tool['outputLines']);
            $this->assertSame(1250, $tool['durationMs']);
            $this->assertSame('completed', $tool['status']);
            $this->assertStringContainsString('app.php', $tool['diffs']);
        } finally {
            File::delete($path);
        }
    }

    public function test_it_classifies_subagent_messages_separately_from_client_input(): void
    {
        $path = $this->transcript([
            ['type' => 'session', 'id' => 'session-1'],
            ['type' => 'custom_message', 'id' => 'client', 'parentId' => null, 'customType' => 'agent_message', 'details' => ['message' => 'Continue', 'from' => ['clientId' => 'web']]],
            ['type' => 'custom_message', 'id' => 'child', 'parentId' => 'client', 'customType' => 'agent_message', 'details' => [
                'message' => 'Repository inspection is complete.', 'fromRelationship' => 'child',
                'from' => ['sessionId' => 'child-1', 'sessionName' => 'catalog-reviewer'],
            ]],
        ]);

        try {
            $items = (new AgentTranscriptReader)->read(['id' => 'session-1', 'sessionFile' => $path])['items'];
            $this->assertSame('user', $items[0]['role']);
            $this->assertSame('agent_message', $items[1]['type']);
            $this->assertSame('catalog-reviewer', $items[1]['sender']);
            $this->assertSame('child', $items[1]['relationship']);
        } finally {
            File::delete($path);
        }
    }

    public function test_it_adds_streaming_activity_and_versions_live_state(): void
    {
        $path = $this->transcript([['type' => 'session', 'id' => 'session-1']]);
        $reader = new AgentTranscriptReader;
        $base = ['id' => 'session-1', 'sessionFile' => $path, 'activity' => 'working', 'isStreaming' => true];
        $streaming = ['role' => 'assistant', 'content' => [
            ['type' => 'thinking', 'thinking' => '**Designing the timeline**'],
            ['type' => 'toolCall', 'id' => 'live-call', 'name' => 'ipython', 'arguments' => ['code' => 'pathlib.Path.cwd()']],
        ]];

        try {
            $first = $reader->read($base + ['streamingMessage' => $streaming]);
            $second = $reader->read($base + ['isCompacting' => true, 'streamingMessage' => $streaming]);

            $this->assertCount(2, $first['items']);
            $this->assertTrue($first['items'][0]['current']);
            $this->assertSame('running', $first['items'][1]['status']);
            $this->assertSame('thinking', $first['currentActivity']['kind']);
            $this->assertSame('Designing the timeline', $first['currentActivity']['detail']);
            $this->assertSame('compacting', $second['currentActivity']['kind']);
            $this->assertNotSame($first['version'], $second['version']);
        } finally {
            File::delete($path);
        }
    }

    public function test_it_reports_missing_transcripts_and_truncates_large_tool_output(): void
    {
        $missing = (new AgentTranscriptReader)->read(['id' => 'missing', 'sessionFile' => '/not/a/transcript']);
        $this->assertFalse($missing['available']);

        $path = $this->transcript([
            ['type' => 'session', 'id' => 'session-1', 'timestamp' => '2026-01-01T00:00:00Z', 'cwd' => '/tmp'],
            ['type' => 'message', 'id' => 'result', 'parentId' => null, 'timestamp' => '2026-01-01T00:00:01Z', 'message' => ['role' => 'toolResult', 'toolCallId' => 'orphan', 'toolName' => 'shell', 'content' => [['type' => 'text', 'text' => str_repeat('x', 40000)]], 'isError' => true]],
        ]);

        try {
            $result = (new AgentTranscriptReader)->read(['id' => 'session-1', 'sessionFile' => $path]);
            $this->assertTrue($result['items'][0]['truncated']);
            $this->assertTrue($result['items'][0]['error']);
            $this->assertSame(32768, strlen($result['items'][0]['output']));
        } finally {
            File::delete($path);
        }
    }

    /** @param list<array<string, mixed>|string> $entries */
    private function transcript(array $entries): string
    {
        $path = sys_get_temp_dir().'/prime-agent-transcript-'.bin2hex(random_bytes(6)).'.jsonl';
        $lines = array_map(fn (array|string $entry): string => is_string($entry) ? $entry : (json_encode($entry, JSON_UNESCAPED_SLASHES) ?: ''), $entries);
        File::put($path, implode("\n", $lines)."\n");

        return $path;
    }
}
