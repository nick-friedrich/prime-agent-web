<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\Project;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $atlas = Project::create(['name' => 'Atlas API', 'slug' => 'atlas-api', 'repository' => 'prime/atlas-api', 'color' => '#C8FF58', 'description' => 'Core inference and orchestration services']);
        $orbit = Project::create(['name' => 'Orbit Console', 'slug' => 'orbit-console', 'repository' => 'prime/orbit-console', 'color' => '#8B7CFF', 'description' => 'Operator-facing control plane']);
        $research = Project::create(['name' => 'Eval Research', 'slug' => 'eval-research', 'repository' => 'prime/eval-suite', 'color' => '#52D9CB', 'description' => 'Long-horizon agent evaluations']);

        $data = [
            [$atlas, 'Schema Scout', 'running', 'Map the legacy authorization schema and propose a safe migration path.', 68, 48210, ['Inspect database relations', 'Draft migration plan']],
            [$atlas, 'Test Sentinel', 'idle', 'Increase service coverage around token rotation and refresh behavior.', 100, 31782, ['Add refresh-token edge cases']],
            [$orbit, 'Interface Builder', 'running', 'Implement the real-time agent timeline and responsive workspace shell.', 43, 65190, ['Build activity stream', 'Polish mobile navigation']],
            [$orbit, 'Review Pilot', 'paused', 'Review dashboard accessibility and keyboard navigation.', 27, 18440, ['Audit focus order']],
            [$research, 'Benchmark Runner', 'running', 'Execute the long-context benchmark suite and compare regressions.', 81, 92304, ['Run benchmark matrix', 'Summarize regressions']],
            [$research, 'Paper Trail', 'error', 'Synthesize evaluation notes into an evidence-backed report.', 54, 40688, ['Collect experiment notes']],
        ];

        foreach ($data as [$project, $name, $status, $goal, $progress, $tokens, $tasks]) {
            $agent = Agent::create(compact('name', 'status', 'goal', 'progress') + ['project_id' => $project->id, 'model' => str_contains($name, 'Benchmark') ? 'Prime RLM XL' : 'Prime RLM', 'tokens_used' => $tokens, 'last_seen_at' => now()->subMinutes(rand(1, 18))]);
            foreach ($tasks as $i => $title) {
                AgentTask::create(['agent_id' => $agent->id, 'title' => $title, 'status' => $i === 0 ? ($progress === 100 ? 'complete' : 'active') : 'queued', 'progress' => $i === 0 ? $progress : 0]);
            }
        }
    }
}
