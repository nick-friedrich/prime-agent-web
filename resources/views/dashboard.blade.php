<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Prime Agent — Mission Control</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="brand-row">
            <a class="brand" href="{{ route('dashboard') }}"><span class="brand-mark"><i></i><i></i><i></i></span><span>prime<span>agent</span></span></a>
            <button class="icon-btn sidebar-close" data-close-nav aria-label="Close navigation">×</button>
        </div>
        <nav class="main-nav"><a class="nav-item active" href="{{ route('dashboard') }}"><svg viewBox="0 0 24 24"><path d="M4 13h6V4H4v9Zm0 7h6v-4H4v4Zm10 0h6v-9h-6v9Zm0-16v4h6V4h-6Z"/></svg>Mission control</a></nav>

        <div class="sidebar-label"><span>Projects</span><button data-open-modal="project-modal" aria-label="Add project">+</button></div>
        <div class="project-list">
            <a class="project-row {{ !$activeProject ? 'selected' : '' }}" href="{{ route('dashboard') }}"><span class="project-glyph all-projects">⌘</span><span>All projects</span><strong>{{ $projects->count() }}</strong></a>
            @foreach($projects as $project)
                <a class="project-row {{ $activeProject?->id === $project->id ? 'selected' : '' }}" href="{{ route('dashboard', ['project' => $project->slug]) }}"><span class="project-glyph" style="--project-color:{{ $project->color }}">{{ strtoupper(substr($project->name, 0, 1)) }}</span><span>{{ $project->name }}</span></a>
            @endforeach
        </div>

        <div class="sidebar-bottom">
            <div class="daemon-status {{ $daemonOnline ? '' : 'offline' }}"><span class="signal"><i></i><i></i><i></i></span><div><b>{{ $daemonOnline ? 'Runtime online' : 'Runtime offline' }}</b><small>{{ $primeAgentAvailable ? 'Prime Agent detected' : 'Installation required' }}</small></div><span class="live-dot"></span></div>
            <div class="runtime-path">{{ $primeAgentBinary ?? 'prime-agent not found' }}</div>
        </div>
    </aside>

    <main>
        <header class="topbar">
            <button class="icon-btn menu-btn" data-open-nav aria-label="Open navigation"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
            <div class="crumb"><span>Workspace</span><b>/</b><strong>{{ $activeProject?->name ?? 'Getting started' }}</strong></div>
            <div class="top-actions">
                <button class="secondary-button" data-open-modal="project-modal"><span>＋</span> Add project</button>
                <button class="primary-button" data-open-modal="agent-modal" @disabled(!$daemonOnline || $projects->isEmpty()) title="{{ !$daemonOnline ? 'Prime Agent runtime must be online' : ($projects->isEmpty() ? 'Add a project first' : '') }}"><span>＋</span> Start agent</button>
            </div>
        </header>

        <div class="workspace onboarding-workspace">
            @if(session('success'))<div class="toast" role="status"><span>✓</span>{{ session('success') }}<button aria-label="Dismiss">×</button></div>@endif
            @if($errors->any())<div class="toast error" role="alert"><span>!</span>{{ $errors->first() }}<button aria-label="Dismiss">×</button></div>@endif

            <section class="welcome-heading">
                <p class="eyebrow">Prime Agent workspace</p>
                <h1>{{ $agents->isEmpty() ? 'Let’s get you running.' : 'Your agents, in one place.' }}</h1>
                <p>{{ $agents->isEmpty() ? 'Complete the steps below. There is no demo data here—everything shown will be yours.' : 'These sessions come directly from your local Prime Agent daemon.' }}</p>
            </section>

            @if(!$primeAgentAvailable || !$daemonOnline || $projects->isEmpty() || $agents->isEmpty())
            <section class="setup-panel">
                <div class="setup-panel-heading"><div><span>01</span><div><h2>Set up your workspace</h2><p>Four small steps, then this checklist gets out of your way.</p></div></div><strong>{{ collect([$primeAgentAvailable,$daemonOnline,$projects->isNotEmpty(),$agents->isNotEmpty()])->filter()->count() }}/4 complete</strong></div>
                <div class="setup-progress"><i style="width:{{ collect([$primeAgentAvailable,$daemonOnline,$projects->isNotEmpty(),$agents->isNotEmpty()])->filter()->count() * 25 }}%"></i></div>
                <div class="setup-steps">
                    <article class="setup-step {{ $primeAgentAvailable ? 'done' : 'blocked' }}">
                        <span class="step-status">{{ $primeAgentAvailable ? '✓' : '!' }}</span><div><small>Step 1</small><h3>Install Prime Agent</h3><p>{{ $primeAgentAvailable ? 'Found at '.$primeAgentBinary : 'Install the CLI and refresh this page.' }}</p></div>
                        @unless($primeAgentAvailable)<code>curl -fsSL https://app.primeintellect.ai/prime-agent/install.sh | sh</code>@endunless
                    </article>
                    <article class="setup-step {{ $daemonOnline ? 'done' : ($primeAgentAvailable ? 'blocked' : '') }}">
                        <span class="step-status">{{ $daemonOnline ? '✓' : '2' }}</span><div><small>Step 2</small><h3>Start the local runtime</h3><p>{{ $daemonOnline ? 'The daemon is ready and connected.' : 'The app tried to start it automatically.'.($daemonError ? ' '.$daemonError : '') }}</p></div>
                        @if($primeAgentAvailable && !$daemonOnline)<code>prime-agent daemon start</code>@endif
                    </article>
                    <article class="setup-step {{ $projects->isNotEmpty() ? 'done' : '' }}">
                        <span class="step-status">{{ $projects->isNotEmpty() ? '✓' : '3' }}</span><div><small>Step 3</small><h3>Connect a Git project</h3><p>{{ $projects->isNotEmpty() ? $projects->count().' project'.($projects->count() === 1 ? '' : 's').' connected.' : 'Choose a local Git repository where agents can work.' }}</p></div>
                        @if($projects->isEmpty())<button class="secondary-button" data-open-modal="project-modal">Choose project</button>@endif
                    </article>
                    <article class="setup-step {{ $agents->isNotEmpty() ? 'done' : '' }}">
                        <span class="step-status">{{ $agents->isNotEmpty() ? '✓' : '4' }}</span><div><small>Step 4</small><h3>Start your first agent</h3><p>{{ $agents->isNotEmpty() ? 'Your first real session is active.' : 'Give Prime Agent a name and a concrete goal.' }}</p></div>
                        @if($agents->isEmpty())<button class="primary-button" data-open-modal="agent-modal" @disabled(!$daemonOnline || $projects->isEmpty())>Start agent</button>@endif
                    </article>
                </div>
            </section>
            @endif

            @if($agents->isNotEmpty())
            <section class="real-agents">
                <div class="section-heading"><div><h2>Prime Agent sessions</h2><span>{{ $agents->count() }} real {{ Str::plural('session', $agents->count()) }}</span></div><a href="{{ request()->fullUrl() }}">Refresh <b>↻</b></a></div>
                <div class="session-table">
                    @foreach($agents as $agent)
                    @php
                        $working = ($agent['activity'] ?? null) === 'working';
                        $archived = ($agent['lifecycle'] ?? null) === 'archived';
                        $status = $archived ? 'archived' : ($working ? 'working' : 'idle');
                        $model = $agent['model'] ?? null;
                    @endphp
                    @php($sessionId = $agent['id'] ?? $agent['activeSessionId'] ?? null)
                    <div class="session-row">
                        <span class="session-symbol {{ $status }}">{{ $working ? '↯' : '⌁' }}</span>
                        <div class="session-main"><h3><a href="{{ $sessionId ? route('agents.show', ['sessionId' => $sessionId]) : '#' }}">{{ Str::limit($agent['firstMessage'] ?? 'Agent session', 72) }}</a></h3><p>{{ $agent['cwd'] ?? 'Unknown project' }}</p></div>
                        <span class="status-pill {{ $working ? 'running' : ($archived ? 'paused' : 'idle') }}"><i></i>{{ $status }}</span>
                        <div class="session-stat"><small>Messages</small><strong>{{ $agent['messageCount'] ?? 0 }}</strong></div>
                        <div class="session-stat"><small>Model</small><strong>{{ is_array($model) ? (($model['provider'] ?? '').'/'.($model['id'] ?? '')) : 'Default' }}</strong></div>
                        <code>{{ substr($agent['activeSessionId'] ?? $agent['id'] ?? '', -8) }}</code>
                    </div>
                    @endforeach
                </div>
            </section>
            @endif
        </div>
    </main>
