<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\PrimeAgentRuntime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly PrimeAgentRuntime $runtime) {}

    public function index(Request $request): View
    {
        $projects = Project::orderBy('name')->get();
        $activeProject = $request->filled('project')
            ? $projects->firstWhere('slug', $request->string('project'))
            : null;
        $primeAgentAvailable = $this->runtime->isAvailable();
        $daemon = $primeAgentAvailable && ! app()->runningUnitTests()
            ? $this->runtime->ensureDaemon()
            : ['online' => false, 'error' => null];
        $agents = $daemon['online'] ? collect($this->runtime->agents()) : collect();

        if ($activeProject) {
            $agents = $agents->where('cwd', $activeProject->path)->values();
        }

        return view('dashboard', [
            'projects' => $projects,
            'activeProject' => $activeProject,
            'agents' => $agents,
            'primeAgentAvailable' => $primeAgentAvailable,
            'primeAgentBinary' => $this->runtime->binary(),
            'daemonOnline' => $daemon['online'],
            'daemonError' => $daemon['error'],
        ]);
    }

    public function storeProject(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'path' => ['required', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $path = realpath($data['path']);
        if ($path === false || ! is_dir($path)) {
            throw ValidationException::withMessages(['path' => 'Choose an existing local project directory.']);
        }
        if (! is_dir($path.'/.git')) {
            throw ValidationException::withMessages(['path' => 'This directory is not a Git repository yet. Run git init there first.']);
        }

        $data['path'] = $path;
        $data['slug'] = Str::slug($data['name']).'-'.Str::lower(Str::random(4));
        $data['color'] = ['#C8FF58', '#8B7CFF', '#52D9CB', '#FF9E6D'][Project::count() % 4];
        Project::create($data);

        return back()->with('success', 'Project connected. You can now start an agent.');
    }

    public function storeAgent(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'name' => ['required', 'string', 'max:80'],
            'goal' => ['required', 'string', 'max:800'],
        ]);

        if (! $this->runtime->isAvailable()) {
            return back()->withErrors(['prime_agent' => 'Prime Agent is not installed or is not visible to Laravel.']);
        }

        $daemon = $this->runtime->ensureDaemon();
        if (! $daemon['online']) {
            return back()->withErrors(['prime_agent' => $daemon['error'] ?: 'Prime Agent could not start.']);
        }

        $project = Project::findOrFail($data['project_id']);

        try {
            $this->runtime->create($data['name'], $project->path, $data['goal']);
        } catch (\RuntimeException $error) {
            return back()->withInput()->withErrors(['prime_agent' => $error->getMessage()]);
        }

        return back()->with('success', $data['name'].' was started in Prime Agent.');
    }
}
