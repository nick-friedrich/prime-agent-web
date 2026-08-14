<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\PrimeAgentRuntime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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

    public function test_git_project_can_be_connected_without_a_description(): void
    {
        $this->post('/projects', [
            'name' => 'No Description',
            'path' => base_path(),
            'description' => '',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('projects', [
            'name' => 'No Description',
            'description' => null,
        ]);
    }

    public function test_git_worktree_project_can_be_connected(): void
    {
        $worktree = sys_get_temp_dir().'/prime-agent-worktree-'.bin2hex(random_bytes(6));
        File::makeDirectory($worktree);
        File::put($worktree.'/.git', 'gitdir: /tmp/example');

        try {
            $this->post('/projects', [
                'name' => 'Worktree',
                'path' => $worktree,
            ])->assertRedirect()->assertSessionHas('success');

            $this->assertDatabaseHas('projects', [
                'name' => 'Worktree',
                'path' => realpath($worktree),
                'description' => null,
            ]);
        } finally {
            File::deleteDirectory($worktree);
        }
    }

    public function test_dashboard_exposes_directory_picker_and_optional_description(): void
    {
        $runtime = Mockery::mock(PrimeAgentRuntime::class);
        $runtime->shouldReceive('isAvailable')->andReturn(false);
        $runtime->shouldReceive('binary')->andReturn(null);
        $this->app->instance(PrimeAgentRuntime::class, $runtime);

        $this->get('/')
            ->assertOk()
            ->assertSee('Search repositories')
            ->assertSee('Browse folders')
            ->assertSee('Or enter an absolute path')
            ->assertSee('Description')
            ->assertSee('Optional');
    }

    public function test_directory_search_and_browse_endpoints_return_home_scoped_results(): void
    {
        $home = sys_get_temp_dir().'/prime-agent-endpoint-'.bin2hex(random_bytes(6));
        $repository = $home.'/dev/example-project';
        $outside = sys_get_temp_dir().'/prime-agent-endpoint-outside-'.bin2hex(random_bytes(6));
        File::makeDirectory($repository.'/.git', 0755, true);
        File::makeDirectory($outside);
        symlink($outside, $home.'/escaped');
        config([
            'projects.home' => $home,
            'projects.discovery_roots' => $home.'/dev',
            'projects.cache_seconds' => 0,
            'cache.default' => 'array',
        ]);

        try {
            $this->getJson('/project-directories/search?q=ept')
                ->assertOk()
                ->assertJsonPath('repositories.0.name', 'example-project')
                ->assertJsonPath('repositories.0.path', realpath($repository));

            $this->getJson('/project-directories/browse?path='.urlencode($home.'/dev'))
                ->assertOk()
                ->assertJsonPath('current', realpath($home.'/dev'))
                ->assertJsonPath('directories.0.is_git', true);

            $this->getJson('/project-directories/browse?path='.urlencode(sys_get_temp_dir()))
                ->assertUnprocessable()
                ->assertJsonPath('message', 'Directory browsing is limited to your home directory.');

            $this->getJson('/project-directories/browse?path='.urlencode($home.'/missing'))
                ->assertUnprocessable();

            $this->getJson('/project-directories/browse?path='.urlencode($home.'/dev/../..'))
                ->assertUnprocessable();

            $this->getJson('/project-directories/browse?path='.urlencode($home.'/escaped'))
                ->assertUnprocessable();
        } finally {
            File::delete($home.'/escaped');
            File::deleteDirectory($home);
            File::deleteDirectory($outside);
        }
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
        $runtime->shouldReceive('create')->once()->with(base_path(), 'Prepare a safe release.')
            ->andReturn(['activeSessionId' => 'session-1']);
        $this->app->instance(PrimeAgentRuntime::class, $runtime);

        $this->post('/agents', [
            'project_id' => $project->id,
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
            'goal' => 'Prepare a safe release.',
        ])->assertSessionHasErrors('prime_agent');
    }
}
