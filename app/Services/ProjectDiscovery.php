<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Str;

class ProjectDiscovery
{
    /**
     * @param  list<array<string, mixed>>  $sessions
     */
    public function syncFromSessions(array $sessions): void
    {
        $repositoryRoots = [];

        foreach ($sessions as $session) {
            $cwd = $session['cwd'] ?? null;
            if (! is_string($cwd)) {
                continue;
            }

            $repositoryRoot = $this->projectPath($cwd);
            if ($repositoryRoot !== null) {
                $repositoryRoots[$repositoryRoot] = true;
            }
        }

        foreach (array_keys($repositoryRoots) as $repositoryRoot) {
            $name = basename($repositoryRoot);
            $slugPrefix = Str::slug($name) ?: 'project';

            Project::query()->firstOrCreate(
                ['path' => $repositoryRoot],
                [
                    'name' => $name,
                    'slug' => $slugPrefix.'-'.Str::lower(Str::random(4)),
                    'color' => $this->nextColor(),
                    'description' => 'Discovered from Prime Agent sessions.',
                ],
            );
        }
    }

    public function projectPath(string $cwd): ?string
    {
        $path = realpath($cwd);
        if ($path === false || ! is_dir($path)) {
            return null;
        }

        while (true) {
            if (file_exists($path.'/.git')) {
                return $path;
            }

            $parent = dirname($path);
            if ($parent === $path) {
                return null;
            }

            $path = $parent;
        }
    }

    private function nextColor(): string
    {
        $colors = ['#C8FF58', '#8B7CFF', '#52D9CB', '#FF9E6D'];

        return $colors[Project::query()->count() % count($colors)];
    }
}
