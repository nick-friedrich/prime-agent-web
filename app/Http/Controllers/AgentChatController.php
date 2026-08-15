<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\AgentTranscriptReader;
use App\Services\PrimeAgentRuntime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AgentChatController extends Controller
{
    public function __construct(
        private readonly PrimeAgentRuntime $runtime,
        private readonly AgentTranscriptReader $transcripts,
    ) {}

    public function create(Request $request): View
    {
        $projects = Project::orderBy('name')->get();
        $selectedProject = $request->filled('project')
            ? $projects->firstWhere('slug', $request->string('project')->toString()) ?? $projects->first()
            : $projects->first();
        $primeAgentAvailable = $this->runtime->isAvailable();
        $daemon = $primeAgentAvailable
            ? $this->runtime->ensureDaemon()
            : ['online' => false, 'error' => null];

        return view('agents.create', [
            'projects' => $projects,
            'selectedProject' => $selectedProject,
            'primeAgentAvailable' => $primeAgentAvailable,
            'daemonOnline' => $daemon['online'],
            'daemonError' => $daemon['error'],
            'primeAgentBinary' => $this->runtime->binary(),
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'session_mode' => ['required', 'in:chat,goal'],
            'message' => ['nullable', 'string', 'max:16384'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240'],
        ]);

        $message = $request->string('message')->trim()->toString();
        $files = array_values(array_filter(
            $request->file('attachments', []),
            fn (UploadedFile $file): bool => $file->isValid(),
        ));
        $sessionMode = $request->string('session_mode')->toString();
        if ($message === '' && $files === []) {
            throw ValidationException::withMessages(['message' => 'Enter a message or attach at least one file.']);
        }
        if ($sessionMode === 'goal' && $message === '') {
            throw ValidationException::withMessages(['message' => 'Describe the goal before starting a goal session.']);
        }

        if (! $this->runtime->isAvailable()) {
            return $this->creationError($request, 'Prime Agent is not installed or is not visible to Laravel.');
        }
        $daemon = $this->runtime->ensureDaemon();
        if (! $daemon['online']) {
            return $this->creationError($request, $daemon['error'] ?: 'Prime Agent could not start.');
        }

        $project = Project::query()->findOrFail($request->integer('project_id'));
        $activeSessionId = null;
        $stableSessionId = null;
        try {
            $agent = $this->runtime->createSession(
                $project->path,
                $sessionMode,
                $sessionMode === 'goal' ? $message : null,
            );
            $activeSessionId = $agent['activeSessionId'];
            $agentId = $agent['id'] ?? null;
            $stableSessionId = is_string($agentId) && $agentId !== '' ? $agentId : $activeSessionId;

            $images = [];
            if ($files !== []) {
                [$attachmentPrompt, $images] = $this->storeAttachments($stableSessionId, $files);
                $message = trim($message."\n\n".$attachmentPrompt);
            }
            $files !== []
                ? $this->runtime->prompt($activeSessionId, $message, $images)
                : $this->runtime->send($activeSessionId, $message);
        } catch (\RuntimeException $error) {
            if (is_string($activeSessionId)) {
                try {
                    $this->runtime->stop($activeSessionId);
                } catch (\RuntimeException) {
                    // Preserve the original creation error.
                }
            }
            if (is_string($stableSessionId)) {
                Storage::disk('local')->deleteDirectory($this->attachmentDirectory($stableSessionId));
            }

            return $this->creationError($request, $error->getMessage());
        }

        $redirect = route('agents.show', ['sessionId' => $stableSessionId]);
        if ($request->expectsJson()) {
            return response()->json(['redirect' => $redirect], 201);
        }

        return redirect($redirect);
    }

    public function show(string $sessionId): View
    {
        $agent = $this->resolveAgent($sessionId);
        $transcript = $this->transcripts->read($agent);
        $agent = $this->transcripts->withDisplayTitle($agent, $transcript);

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
        $agent = $this->transcripts->withDisplayTitle($agent, $transcript);
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
            'message' => ['nullable', 'string', 'max:16384'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240'],
        ]);

        $message = $request->string('message')->trim()->toString();
        $files = array_values(array_filter(
            $request->file('attachments', []),
            fn (UploadedFile $file): bool => $file->isValid(),
        ));
        if ($message === '' && $files === []) {
            throw ValidationException::withMessages(['message' => 'Enter a message or attach at least one file.']);
        }

        $agent = $this->resolveAgent($sessionId);
        $target = $agent['activeSessionId'] ?? $agent['id'] ?? null;
        abort_unless(is_string($target) && $target !== '', 404);

        $images = [];
        if ($files !== []) {
            $stableSessionId = is_string($agent['id'] ?? null) ? $agent['id'] : $sessionId;
            [$attachmentPrompt, $images] = $this->storeAttachments($stableSessionId, $files);
            $message = trim($message."\n\n".$attachmentPrompt);
        }

        try {
            $activeSessionId = $agent['activeSessionId'] ?? null;
            $receipt = $files !== [] && is_string($activeSessionId) && $activeSessionId !== ''
                ? $this->runtime->prompt($activeSessionId, $message, $images)
                : $this->runtime->send($target, $message);
        } catch (\RuntimeException $error) {
            return response()->json(['message' => $error->getMessage()], 503);
        }

        return response()->json(['receipt' => $receipt], 202);
    }

    public function attachment(Request $request, string $sessionId, string $attachmentId): BinaryFileResponse
    {
        $agent = $this->resolveAgent($sessionId);
        abort_unless(Str::isUuid($attachmentId), 404);

        $stableSessionId = is_string($agent['id'] ?? null) ? $agent['id'] : $sessionId;
        $directory = $this->attachmentDirectory($stableSessionId).'/'.$attachmentId;
        $files = Storage::disk('local')->files($directory);
        abort_unless(count($files) === 1, 404);

        $path = Storage::disk('local')->path($files[0]);
        $mimeType = mime_content_type($path) ?: 'application/octet-stream';
        $inline = $request->boolean('inline') && in_array($mimeType, $this->supportedImageMimeTypes(), true);

        return response()->file($path, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename*=UTF-8\'\''.rawurlencode(basename($path)),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, string $sessionId): JsonResponse|RedirectResponse
    {
        $agent = $this->resolveAgent($sessionId);
        $activeSessionId = $agent['activeSessionId'] ?? null;
        abort_unless(is_string($activeSessionId) && $activeSessionId !== '', 409, 'This agent is not currently running.');

        try {
            $this->runtime->stop($activeSessionId);
        } catch (\RuntimeException $error) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $error->getMessage()], 503);
            }

            return back()->withErrors(['prime_agent' => $error->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json(['redirect' => route('dashboard')]);
        }

        return redirect()->route('dashboard')->with('success', 'The agent was stopped. Its session remains available to resume later.');
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return array{string, list<array{type: string, mimeType: string, data: string}>}
     */
    private function storeAttachments(string $sessionId, array $files): array
    {
        $attachments = [];
        $images = [];

        foreach ($files as $file) {
            $id = Str::uuid()->toString();
            $name = $this->safeAttachmentName($file->getClientOriginalName());
            $stored = $file->storeAs($this->attachmentDirectory($sessionId).'/'.$id, $name, 'local');
            if (! is_string($stored)) {
                throw new \RuntimeException('Could not store the attached file.');
            }

            $mimeType = $file->getMimeType() ?: 'application/octet-stream';
            $path = Storage::disk('local')->path($stored);
            $isImage = in_array($mimeType, $this->supportedImageMimeTypes(), true);
            $attachments[] = [
                'id' => $id,
                'name' => $name,
                'mimeType' => $mimeType,
                'size' => $file->getSize(),
                'image' => $isImage,
                'path' => $path,
            ];

            if ($isImage) {
                $contents = file_get_contents($path);
                if ($contents === false) {
                    throw new \RuntimeException('Could not read the attached image.');
                }
                $encodedImage = base64_encode($contents);
                if (strlen($encodedImage) < 4.5 * 1024 * 1024) {
                    $images[] = ['type' => 'image', 'mimeType' => $mimeType, 'data' => $encodedImage];
                }
            }
        }

        $encoded = json_encode($attachments, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return ['<prime-agent-web-attachments>'.$encoded.'</prime-agent-web-attachments>', $images];
    }

    private function safeAttachmentName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\pL\pN._ -]+/u', '_', $name) ?? '';
        $name = trim($name, " .\t\n\r\0\x0B");

        return $name !== '' ? Str::limit($name, 180, '') : 'attachment';
    }

    private function creationError(Request $request, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 503);
        }

        return back()->withInput()->withErrors(['prime_agent' => $message]);
    }

    private function attachmentDirectory(string $sessionId): string
    {
        return 'agent-uploads/'.hash('sha256', $sessionId);
    }

    /** @return list<string> */
    private function supportedImageMimeTypes(): array
    {
        return ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
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
            'firstMessage' => $agent['firstMessage'] ?? null,
        ];
    }
}
