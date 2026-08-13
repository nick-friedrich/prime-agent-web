<?php

namespace App\Services;

use Illuminate\Support\Str;

class AgentTranscriptReader
{
    private const TOOL_OUTPUT_LIMIT = 32768;

    /**
     * @param  array<string, mixed>  $agent
     * @return array{available: bool, items: list<array<string, mixed>>, version: string, error: string|null}
     */
    public function read(array $agent): array
    {
        $sessionFile = $agent['sessionFile'] ?? null;
        if (! is_string($sessionFile) || $sessionFile === '' || ! is_file($sessionFile) || ! is_readable($sessionFile)) {
            return [
                'available' => false,
                'items' => [],
                'version' => $this->version($agent, null),
                'error' => 'The transcript file is not available yet.',
            ];
        }

        $handle = fopen($sessionFile, 'rb');
        if ($handle === false) {
            return [
                'available' => false,
                'items' => [],
                'version' => $this->version($agent, null),
                'error' => 'The transcript file could not be opened.',
            ];
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

        return [
            'available' => true,
            'items' => $this->normalize($this->currentBranch($entries)),
            'version' => $this->version($agent, $sessionFile),
            'error' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function currentBranch(array $entries): array
    {
        $sessionEntries = array_values(array_filter(
            $entries,
            fn (array $entry): bool => ($entry['type'] ?? null) !== 'session'
        ));

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
                continue;
            }

            if ($type === 'custom_message') {
                $this->appendCustom($items, $entry, $id, $timestamp);
                continue;
            }

            if ($type !== 'message' || ! is_array($entry['message'] ?? null)) {
                continue;
            }

            /** @var array<string, mixed> $message */
            $message = $entry['message'];
            $role = $message['role'] ?? null;
            if ($role === 'user') {
                $text = $this->contentText($message['content'] ?? null);
                if ($text !== '') {
                    $items[] = $this->textItem($id, 'user', $text, $timestamp);
                }
                continue;
            }

            if ($role === 'custom') {
                $this->appendCustom($items, $message, $id, $timestamp);
                continue;
            }

            if ($role === 'bashExecution') {
                $output = is_string($message['output'] ?? null) ? $message['output'] : '';
                $command = is_string($message['command'] ?? null) ? $message['command'] : 'Shell command';
                $items[] = $this->toolItem($id, null, $command, '', $output, (bool) ($message['cancelled'] ?? false), $timestamp);
                continue;
            }

            if ($role === 'assistant') {
                $parts = is_array($message['content'] ?? null) ? $message['content'] : [];
                $textParts = [];
                foreach ($parts as $part) {
                    if (! is_array($part)) {
                        continue;
                    }
                    if (($part['type'] ?? null) === 'text' && is_string($part['text'] ?? null)) {
                        $textParts[] = $part['text'];
                    }
                    if (($part['type'] ?? null) === 'toolCall') {
                        $callId = is_string($part['id'] ?? null) ? $part['id'] : null;
                        $name = is_string($part['name'] ?? null) ? $part['name'] : 'Tool';
                        $arguments = $this->prettyValue($part['arguments'] ?? null);
                        $toolId = $id.'-tool-'.count($items);
                        $items[] = $this->toolItem($toolId, $callId, $name, $arguments, '', false, $timestamp);
                        if ($callId !== null) {
                            $toolsByCallId[$callId] = array_key_last($items);
                        }
                    }
                }
                $text = trim(implode("\n\n", $textParts));
                if ($text !== '') {
                    $items[] = $this->textItem($id, 'assistant', $text, $timestamp);
                }
                continue;
            }

            if ($role === 'toolResult') {
                $callId = is_string($message['toolCallId'] ?? null) ? $message['toolCallId'] : null;
                $output = $this->contentText($message['content'] ?? null);
                if ($callId !== null && isset($toolsByCallId[$callId])) {
                    $index = $toolsByCallId[$callId];
                    [$output, $truncated] = $this->truncate($output);
                    $items[$index]['output'] = $output;
                    $items[$index]['truncated'] = $truncated;
                    $items[$index]['error'] = (bool) ($message['isError'] ?? false);
                } else {
                    $name = is_string($message['toolName'] ?? null) ? $message['toolName'] : 'Tool result';
                    $items[] = $this->toolItem($id, $callId, $name, '', $output, (bool) ($message['isError'] ?? false), $timestamp);
                }
            }
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $message
     */
    private function appendCustom(array &$items, array $message, string $id, ?string $timestamp): void
    {
        if (($message['display'] ?? true) === false) {
            return;
        }

        if (($message['customType'] ?? null) === 'agent_message' && is_array($message['details'] ?? null)) {
            $text = $message['details']['message'] ?? null;
            if (is_string($text) && $text !== '') {
                $items[] = $this->textItem($id, 'user', $text, $timestamp);
            }
            return;
        }

        $text = $this->contentText($message['content'] ?? null);
        if ($text !== '') {
            $items[] = $this->textItem($id, 'system', $text, $timestamp, 'Agent activity');
        }
    }

    /** @return array<string, mixed> */
    private function textItem(string $id, string $role, string $text, ?string $timestamp, ?string $label = null): array
    {
        return [
            'id' => $id,
            'type' => 'message',
            'role' => $role,
            'text' => $text,
            'html' => Str::markdown($text, ['html_input' => 'escape', 'allow_unsafe_links' => false]),
            'label' => $label,
            'timestamp' => $timestamp,
        ];
    }

    /** @return array<string, mixed> */
    private function toolItem(string $id, ?string $callId, string $name, string $arguments, string $output, bool $error, ?string $timestamp): array
    {
        [$output, $truncated] = $this->truncate($output);

        return [
            'id' => $id,
            'type' => 'tool',
            'callId' => $callId,
            'name' => $name,
            'arguments' => $arguments,
            'output' => $output,
            'error' => $error,
            'truncated' => $truncated,
            'timestamp' => $timestamp,
        ];
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

    /** @return array{string, bool} */
    private function truncate(string $value): array
    {
        if (strlen($value) <= self::TOOL_OUTPUT_LIMIT) {
            return [$value, false];
        }

        return [substr($value, 0, self::TOOL_OUTPUT_LIMIT), true];
    }

    /** @param array<string, mixed> $agent */
    private function version(array $agent, ?string $sessionFile): string
    {
        $stat = $sessionFile !== null ? @stat($sessionFile) : false;
        $identity = [
            $agent['id'] ?? null,
            $agent['activeSessionId'] ?? null,
            $agent['activity'] ?? null,
            $agent['lifecycle'] ?? null,
            is_array($stat) ? $stat['size'] : null,
            is_array($stat) ? $stat['mtime'] : null,
        ];

        return hash('sha256', (string) json_encode($identity));
    }
}
