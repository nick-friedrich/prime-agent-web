<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>New chat — Prime Agent</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="chat-body">
<div class="chat-shell new-chat-shell" data-chat data-new-chat data-create-url="{{ route('agents.store') }}">
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
                <a class="project-row {{ $selectedProject?->id === $project->id ? 'selected' : '' }}" href="{{ route('agents.create', ['project' => $project->slug]) }}"><span class="project-glyph" style="--project-color:{{ $project->color }}">{{ strtoupper(substr($project->name, 0, 1)) }}</span><span>{{ $project->name }}</span></a>
            @endforeach
        </div>
        <div class="sidebar-bottom">
            <div class="daemon-status {{ $daemonOnline ? '' : 'offline' }}"><span class="signal"><i></i><i></i><i></i></span><div><b>{{ $daemonOnline ? 'Runtime online' : 'Runtime offline' }}</b><small>{{ $primeAgentAvailable ? 'Prime Agent detected' : 'Installation required' }}</small></div><span class="live-dot"></span></div>
            <div class="runtime-path">{{ $primeAgentBinary ?? 'prime-agent not found' }}</div>
        </div>
    </aside>

    <main class="chat-main">
        <header class="chat-header new-chat-header">
            <button class="icon-btn menu-btn" data-open-nav aria-label="Open navigation"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
            <div class="chat-agent-mark new">＋</div>
            <div class="chat-title">
                <h1>New chat</h1>
                <p><span class="status-pill idle"><i></i>not started</span><span>Choose a project and send your first message</span></p>
            </div>
            <div class="new-chat-settings">
                <label>Project
                    <select name="project_id" form="new-agent-form" required aria-label="Project">
                        <option value="">Select a project</option>
                        @foreach($projects as $project)
                            <option value="{{ $project->id }}" @selected(old('project_id', $selectedProject?->id) == $project->id)>{{ $project->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Session type
                    <select name="session_mode" form="new-agent-form" required aria-label="Session type">
                        <option value="chat" @selected(old('session_mode', 'chat') === 'chat')>Chat</option>
                        <option value="goal" @selected(old('session_mode') === 'goal')>Goal</option>
                    </select>
                </label>
            </div>
        </header>

        @if($errors->any())<div class="toast error" role="alert"><span>!</span>{{ $errors->first() }}<button aria-label="Dismiss">×</button></div>@endif
        @if(!$daemonOnline)
            <div class="new-chat-runtime-warning" role="alert">{{ $daemonError ?: ($primeAgentAvailable ? 'Prime Agent is offline. Start the runtime before sending.' : 'Install Prime Agent before starting a conversation.') }}</div>
        @endif

        <section class="chat-scroll" data-chat-scroll aria-live="polite">
            <div class="chat-transcript" data-chat-transcript></div>
            <div class="chat-empty new-chat-empty" data-chat-empty><span>⌁</span><h2>What can I help you build?</h2><p>Write a message or attach files to start a new conversation.</p></div>
        </section>

        <footer class="composer-wrap">
            <div class="current-activity" data-current-activity role="status">
                <span class="activity-indicator"><i></i></span>
                <strong data-current-activity-label>New conversation</strong>
                <span data-current-activity-detail>Your agent starts when you send</span>
            </div>
            <form id="new-agent-form" class="chat-composer" data-chat-composer enctype="multipart/form-data">
                @csrf
                <div class="composer-attachments" data-composer-attachments hidden></div>
                <input id="chat-attachments" type="file" name="attachments[]" multiple hidden data-chat-files>
                <button type="button" class="composer-attach" aria-label="Attach images or files" title="Attach images or files" aria-controls="chat-attachments" data-composer-attach><svg viewBox="0 0 24 24"><path d="m20.5 11.5-8.9 8.9a6 6 0 0 1-8.5-8.5l9.6-9.6a4 4 0 0 1 5.7 5.7l-9.6 9.6a2 2 0 1 1-2.8-2.8l8.9-8.9"/></svg></button>
                <textarea name="message" rows="1" maxlength="16384" placeholder="Message Prime Agent…" aria-label="Message Prime Agent" data-chat-input autofocus>{{ old('message') }}</textarea>
                <button class="composer-send" aria-label="Start conversation" data-composer-send @disabled(!$daemonOnline || $projects->isEmpty())><svg viewBox="0 0 24 24"><path d="m5 12 14-7-4 14-3-6-7-1Z"/><path d="m12 13 7-8"/></svg></button>
                <div class="composer-drop-overlay" data-composer-drop-overlay hidden>Drop files to attach</div>
            </form>
            <div class="composer-meta"><span data-chat-feedback>Enter to send · Shift+Enter for a new line</span><span>Your chat is created on send</span></div>
        </footer>
    </main>
</div>
</body>
</html>
