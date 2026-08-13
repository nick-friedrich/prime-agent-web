<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Prime Agent — Mission Control</title>
    <meta name="description" content="Orchestrate long-running Prime Agents across every project.">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="brand-row">
            <a class="brand" href="{{ route('dashboard') }}" aria-label="Prime Agent home">
                <span class="brand-mark"><i></i><i></i><i></i></span>
                <span>prime<span>agent</span></span>
            </a>
            <button class="icon-btn sidebar-close" data-close-nav aria-label="Close navigation">×</button>
        </div>

        <nav class="main-nav" aria-label="Main navigation">
            <a class="nav-item active" href="{{ route('dashboard') }}">
                <svg viewBox="0 0 24 24"><path d="M4 13h6V4H4v9Zm0 7h6v-4H4v4Zm10 0h6v-9h-6v9Zm0-16v4h6V4h-6Z"/></svg>
                Mission control
            </a>
            <a class="nav-item" href="#activity">
                <svg viewBox="0 0 24 24"><path d="M4 17h3l3-10 4 14 3-9h3"/></svg>
                Activity
                <span class="nav-count">12</span>
            </a>
            <a class="nav-item" href="#schedule">
                <svg viewBox="0 0 24 24"><path d="M6 3v3m12-3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v14H4V6a1 1 0 0 1 1-1Z"/></svg>
                Schedules
            </a>
        </nav>

        <div class="sidebar-label"><span>Projects</span><button data-open-modal="project-modal" aria-label="Add project">+</button></div>
        <div class="project-list">
            <a class="project-row {{ !$activeProject ? 'selected' : '' }}" href="{{ route('dashboard') }}">
                <span class="project-glyph all-projects">⌘</span><span>All projects</span>
                <strong>{{ $projects->sum('agents_count') }}</strong>
            </a>
            @foreach($projects as $project)
                <a class="project-row {{ $activeProject?->id === $project->id ? 'selected' : '' }}" href="{{ route('dashboard', ['project' => $project->slug]) }}">
                    <span class="project-glyph" style="--project-color: {{ $project->color }}">{{ strtoupper(substr($project->name, 0, 1)) }}</span>
                    <span>{{ $project->name }}</span><strong>{{ $project->agents_count }}</strong>
                </a>
            @endforeach
        </div>

        <div class="sidebar-bottom">
            <div class="daemon-status {{ $primeAgentAvailable ? '' : 'offline' }}"><span class="signal"><i></i><i></i><i></i></span><div><b>{{ $primeAgentAvailable ? 'Prime Agent ready' : 'Prime Agent missing' }}</b><small>{{ $primeAgentAvailable ? 'Executable detected' : 'Installation required' }}</small></div><span class="live-dot"></span></div>
            <a class="nav-item" href="#settings"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.6v.2h-4V21a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9A1.7 1.7 0 0 0 3 14H2.8v-4H3a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3A1.7 1.7 0 0 0 10 3v-.2h4V3a1.7 1.7 0 0 0 1 1.6 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.6 1h.2v4H21a1.7 1.7 0 0 0-1.6 1Z"/></svg>Settings</a>
            <div class="profile"><div class="avatar">NK</div><div><b>Nick Kooper</b><small>Workspace owner</small></div><button aria-label="Profile menu">•••</button></div>
        </div>
    </aside>

    <main>
        <header class="topbar">
            <button class="icon-btn menu-btn" data-open-nav aria-label="Open navigation"><svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
            <div class="crumb"><span>Workspace</span><b>/</b><strong>{{ $activeProject?->name ?? 'Mission control' }}</strong></div>
            <div class="top-actions">
                <button class="search-button" data-search><kbd>⌘</kbd><kbd>K</kbd><span>Quick search</span></button>
                <button class="icon-btn notification" aria-label="Notifications"><svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4"/></svg><i></i></button>
                <button class="primary-button" @if($primeAgentAvailable) data-open-modal="agent-modal" @else disabled title="Prime Agent must be installed before deploying an agent" @endif><span>＋</span> Deploy agent</button>
            </div>
        </header>

        <div class="workspace">
            @unless($primeAgentAvailable)
                <div class="runtime-alert" role="alert">
                    <span class="runtime-alert-icon">!</span>
                    <div><strong>Prime Agent is not available</strong><p>Install <code>prime-agent</code> or set <code>PRIME_AGENT_BINARY</code> to its absolute executable path, then restart Laravel.</p></div>
                </div>
            @endunless
            @if(session('success'))<div class="toast" role="status"><span>✓</span>{{ session('success') }}<button aria-label="Dismiss">×</button></div>@endif
            @if($errors->any())<div class="toast error" role="alert"><span>!</span>{{ $errors->first() }}<button aria-label="Dismiss">×</button></div>@endif

            <section class="page-heading">
                <div><p class="eyebrow">{{ now()->format('l, F j') }}</p><h1>{{ $activeProject?->name ?? 'Mission control' }}</h1><p>{{ $activeProject?->description ?? 'Your autonomous workforce, at a glance.' }}</p></div>
                <div class="heading-actions"><button class="secondary-button" data-filter-toggle><svg viewBox="0 0 24 24"><path d="M4 6h16M7 12h10m-7 6h4"/></svg>Filter</button><button class="secondary-button icon-only" data-view-toggle aria-label="Toggle view"><svg viewBox="0 0 24 24"><path d="M4 4h6v6H4V4Zm10 0h6v6h-6V4ZM4 14h6v6H4v-6Zm10 0h6v6h-6v-6Z"/></svg></button></div>
            </section>

            <section class="metrics" aria-label="Workspace metrics">
                <article><span class="metric-icon running"><svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6v12Z"/></svg></span><div><small>Running now</small><strong>{{ $agents->where('status', 'running')->count() }}</strong><em>agents</em></div><span class="trend up">↑ 18%</span></article>
                <article><span class="metric-icon queued"><svg viewBox="0 0 24 24"><path d="M12 7v5l3 2M21 12a9 9 0 1 1-9-9 9 9 0 0 1 9 9Z"/></svg></span><div><small>Work in queue</small><strong>{{ $agents->flatMap->tasks->where('status', 'queued')->count() }}</strong><em>tasks</em></div><span class="trend">Steady</span></article>
                <article><span class="metric-icon completed"><svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg></span><div><small>Completion rate</small><strong>94.2</strong><em>%</em></div><span class="trend up">↑ 3.1%</span></article>
                <article><span class="metric-icon spend"><svg viewBox="0 0 24 24"><path d="M12 2v20M17 6.5c0-2-2-3-5-3s-5 1.3-5 3 1.5 2.7 5 3.5 5 1.8 5 3.8-2 3.7-5 3.7-5-1.5-5-3.5"/></svg></span><div><small>Token spend</small><strong>{{ number_format($agents->sum('tokens_used') / 1000, 1) }}k</strong><em>today</em></div><span class="trend down">↓ 8%</span></article>
            </section>

            <section class="section-block">
                <div class="section-heading"><div><h2>Active agents</h2><span>{{ $agents->count() }} total</span></div><a href="#all-agents">View all <b>→</b></a></div>
                <div class="filters" hidden><button class="active" data-status-filter="all">All</button><button data-status-filter="running">Running</button><button data-status-filter="idle">Idle</button><button data-status-filter="paused">Paused</button><button data-status-filter="error">Needs attention</button></div>
                <div class="agent-grid" id="agent-grid">
                    @forelse($agents as $agent)
                    <article class="agent-card" data-status="{{ $agent->status }}">
                        <div class="agent-card-top">
                            <div class="agent-identity"><span class="agent-symbol" style="--project-color: {{ $agent->project->color }}"><svg viewBox="0 0 24 24"><path d="M8 9h8M9 14h.01M15 14h.01M7 20h10a3 3 0 0 0 3-3V9a3 3 0 0 0-3-3h-1l-1-3H9L8 6H7a3 3 0 0 0-3 3v8a3 3 0 0 0 3 3Z"/></svg></span><div><h3>{{ $agent->name }}</h3><p><i style="background:{{ $agent->project->color }}"></i>{{ $agent->project->name }}</p></div></div>
                            <div class="agent-menu-wrap"><button class="card-menu" aria-label="Agent actions">•••</button><div class="agent-menu">
                                @foreach(['running' => 'Resume', 'paused' => 'Pause', 'idle' => 'Stop'] as $status => $label)
                                <form method="POST" action="{{ route('agents.update', $agent) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="{{ $status }}"><button>{{ $label }}</button></form>
                                @endforeach
                            </div></div>
                        </div>
                        <div class="agent-state"><span class="status-pill {{ $agent->status }}"><i></i>{{ ucfirst($agent->status) }}</span><span>{{ $agent->model }}</span><span>Updated {{ $agent->last_seen_at?->diffForHumans(short: true) }}</span></div>
                        <p class="agent-goal">{{ $agent->goal }}</p>
                        <div class="progress-meta"><span>Current goal</span><strong>{{ $agent->progress }}%</strong></div>
                        <div class="progress"><i style="width: {{ $agent->progress }}%; --project-color: {{ $agent->project->color }}"></i></div>
                        <div class="agent-footer"><span><svg viewBox="0 0 24 24"><path d="M5 12h14M12 5v14"/></svg>{{ $agent->tasks->whereIn('status', ['queued','active'])->count() }} tasks</span><span><svg viewBox="0 0 24 24"><path d="M12 2v20M7 7h7a3 3 0 0 1 0 6h-4a3 3 0 0 0 0 6h7"/></svg>{{ number_format($agent->tokens_used / 1000, 1) }}k tokens</span><a href="#agent-{{ $agent->id }}" aria-label="Open {{ $agent->name }}">↗</a></div>
                    </article>
                    @empty
                    <div class="empty-state"><span>⌁</span><h3>No agents here yet</h3><p>Deploy an agent and give it a goal to get started.</p><button class="primary-button" @if($primeAgentAvailable) data-open-modal="agent-modal" @else disabled @endif>Deploy agent</button></div>
                    @endforelse
                </div>
            </section>

            <section class="lower-grid" id="activity">
                <article class="panel activity-panel">
                    <div class="panel-heading"><div><h2>Live activity</h2><span class="live-label"><i></i>Live</span></div><button class="icon-btn">•••</button></div>
                    <div class="activity-list">
                        @foreach($agents->take(5) as $i => $agent)
                        <div class="activity-item"><span class="activity-line"><i class="{{ $agent->status }}"></i></span><div><p><b>{{ $agent->name }}</b> {{ ['updated its working memory','completed a tool call','requested a review','checkpointed its session','reported a status change'][$i] ?? 'made progress' }}</p><small>{{ $agent->project->name }} · {{ $agent->last_seen_at?->diffForHumans() }}</small></div><span class="activity-code">{{ ['memory.write','shell.exec','review.open','session.save','agent.status'][$i] ?? 'agent.event' }}</span></div>
                        @endforeach
                    </div>
                    <a class="panel-link" href="#full-activity">Open activity log <span>→</span></a>
                </article>
                <article class="panel capacity-panel">
                    <div class="panel-heading"><div><h2>Runtime capacity</h2><span>Last 24 hours</span></div><button class="icon-btn">•••</button></div>
                    <div class="capacity-ring" style="--value: {{ min(86, 20 + $agents->where('status','running')->count() * 14) }}"><div><strong>{{ min(86, 20 + $agents->where('status','running')->count() * 14) }}%</strong><span>utilized</span></div></div>
                    <div class="capacity-legend"><div><i class="lime"></i><span>Agent compute</span><b>18.4h</b></div><div><i class="violet"></i><span>Tool execution</span><b>6.2h</b></div><div><i class="muted"></i><span>Available</span><b>8.7h</b></div></div>
                    <div class="capacity-note"><span>↗</span><p><b>Healthy headroom</b><small>You can deploy ~3 more agents.</small></p></div>
                </article>
            </section>
        </div>
    </main>
