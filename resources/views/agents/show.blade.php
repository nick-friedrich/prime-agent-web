<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ Str::limit($agent['firstMessage'] ?? 'Agent chat', 72) }} — Prime Agent</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="chat-body">
@php
    $working = ($agent['activity'] ?? null) === 'working';
    $archived = ($agent['lifecycle'] ?? null) === 'archived';
    $status = $archived ? 'archived' : ($working ? 'working' : 'idle');
    $model = $agent['model'] ?? null;
    $modelLabel = is_array($model) ? trim(($model['provider'] ?? '').'/'.($model['id'] ?? ''), '/') : 'Default model';
@endphp
<div class="chat-shell" data-chat data-transcript-url="{{ route('agents.transcript', ['sessionId' => $sessionId]) }}" data-message-url="{{ route('agents.messages.store', ['sessionId' => $sessionId]) }}" data-stop-url="{{ route('agents.destroy', ['sessionId' => $sessionId]) }}">
    <aside class="chat-sidebar" id="sidebar">
        <div class="brand-row">
            <a class="brand" href="{{ route('dashboard') }}"><span class="brand-mark"><i></i><i></i><i></i></span><span>prime<span>agent</span></span></a>
            <button class="icon-btn sidebar-close" data-close-nav aria-label="Close navigation">×</button>
        </div>
        <a class="chat-back" href="{{ route('dashboard') }}"><span>←</span> Mission control</a>
        <div class="sidebar-label"><span>Projects</span></div>
        <div class="project-list">
            <a class="project-row" href="{{ route('dashboard') }}"><span class="project-glyph all-projects">⌘</span><span>All projects</span><strong>{{ $projects->count() }}</strong></a>
            @foreach($projects as $project)
                <a class="project-row {{ ($agent['cwd'] ?? null) === $project->path ? 'selected' : '' }}" href="{{ route('dashboard', ['project' => $project->slug]) }}"><span class="project-glyph" style="--project-color:{{ $project->color }}">{{ strtoupper(substr($project->name, 0, 1)) }}</span><span>{{ $project->name }}</span></a>
            @endforeach
        </div>
        <div class="sidebar-bottom">
            <div class="daemon-status"><span class="signal"><i></i><i></i><i></i></span><div><b>Runtime online</b><small>Prime Agent detected</small></div><span class="live-dot"></span></div>
            <div class="runtime-path">{{ $primeAgentBinary }}</div>
        </div>
    </aside>

    <main class="chat-main">
        <header class="chat-header">
            <button class="icon-btn menu-btn" data-open-nav aria-label="Open navigation"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
            <div class="chat-agent-mark {{ $status }}">{{ $working ? '↯' : '⌁' }}</div>
            <div class="chat-title">
                <h1>{{ Str::limit($agent['firstMessage'] ?? 'Agent session', 72) }}</h1>
                <p><span data-chat-status class="status-pill {{ $working ? 'running' : ($archived ? 'paused' : 'idle') }}"><i></i>{{ $status }}</span><span>{{ $modelLabel }}</span><code>{{ substr($agent['activeSessionId'] ?? $agent['id'] ?? '', -8) }}</code></p>
            </div>
            <div class="chat-project"><small>Working directory</small><code>{{ $agent['cwd'] ?? 'Unknown project' }}</code></div>
        </header>

        <section class="chat-scroll" data-chat-scroll aria-live="polite">
            <div class="chat-transcript" data-chat-transcript></div>
            <div class="chat-empty" data-chat-empty hidden><span>⌁</span><h2>No messages yet</h2><p>Send the agent a concrete instruction to begin.</p></div>
        </section>

        <footer class="composer-wrap">
            <div class="current-activity" data-current-activity role="status">
                <span class="activity-indicator"><i></i></span>
                <strong data-current-activity-label>Ready for input</strong>
                <span data-current-activity-detail></span>
            </div>
            <form class="chat-composer" data-chat-composer>
                <textarea name="message" rows="1" maxlength="16384" placeholder="Send a message to this agent…" aria-label="Message the agent" required data-chat-input></textarea>
                <button class="composer-send" aria-label="Send message" data-composer-send><svg viewBox="0 0 24 24"><path d="m5 12 14-7-4 14-3-6-7-1Z"/><path d="m12 13 7-8"/></svg></button>
                <button type="button" class="composer-stop" aria-label="Stop agent" title="Stop agent" data-composer-stop hidden><span>■</span></button>
            </form>
            <div class="composer-meta"><span data-chat-feedback>Enter to send · Shift+Enter for a new line</span><span><b data-chat-count>{{ $agent['messageCount'] ?? 0 }}</b> messages</span></div>
        </footer>
    </main>
</div>
<script type="application/json" data-chat-initial>{{ Illuminate\Support\Js::encode(['agent' => $agentPayload, 'transcript' => $transcript]) }}</script>
</body>
</html>
