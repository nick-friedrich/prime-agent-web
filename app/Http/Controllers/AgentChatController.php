<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\AgentTranscriptReader;
use App\Services\PrimeAgentRuntime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class AgentChatController extends Controller
{
    public function __construct(
        private readonly PrimeAgentRuntime $runtime,
        private readonly AgentTranscriptReader $transcripts,
    ) {}

    public function show(string $sessionId): View
    {
        $agent = $this->resolveAgent($sessionId);
        $transcript = $this->transcripts->read($agent);

        return view('agents.show', [
            'agent' => $agent,
            'agentPayload' => $this->agentMetadata($agent),
            'sessionId' => $sessionId,
            'transcript' => $transcript,
            'projects' => Project::orderBy('name')->get(),
            'daemonOnline' => true,
            'primeAgentBinary' => $this->runtime->binary(),
        ]);
    }

    public function transcript(Request $request, string $sessionId): JsonResponse|Response
    {
        $agent = $this->resolveAgent($sessionId);
        $transcript = $this->transcripts->read($agent);
        $etag = '"'.$transcript['version'].'"';

        if ($request->header('If-None-Match') === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        return response()->json([
            'agent' => $this->agentMetadata($agent),
            'transcript' => $transcript,
        ])->header('ETag', $etag);
    }

    public function storeMessage(Request $request, string $sessionId): JsonResponse
    {
        $request->validate([
            'message' => ['required', 'string', 'max:16384'],
        ]);
        $message = $request->string('message')->trim()->toString();
        $agent = $this->resolveAgent($sessionId);
        $target = $agent['activeSessionId'] ?? $agent['id'] ?? null;
        abort_unless(is_string($target) && $target !== '', 404);

        try {
            $receipt = $this->runtime->send($target, $message);
        } catch (\RuntimeException $error) {
            return response()->json(['message' => $error->getMessage()], 503);
        }

        return response()->json(['receipt' => $receipt], 202);
    }

    /** @return array<string, mixed> */
    private function resolveAgent(string $sessionId): array
    {
        abort_unless($this->runtime->isAvailable(), 503, 'Prime Agent is not installed.');
        $daemon = $this->runtime->ensureDaemon();
        abort_unless($daemon['online'], 503, $daemon['error'] ?: 'Prime Agent is offline.');

        foreach ($this->runtime->agents() as $agent) {
            if (($agent['id'] ?? null) === $sessionId || ($agent['activeSessionId'] ?? null) === $sessionId) {
                return $agent;
            }
        }

        abort(404);
    }

    /**
     * @param  array<string, mixed>  $agent
     * @return array<string, mixed>
     */
    private function agentMetadata(array $agent): array
    {
        return [
            'id' => $agent['id'] ?? null,
            'activeSessionId' => $agent['activeSessionId'] ?? null,
            'sessionName' => $agent['sessionName'] ?? null,
            'cwd' => $agent['cwd'] ?? null,
            'activity' => $agent['activity'] ?? null,
            'lifecycle' => $agent['lifecycle'] ?? null,
            'model' => $agent['model'] ?? null,
            'messageCount' => $agent['messageCount'] ?? 0,
            'isStreaming' => (bool) ($agent['isStreaming'] ?? false),
            'isRunningTools' => (bool) ($agent['isRunningTools'] ?? false),
            'isCompacting' => (bool) ($agent['isCompacting'] ?? false),
            'isBashRunning' => (bool) ($agent['isBashRunning'] ?? false),
            'hasRunningRlmChildren' => (bool) ($agent['hasRunningRlmChildren'] ?? false),
            'unfinishedActionCount' => $agent['unfinishedActionCount'] ?? 0,
            'taskState' => $agent['taskState'] ?? null,
            'summary' => $agent['summary'] ?? null,
        ];
    }
}
