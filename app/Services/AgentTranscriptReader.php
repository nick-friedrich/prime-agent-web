<?php

namespace App\Services;

use Illuminate\Support\Str;

class AgentTranscriptReader
{
    private const TOOL_OUTPUT_LIMIT = 32768;

    /**
     * @param  array<string, mixed>  $agent
     * @return array{available: bool, items: list<array<string, mixed>>, currentActivity: array<string, mixed>, version: string, error: string|null}
     */
    public function read(array $agent): array
    {
        $sessionFile = $agent['sessionFile'] ?? null;
        if (! is_string($sessionFile) || $sessionFile === '' || ! is_file($sessionFile) || ! is_readable($sessionFile)) {
            return $this->result($agent, null, [], false, 'The transcript file is not available yet.');
        }

        $handle = fopen($sessionFile, 'rb');
        if ($handle === false) {
            return $this->result($agent, null, [], false, 'The transcript file could not be opened.');
        }

        /** @var list<array<string, mixed>> $entries */
        $entries = [];
        try {
            while (($line = fgets($handle)) !== false) {
                $entry = json_decode($line, true);
                if (is_array($entry)) {
                    /** @var array<string, mixed> $entry */
                    $entries[] = $entry;
                }
            }
        } finally {
            fclose($handle);
        }

        return $this->result($agent, $sessionFile, $this->normalize($this->currentBranch($entries)), true, null);
    }