</div>

<dialog id="project-modal" class="modal">
    <form method="POST" action="{{ route('projects.store') }}">@csrf
        <div class="modal-heading"><div><span class="modal-icon">⌘</span><div><h2>Create a project</h2><p>Connect a workspace for your agents.</p></div></div><button type="button" data-close-modal>×</button></div>
        <label>Project name<input name="name" required placeholder="e.g. Atlas API"></label>
        <label>Repository <span>Optional</span><input name="repository" placeholder="owner/repository"></label>
        <label>Description <span>Optional</span><textarea name="description" rows="3" placeholder="What are you building?"></textarea></label>
        <div class="modal-actions"><button type="button" class="secondary-button" data-close-modal>Cancel</button><button class="primary-button">Create project</button></div>
    </form>
</dialog>

<dialog id="agent-modal" class="modal">
    <form method="POST" action="{{ route('agents.store') }}">@csrf
        <div class="modal-heading"><div><span class="modal-icon agent">↯</span><div><h2>Deploy an agent</h2><p>Give a new autonomous worker a clear goal.</p></div></div><button type="button" data-close-modal>×</button></div>
        <div class="form-row"><label>Agent name<input name="name" required placeholder="e.g. Release Pilot"></label><label>Model<select name="model"><option>Prime RLM</option><option>Prime RLM XL</option><option>GPT-5.4</option></select></label></div>
        <label>Project<select name="project_id" required><option value="">Select a project</option>@foreach($projects as $project)<option value="{{ $project->id }}" @selected($activeProject?->id === $project->id)>{{ $project->name }}</option>@endforeach</select></label>
        <label>Goal<textarea name="goal" required rows="4" placeholder="Describe the outcome, constraints, and quality bar..."></textarea></label>
        <div class="trust-note"><span>✓</span><p><b>Runs in your project workspace</b><small>The agent can execute commands and edit files with your configured permissions.</small></p></div>
        <div class="modal-actions"><button type="button" class="secondary-button" data-close-modal>Cancel</button><button class="primary-button" @disabled(!$primeAgentAvailable)><span>＋</span> Deploy agent</button></div>
    </form>
</dialog>

<div class="command-palette" hidden>
    <div class="command-box"><div class="command-input"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/></svg><input placeholder="Search projects, agents, and actions…" autofocus><kbd>esc</kbd></div><div class="command-hint"><span>Try “Deploy an agent” or “Open Atlas API”</span></div></div>
</div>
</body>
</html>
