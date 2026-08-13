<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_projects_and_agents(): void
    {
        $project = Project::create(['name' => 'Test Project', 'slug' => 'test-project']);
        Agent::create(['project_id' => $project->id, 'name' => 'Test Agent', 'goal' => 'Verify the dashboard', 'status' => 'running']);

        $this->get('/')->assertOk()->assertSee('Mission control')->assertSee('Test Project')->assertSee('Test Agent');
    }

    public function test_project_can_be_created(): void
    {
        $this->post('/projects', ['name' => 'New Runtime', 'repository' => 'prime/runtime', 'description' => 'Runtime services'])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('projects', ['name' => 'New Runtime', 'repository' => 'prime/runtime']);
    }

    public function test_agent_can_be_deployed_and_paused(): void
    {
        config(['services.prime_agent.binary' => PHP_BINARY]);
        $project = Project::create(['name' => 'Control Plane', 'slug' => 'control-plane']);
        $this->post('/agents', ['project_id' => $project->id, 'name' => 'Release Pilot', 'model' => 'Prime RLM', 'goal' => 'Prepare a safe release.'])
            ->assertRedirect()->assertSessionHas('success');

        $agent = Agent::firstOrFail();
        $this->assertSame('running', $agent->status);
        $this->assertDatabaseHas('agent_tasks', ['agent_id' => $agent->id, 'status' => 'active']);

        $this->patch("/agents/{$agent->id}", ['status' => 'paused'])->assertRedirect();
        $this->assertDatabaseHas('agents', ['id' => $agent->id, 'status' => 'paused']);
    }

    public function test_missing_prime_agent_is_shown_and_blocks_deployment(): void
    {
        config(['services.prime_agent.binary' => '/definitely/missing/prime-agent']);
        $project = Project::create(['name' => 'Control Plane', 'slug' => 'control-plane']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Prime Agent is not available')
            ->assertSee('PRIME_AGENT_BINARY');

        $this->post('/agents', [
            'project_id' => $project->id,
            'name' => 'Release Pilot',
            'model' => 'Prime RLM',
            'goal' => 'Prepare a safe release.',
        ])->assertRedirect()->assertSessionHasErrors('prime_agent');

        $this->assertDatabaseCount('agents', 0);
    }
}