</div>

<dialog id="project-modal" class="modal">
    <form method="POST" action="{{ route('projects.store') }}" data-project-form>@csrf
        <div class="modal-heading"><div><span class="modal-icon">⌘</span><div><h2>Connect a project</h2><p>Choose an existing local Git repository.</p></div></div><button type="button" data-close-modal>×</button></div>
        <label>Project name<input name="name" required value="{{ old('name') }}" placeholder="e.g. My application" data-project-name></label>
        <div class="directory-field">
            <label for="project-path">Project directory</label>
            <div class="directory-picker" data-directory-picker data-search-url="{{ route('project-directories.search') }}" data-browse-url="{{ route('project-directories.browse') }}">
                <div class="directory-tabs" role="tablist" aria-label="Choose directory method">
                    <button type="button" class="active" role="tab" aria-selected="true" data-directory-mode="search">Search repositories</button>
                    <button type="button" role="tab" aria-selected="false" data-directory-mode="browse">Browse folders</button>
                </div>
                <section class="directory-panel" data-directory-panel="search">
                    <div class="directory-search"><span aria-hidden="true">⌕</span><input type="search" autocomplete="off" placeholder="Search by repository name or path" aria-label="Search local repositories" aria-controls="directory-search-results" data-directory-search></div>
                    <div id="directory-search-results" class="directory-results" role="listbox" aria-live="polite" data-directory-results><p>Start typing or browse all discovered repositories.</p></div>
                </section>
                <section class="directory-panel" data-directory-panel="browse" hidden>
                    <div class="directory-location"><button type="button" data-directory-up disabled aria-label="Go to parent directory">↑</button><code data-directory-current>Home</code><button type="button" data-directory-use hidden>Use repository</button></div>
                    <div class="directory-results browse-results" aria-live="polite" data-directory-browse-results><p>Loading folders…</p></div>
                </section>
            </div>
            <label class="manual-path" for="project-path"><span>Or enter an absolute path</span><input id="project-path" name="path" required value="{{ old('path') }}" placeholder="/Users/you/dev/my-project" data-project-path></label>
        </div>
        <label>Description <span>Optional</span><textarea name="description" rows="3" placeholder="What are you building?">{{ old('description') }}</textarea></label>
        <div class="trust-note"><span>!</span><p><b>Prime Agent can edit this repository</b><small>Only connect a project whose contents and instructions you trust.</small></p></div>
        <div class="modal-actions"><button type="button" class="secondary-button" data-close-modal>Cancel</button><button class="primary-button">Connect project</button></div>
    </form>
</dialog>

<dialog id="agent-modal" class="modal">
    <form method="POST" action="{{ route('agents.store') }}">@csrf
        <div class="modal-heading"><div><span class="modal-icon agent">↯</span><div><h2>Start a Prime Agent</h2><p>This creates a real daemon-backed session.</p></div></div><button type="button" data-close-modal>×</button></div>
        <label>Project<select name="project_id" required><option value="">Select a project</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected(old('project_id', $activeProject?->id) == $project->id)>{{ $project->name }}</option>@endforeach</select></label>
        <label>Goal<textarea name="goal" required rows="5" placeholder="Describe the outcome, constraints, and quality checks...">{{ old('goal') }}</textarea></label>
        <div class="modal-actions"><button type="button" class="secondary-button" data-close-modal>Cancel</button><button class="primary-button" @disabled(!$daemonOnline || $projects->isEmpty())><span>＋</span> Start agent</button></div>
    </form>
</dialog>
</body>
</html>
