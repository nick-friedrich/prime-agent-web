<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\Project;
use App\Services\PrimeAgentRuntime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly PrimeAgentRuntime $runtime) {}

    public function index(Request $request): View
    {
        $projects = Project::withCount('agents')->orderBy('name')->get();
        $activeProject = $request->filled('project')
            ? $projects->firstWhere('slug', $request->string('project'))
            : null;
        $agents = Agent::with(['project', 'tasks' => fn ($q) => $q->latest()])
            ->when($activeProject, fn ($q) => $q->whereBelongsTo($activeProject))
            ->latest('last_seen_at')->get();

        $primeAgentBinary = $this->runtime->binary();
        $primeAgentAvailable = $primeAgentBinary !== null;

        return view('dashboard', compact('projects', 'activeProject', 'agents', 'primeAgentAvailable', 'primeAgentBinary'));
    }

    public function storeProject(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'repository' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $data['slug'] = Str::slug($data['name']).'-'.Str::lower(Str::random(4));
        $data['color'] = ['#C8FF58', '#8B7CFF', '#52D9CB', '#FF9E6D'][Project::count() % 4];
        Project::create($data);

        return back()->with('success', 'Project added to the workspace.');
    }

    public function storeAgent(Request $request): RedirectResponse
    {
        if (! $this->runtime->isAvailable()) {
            return back()->withErrors([
                'prime_agent' => 'Prime Agent is not installed or is not visible to the Laravel process. Install it or configure PRIME_AGENT_BINARY.',
            ]);
        }

        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'name' => ['required', 'string', 'max:80'],
            'model' => ['required', 'string', 'max:80'],
            'goal' => ['required', 'string', 'max:800'],
        ]);
        $data += ['status' => 'running', 'progress' => 4, 'last_seen_at' => now()];
        $agent = Agent::create($data);
        AgentTask::create(['agent_id' => $agent->id, 'title' => $data['goal'], 'status' => 'active', 'progress' => 4]);

        return back()->with('success', $agent->name.' is now running.');
    }

    public function updateAgent(Request $request, Agent $agent): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:running,idle,paused,error']]);
        $agent->update($data + ['last_seen_at' => now()]);

        return back()->with('success', $agent->name.' is now '.$data['status'].'.');
    }
}
