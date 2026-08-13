<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Services\ProjectDiscovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_are_inferred_from_session_working_directories(): void
    {
        $discovery = new ProjectDiscovery;
        $discovery->syncFromSessions([
            ['activeSessionId' => 'session-1', 'cwd' => base_path('app/Http')],
            ['activeSessionId' => 'session-2', 'cwd' => base_path()],
            ['activeSessionId' => 'session-3', 'cwd' => '/definitely/not/a/local/project'],
            ['activeSessionId' => 'session-4'],
        ]);

        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseHas('projects', [
            'name' => 'prime-agent-web',
            'path' => base_path(),
            'description' => 'Discovered from Prime Agent sessions.',
        ]);
        $this->assertSame(base_path(), $discovery->projectPath(base_path('app/Http')));
    }

    public function test_discovery_does_not_duplicate_or_overwrite_a_connected_project(): void
    {
        Project::create([
            'name' => 'My custom name',
            'slug' => 'my-custom-name',
            'path' => base_path(),
            'description' => 'Keep this description.',
        ]);

        $discovery = new ProjectDiscovery;
        $sessions = [['activeSessionId' => 'session-1', 'cwd' => base_path('app')]];
        $discovery->syncFromSessions($sessions);
        $discovery->syncFromSessions($sessions);

        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseHas('projects', [
            'name' => 'My custom name',
            'path' => base_path(),
            'description' => 'Keep this description.',
        ]);
    }
}
