<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\PrimeAgentRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_dashboard_shows_real_onboarding_instead_of_samples(): void
    {
        $runtime = Mockery::mock(PrimeAgentRuntime::class);
        $runtime->shouldReceive('isAvailable')->andReturn(false);
        $runtime->shouldReceive('binary')->andReturn(null);
        $this->app->instance(PrimeAgentRuntime::class, $runtime);

        $this->get('/')
            ->assertOk()
            ->assertSee('Let’s get you running.')
            ->assertSee('There is no demo data here')
            ->assertSee('Install Prime Agent')
            ->assertDontSee('Schema Scout');
    }

    public function test_git_project_can_be_connected(): void
    {
        $this->post('/projects', [
            'name' => 'Prime Agent Web',
            'path' => base_path(),
            'description' => 'The real dashboard',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('projects', ['name' => 'Prime Agent Web', 'path' => base_path()]);
    }

    public function test_non_git_directory_is_rejected(): void
    {
        $this->post('/projects', ['name' => 'Not a repo', 'path' => sys_get_temp_dir()])
            ->assertSessionHasErrors('path');
    }

    public function test_agent_deployment_calls_the_real_runtime(): void
    {
        $project = Project::create([
            'name' => 'Control Plane', 'slug' => 'control-plane', 'path' => base_path(),
        ]);
        $runtime = Mockery::mock(PrimeAgentRuntime::class);
        $runtime->shouldReceive('isAvailable')->once()->andReturn(true);
        $runtime->shouldReceive('ensureDaemon')->once()->andReturn(['online' => true, 'error' => null]);
        $runtime->shouldReceive('create')->once()->with('Release Pilot', base_path(), 'Prepare a safe release.')
            ->andReturn(['activeSessionId' => 'session-1']);
        $this->app->instance(PrimeAgentRuntime::class, $runtime);

        $this->post('/agents', [
            'project_id' => $project->id,
            'name' => 'Release Pilot',
            'goal' => 'Prepare a safe release.',
        ])->assertRedirect()->assertSessionHas('success');
    }

    public function test_missing_prime_agent_blocks_deployment(): void
    {
        $project = Project::create([
            'name' => 'Control Plane', 'slug' => 'control-plane', 'path' => base_path(),
        ]);
        $runtime = Mockery::mock(PrimeAgentRuntime::class);
        $runtime->shouldReceive('isAvailable')->once()->andReturn(false);
        $this->app->instance(PrimeAgentRuntime::class, $runtime);

        $this->post('/agents', [
            'project_id' => $project->id,
            'name' => 'Release Pilot',
            'goal' => 'Prepare a safe release.',
        ])->assertSessionHasErrors('prime_agent');
    }
}
