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
            $this->assertCount(5, $result['items']);
            $this->assertSame(['user', null, 'assistant', 'user', 'system'], array_map(
                fn (array $item): mixed => $item['role'] ?? null,
                $result['items']
            ));
            $this->assertStringNotContainsString('<script>', $result['items'][0]['html']);
            $this->assertStringContainsString('<strong>Build it</strong>', $result['items'][0]['html']);
            $this->assertStringNotContainsString('hidden reasoning', $result['items'][2]['html']);
            $this->assertSame('shell', $result['items'][1]['name']);
            $this->assertSame('/tmp', $result['items'][1]['output']);
            $this->assertSame('Follow up', $result['items'][3]['text']);
            $this->assertSame('Conversation compacted', $result['items'][4]['label']);
            $this->assertStringNotContainsString('Discarded branch', json_encode($result['items']) ?: '');
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