    /**
     * @param  array<string, mixed>  $agent
     * @param  list<array<string, mixed>>  $items
     * @return array{available: bool, items: list<array<string, mixed>>, currentActivity: array<string, mixed>, version: string, error: string|null}
     */
    private function result(array $agent, ?string $sessionFile, array $items, bool $available, ?string $error): array
    {
        $items = $this->appendStreamingItems($items, $agent);
        $activity = $this->currentActivity($agent, $items);

        return [
            'available' => $available,
            'items' => $items,
            'currentActivity' => $activity,
            'version' => $this->version($agent, $sessionFile),
            'error' => $error,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function currentBranch(array $entries): array
    {
        $sessionEntries = array_values(array_filter($entries, fn (array $entry): bool => ($entry['type'] ?? null) !== 'session'));
        if ($sessionEntries === []) {
            return [];
        }

        /** @var array<string, array<string, mixed>> $byId */
        $byId = [];
        foreach ($sessionEntries as $entry) {
            $id = $entry['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $byId[$id] = $entry;
            }
        }
        if ($byId === []) {
            return $sessionEntries;
        }

        $branch = [];
        $current = $sessionEntries[array_key_last($sessionEntries)];
        $seen = [];
        while (true) {
            $id = $current['id'] ?? null;
            if (! is_string($id) || isset($seen[$id])) {
                break;
            }
            $seen[$id] = true;
            $branch[] = $current;
            $parentId = $current['parentId'] ?? null;
            if (! is_string($parentId) || ! isset($byId[$parentId])) {
                break;
            }
            $current = $byId[$parentId];
        }

        return array_reverse($branch);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function normalize(array $entries): array
    {
        /** @var list<array<string, mixed>> $items */
        $items = [];
        /** @var array<string, int> $toolsByCallId */
        $toolsByCallId = [];

        foreach ($entries as $entry) {
            $type = $entry['type'] ?? null;
            $id = is_string($entry['id'] ?? null) ? $entry['id'] : Str::uuid()->toString();
            $timestamp = is_string($entry['timestamp'] ?? null) ? $entry['timestamp'] : null;

            if ($type === 'compaction' && is_string($entry['summary'] ?? null)) {
                $items[] = $this->textItem($id, 'system', $entry['summary'], $timestamp, 'Conversation compacted');
            } elseif ($type === 'custom_message') {
                $this->appendCustom($items, $entry, $id, $timestamp);
            } elseif ($type === 'message' && is_array($entry['message'] ?? null)) {
                /** @var array<string, mixed> $message */
                $message = $entry['message'];
                $this->appendMessage($items, $toolsByCallId, $message, $id, $timestamp);
            }
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param-out list<array<string, mixed>> $items
     * @param  array<string, int>  $toolsByCallId
     * @param-out array<string, int> $toolsByCallId
     * @param  array<string, mixed>  $message
     */
    private function appendMessage(array &$items, array &$toolsByCallId, array $message, string $id, ?string $timestamp): void
    {
        $role = $message['role'] ?? null;
        if ($role === 'user') {
            $text = $this->contentText($message['content'] ?? null);
            if ($text !== '') {
                $items[] = $this->textItem($id, 'user', $text, $timestamp);
            }
            return;
        }
        if ($role === 'custom') {
            $this->appendCustom($items, $message, $id, $timestamp);
            return;
        }
        if ($role === 'bashExecution') {
            $output = is_string($message['output'] ?? null) ? $message['output'] : '';
            $command = is_string($message['command'] ?? null) ? $message['command'] : 'Shell command';
            $items[] = $this->toolItem($id, null, 'shell', ['cmd' => $command], $output, (bool) ($message['cancelled'] ?? false), $timestamp, []);
            return;
        }
        if ($role === 'assistant') {
            $parts = is_array($message['content'] ?? null) ? $message['content'] : [];
            foreach ($parts as $partIndex => $part) {
                if (! is_array($part)) {
                    continue;
                }
                $partId = $id.'-part-'.$partIndex;
                if (($part['type'] ?? null) === 'thinking') {
                    $thinking = is_string($part['thinking'] ?? null) ? trim($part['thinking']) : '';
                    if ($thinking !== '') {
                        $items[] = $this->thinkingItem($partId, $thinking, $timestamp);
                    }
                } elseif (($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null) && trim($part['text']) !== '') {
                    $items[] = $this->textItem($partId, 'assistant', trim($part['text']), $timestamp);
                } elseif (($part['type'] ?? null) === 'toolCall') {
                    $callId = is_string($part['id'] ?? null) ? $part['id'] : null;
                    $name = is_string($part['name'] ?? null) ? $part['name'] : 'Tool';
                    $arguments = $this->stringKeyedArray($part['arguments'] ?? null);
                    if ($arguments === []) {
                        $arguments = ['value' => $part['arguments'] ?? null];
                    }
                    $items[] = $this->toolItem($partId, $callId, $name, $arguments, '', false, $timestamp, []);
                    if ($callId !== null) {
                        $toolsByCallId[$callId] = array_key_last($items);
                    }
                }
            }
            return;
        }
        if ($role !== 'toolResult') {
            return;
        }

        $callId = is_string($message['toolCallId'] ?? null) ? $message['toolCallId'] : null;
        $output = $this->contentText($message['content'] ?? null);
        $details = $this->stringKeyedArray($message['details'] ?? null);
        if ($output === '') {
            $output = $this->resultOutput($details);
        }
        if ($callId !== null && isset($toolsByCallId[$callId])) {
            $items[$toolsByCallId[$callId]] = $this->completeTool($items[$toolsByCallId[$callId]], $output, (bool) ($message['isError'] ?? false), $details);
        } else {
            $name = is_string($message['toolName'] ?? null) ? $message['toolName'] : 'Tool result';
            $items[] = $this->toolItem($id, $callId, $name, [], $output, (bool) ($message['isError'] ?? false), $timestamp, $details);
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param-out list<array<string, mixed>> $items
     * @param array<string, mixed> $message
     */
    private function appendCustom(array &$items, array $message, string $id, ?string $timestamp): void
    {
        if (($message['display'] ?? true) === false) {
            return;
        }
        if (($message['customType'] ?? null) === 'agent_message' && is_array($message['details'] ?? null)) {
            $details = $message['details'];
            $text = $details['message'] ?? null;
            if (! is_string($text) || trim($text) === '') {
                return;
            }
            $from = is_array($details['from'] ?? null) ? $details['from'] : [];
            $isAgent = isset($from['sessionId']) || isset($from['activeSessionId']) || isset($details['fromRelationship']);
            if (! $isAgent) {
                $items[] = $this->textItem($id, 'user', trim($text), $timestamp);
                return;
            }
            $sender = $from['sessionName'] ?? $from['name'] ?? $from['activeSessionId'] ?? $from['sessionId'] ?? 'subagent';
            $sender = is_string($sender) ? $sender : 'subagent';
            $items[] = [
                'id' => $id, 'type' => 'agent_message', 'sender' => $sender,
                'relationship' => is_string($details['fromRelationship'] ?? null) ? $details['fromRelationship'] : null,
                'preview' => Str::limit(preg_replace('/\s+/', ' ', trim($text)) ?? trim($text), 120),
                'text' => trim($text), 'html' => $this->markdown($text), 'timestamp' => $timestamp,
            ];
            return;
        }

        $text = $this->contentText($message['content'] ?? null);
        if ($text !== '') {
            $items[] = $this->textItem($id, 'system', $text, $timestamp, 'Agent activity');
        }
    }

    /** @return array<string, mixed> */
    private function thinkingItem(string $id, string $thinking, ?string $timestamp): array
    {
        return ['id' => $id, 'type' => 'thinking', 'summary' => $this->thinkingRecap($thinking), 'html' => $this->markdown($thinking), 'timestamp' => $timestamp, 'current' => false];
    }

    private function thinkingRecap(string $thinking): string
    {
        $candidates = [];
        if (preg_match_all('/(?:^|\n)\s{0,3}#{1,6}\s+(.+?)\s*(?=\n|$)|\*\*(.+?)\*\*/s', $thinking, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $candidates[] = trim(($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? ''));
            }
        }
        $recap = $candidates !== [] ? $candidates[array_key_last($candidates)] : $thinking;
        $recap = preg_replace('/[`*_~#>\[\]]+/', '', $recap) ?? $recap;
        $recap = preg_replace('/\s+/', ' ', trim($recap)) ?? trim($recap);

        return Str::limit($recap !== '' ? $recap : 'Thinking', 140);
    }

    /** @return array<string, mixed> */
    private function textItem(string $id, string $role, string $text, ?string $timestamp, ?string $label = null): array
    {
        return ['id' => $id, 'type' => 'message', 'role' => $role, 'text' => $text, 'html' => $this->markdown($text), 'label' => $label, 'timestamp' => $timestamp];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function toolItem(string $id, ?string $callId, string $name, array $arguments, string $output, bool $error, ?string $timestamp, array $details): array
    {
        [$output, $truncated] = $this->truncate($output);
        $code = is_string($arguments['code'] ?? null) ? $arguments['code'] : (is_string($arguments['cmd'] ?? null) ? $arguments['cmd'] : '');
        $language = $name === 'ipython' ? (str_starts_with(ltrim($code), '%%bash') ? 'bash' : 'python') : $name;
        $diffs = isset($details['diffs']) ? $this->prettyValue($details['diffs']) : '';
        $duration = $details['durationMs'] ?? null;
        $durationMs = is_int($duration) || is_float($duration) ? (int) $duration : null;

        return [
            'id' => $id, 'type' => 'tool', 'callId' => $callId, 'name' => $name, 'language' => $language,
            'preview' => $this->toolPreview($name, $arguments), 'arguments' => $this->prettyValue($arguments),
            'output' => $output, 'diffs' => $diffs, 'inputLines' => $this->lineCount($code),
            'outputLines' => $this->lineCount($output), 'durationMs' => $durationMs,
            'status' => $error ? 'failed' : ($output !== '' || $details !== [] ? 'completed' : 'running'),
            'error' => $error, 'errorName' => $error ? $this->errorName($details, $output) : null,
            'truncated' => $truncated, 'timestamp' => $timestamp, 'current' => false,
        ];
    }

    /**
     * @param array<string, mixed> $tool
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function completeTool(array $tool, string $output, bool $error, array $details): array
    {
        [$output, $truncated] = $this->truncate($output);
        $duration = $details['durationMs'] ?? null;
        $tool['output'] = $output;
        $tool['outputLines'] = $this->lineCount($output);
        $tool['durationMs'] = is_int($duration) || is_float($duration) ? (int) $duration : null;
        $tool['diffs'] = isset($details['diffs']) ? $this->prettyValue($details['diffs']) : '';
        $tool['status'] = $error ? 'failed' : 'completed';
        $tool['error'] = $error;
        $tool['errorName'] = $error ? $this->errorName($details, $output) : null;
        $tool['truncated'] = $truncated;

        return $tool;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @param array<string, mixed> $agent
     * @return list<array<string, mixed>>
     */
    private function appendStreamingItems(array $items, array $agent): array
    {
        $streaming = $agent['streamingMessage'] ?? null;
        if (! is_array($streaming)) {
            return $items;
        }
        $message = $this->stringKeyedArray($streaming['message'] ?? $streaming);
        if (($message['role'] ?? null) !== 'assistant' && ! isset($message['content'])) {
            return $items;
        }
        $agentIdentity = $agent['activeSessionId'] ?? $agent['id'] ?? 'agent';
        $agentIdentity = is_string($agentIdentity) ? $agentIdentity : 'agent';
        $streamId = is_string($streaming['id'] ?? null) ? $streaming['id'] : 'live-'.$agentIdentity;
        foreach ($items as $item) {
            $itemId = $item['id'] ?? null;
            if (is_string($itemId) && str_starts_with($itemId, $streamId)) {
                return $items;
            }
        }
        /** @var array<string, int> $toolIds */
        $toolIds = [];
        $before = count($items);
        $this->appendMessage($items, $toolIds, $message, $streamId, null);
        for ($index = $before; $index < count($items); $index++) {
            $items[$index]['current'] = true;
            if (($items[$index]['type'] ?? null) === 'tool') {
                $items[$index]['status'] = 'running';
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $agent
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    private function currentActivity(array $agent, array $items): array
    {
        /** @var array<string, mixed>|null $latestThinking */
        $latestThinking = null;
        /** @var array<string, mixed>|null $latestTool */
        $latestTool = null;
        foreach (array_reverse($items) as $item) {
            if ($latestThinking === null && ($item['type'] ?? null) === 'thinking') {
                $latestThinking = $item;
            }
            if ($latestTool === null && ($item['type'] ?? null) === 'tool') {
                $latestTool = $item;
            }
        }
        if ((bool) ($agent['isCompacting'] ?? false)) {
            return $this->activity('compacting', 'Compacting conversation', null, null, true);
        }
        if ((bool) ($agent['isStreaming'] ?? false) && $latestThinking !== null) {
            return $this->activity('thinking', 'Thinking', $this->stringField($latestThinking, 'summary'), $this->stringField($latestThinking, 'id'), true);
        }
        if ((bool) ($agent['isRunningTools'] ?? false) || (bool) ($agent['isBashRunning'] ?? false)) {
            $toolName = $latestTool === null ? null : ($this->stringField($latestTool, 'language') ?? $this->stringField($latestTool, 'name'));
            return $this->activity('tool', 'Executing '.($toolName ?? 'tool'), $latestTool === null ? null : $this->stringField($latestTool, 'preview'), $latestTool === null ? null : $this->stringField($latestTool, 'id'), true);
        }
        if ((bool) ($agent['isStreaming'] ?? false)) {
            return $this->activity('writing', 'Writing response', null, null, true);
        }
        if ((bool) ($agent['hasRunningRlmChildren'] ?? false)) {
            return $this->activity('waiting', 'Waiting for subagents', null, null, true);
        }
        if (($agent['activity'] ?? null) === 'working') {
            return $this->activity('working', 'Working', $latestThinking === null ? null : $this->stringField($latestThinking, 'summary'), $latestThinking === null ? null : $this->stringField($latestThinking, 'id'), true);
        }
        $summary = is_string($agent['summary'] ?? null) && trim($agent['summary']) !== '' ? trim($agent['summary']) : null;
        $taskState = is_string($agent['taskState'] ?? null) ? $agent['taskState'] : null;
        $label = $taskState === 'completed' ? 'Completed' : 'Ready for input';

        return $this->activity('idle', $label, $summary, null, false);
    }

    /** @return array<string, mixed> */
    private function activity(string $kind, string $label, ?string $detail, ?string $itemId, bool $active): array
    {
        return ['kind' => $kind, 'label' => $label, 'detail' => $detail !== '' ? $detail : null, 'itemId' => $itemId !== '' ? $itemId : null, 'active' => $active];
    }

    /** @param array<string, mixed> $value */
    private function stringField(array $value, string $key): ?string
    {
        return is_string($value[$key] ?? null) ? $value[$key] : null;
    }

    /** @param array<string, mixed> $arguments */
    private function toolPreview(string $name, array $arguments): string
    {
        $code = $arguments['code'] ?? $arguments['cmd'] ?? null;
        if (is_string($code)) {
            $lines = preg_split('/\R/', trim($code)) ?: [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line !== '' && ! str_starts_with($line, '%%')) {
                    return Str::limit($line, 120);
                }
            }
        }
        foreach ($arguments as $key => $value) {
            if (is_scalar($value) && (string) $value !== '') {
                return Str::limit($key.'='.((string) $value), 120);
            }
        }

        return ucfirst($name);
    }

    /** @param array<string, mixed> $details */
    private function resultOutput(array $details): string
    {
        $parts = [];
        foreach (['stdout', 'stderr', 'result'] as $key) {
            if (is_string($details[$key] ?? null) && $details[$key] !== '') {
                $parts[] = $details[$key];
            }
        }

        return trim(implode("\n", $parts));
    }

    /** @param array<string, mixed> $details */
    private function errorName(array $details, string $output): string
    {
        foreach (['errorName', 'name', 'status'] as $key) {
            if (is_string($details[$key] ?? null) && $details[$key] !== '' && $details[$key] !== 'error') {
                return Str::limit($details[$key], 80);
            }
        }
        $line = trim((preg_split('/\R/', $output) ?: ['Tool failed'])[0]);

        return Str::limit($line !== '' ? $line : 'Tool failed', 80);
    }

    private function lineCount(string $value): int
    {
        return $value === '' ? 0 : substr_count(rtrim($value), "\n") + 1;
    }

    private function contentText(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }
        if (! is_array($content)) {
            return '';
        }
        $parts = [];
        foreach ($content as $part) {
            if (! is_array($part)) {
                continue;
            }
            if (($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                $parts[] = $part['text'];
            } elseif (($part['type'] ?? null) === 'image') {
                $parts[] = '[Image attachment]';
            }
        }

        return trim(implode("\n", $parts));
    }

    private function markdown(string $value): string
    {
        return Str::markdown($value, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    private function prettyValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '' : $encoded;
    }

    /** @return array<string, mixed> */
    private function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /** @return array{string, bool} */
    private function truncate(string $value): array
    {
        return strlen($value) <= self::TOOL_OUTPUT_LIMIT ? [$value, false] : [substr($value, 0, self::TOOL_OUTPUT_LIMIT), true];
    }

    /** @param array<string, mixed> $agent */
    private function version(array $agent, ?string $sessionFile): string
    {
        $stat = $sessionFile !== null ? @stat($sessionFile) : false;
        $liveKeys = ['id', 'activeSessionId', 'activity', 'lifecycle', 'isStreaming', 'isRunningTools', 'isCompacting', 'isBashRunning', 'hasRunningRlmChildren', 'unfinishedActionCount', 'taskState', 'summary', 'streamingMessage'];
        $identity = [];
        foreach ($liveKeys as $key) {
            $identity[$key] = $agent[$key] ?? null;
        }
        $identity['fileSize'] = is_array($stat) ? $stat['size'] : null;
        $identity['fileMtime'] = is_array($stat) ? $stat['mtime'] : null;

        return hash('sha256', (string) json_encode($identity));
    }
}
