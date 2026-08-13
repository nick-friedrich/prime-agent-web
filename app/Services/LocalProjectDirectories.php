<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

class LocalProjectDirectories
{
    /** @var list<string> */
    private const SKIPPED_DIRECTORIES = [
        '.git', 'node_modules', 'vendor', 'build', 'dist', 'target', 'storage',
    ];

    /**
     * @return list<array{name: string, path: string}>
     */
    public function search(string $query = ''): array
    {
        $repositories = $this->repositories();
        $query = trim($query);

        if ($query === '') {
            usort($repositories, fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

            return $repositories;
        }

        $matches = [];
        foreach ($repositories as $repository) {
            $score = $this->fuzzyScore($query, $repository['name'].' '.$repository['path']);
            if ($score !== null) {
                $matches[] = ['score' => $score, 'repository' => $repository];
            }
        }

        usort($matches, function (array $left, array $right): int {
            return $right['score'] <=> $left['score']
                ?: strcasecmp($left['repository']['name'], $right['repository']['name']);
        });

        return array_map(
            fn (array $match): array => $match['repository'],
            $matches,
        );
    }

    /**
     * @return array{current: string, parent: string|null, directories: list<array{name: string, path: string, is_git: bool}>, is_git: bool}
     */
    public function browse(?string $requestedPath = null): array
    {
        $home = $this->homePath();
        $path = realpath($requestedPath ?: $home);

        if ($path === false || ! is_dir($path)) {
            throw new InvalidArgumentException('Choose an existing directory.');
        }
        if (! $this->isWithin($path, $home)) {
            throw new InvalidArgumentException('Directory browsing is limited to your home directory.');
        }
        if (! is_readable($path)) {
            throw new InvalidArgumentException('This directory cannot be read.');
        }

        $entries = scandir($path);
        if ($entries === false) {
            throw new InvalidArgumentException('This directory cannot be read.');
        }

        $directories = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }

            $child = realpath($path.DIRECTORY_SEPARATOR.$entry);
            if ($child === false || ! is_dir($child) || ! is_readable($child) || ! $this->isWithin($child, $home)) {
                continue;
            }

            $directories[$child] = [
                'name' => $entry,
                'path' => $child,
                'is_git' => $this->isGitRepository($child),
            ];
        }

        $directories = array_values($directories);
        usort($directories, fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        return [
            'current' => $path,
            'parent' => $path === $home ? null : dirname($path),
            'directories' => $directories,
            'is_git' => $this->isGitRepository($path),
        ];
    }

    public function isGitRepository(string $path): bool
    {
        return file_exists(rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.git');
    }

    /**
     * @return list<array{name: string, path: string}>
     */
    private function repositories(): array
    {
        $roots = $this->searchRoots();
        $cacheKey = 'local-project-directories.'.sha1(implode('|', [
            ...$roots,
            (string) $this->integerConfig('projects.max_depth', 5),
            (string) $this->integerConfig('projects.max_directories', 5000),
        ]));
        $cached = Cache::remember(
            $cacheKey,
            max(0, $this->integerConfig('projects.cache_seconds', 60)),
            fn (): array => $this->scan($roots),
        );

        return $cached;
    }

    /**
     * @param  list<string>  $roots
     * @return list<array{name: string, path: string}>
     */
    private function scan(array $roots): array
    {
        $maxDepth = max(0, $this->integerConfig('projects.max_depth', 5));
        $maxDirectories = max(1, $this->integerConfig('projects.max_directories', 5000));
        $queue = array_map(fn (string $root): array => [$root, 0], $roots);
        $visited = [];
        $repositories = [];
        $directoryCount = 0;

        while ($queue !== [] && $directoryCount < $maxDirectories) {
            [$candidate, $depth] = array_shift($queue);
            $path = realpath($candidate);
            if ($path === false || isset($visited[$path]) || ! is_dir($path) || ! is_readable($path)) {
                continue;
            }

            $visited[$path] = true;
            $directoryCount++;

            if ($this->isGitRepository($path)) {
                $repositories[$path] = ['name' => basename($path), 'path' => $path];

                continue;
            }
            if ($depth >= $maxDepth) {
                continue;
            }

            $entries = scandir($path);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($this->shouldSkip($entry)) {
                    continue;
                }

                $child = $path.DIRECTORY_SEPARATOR.$entry;
                if (is_dir($child) && ! is_link($child)) {
                    $queue[] = [$child, $depth + 1];
                }
            }
        }

        return array_values($repositories);
    }

    /** @return list<string> */
    private function searchRoots(): array
    {
        $configured = config('projects.discovery_roots', '');
        $rootList = is_string($configured) ? explode(PATH_SEPARATOR, $configured) : $configured;
        if (! is_array($rootList)) {
            return [];
        }

        $home = $this->homePath();
        $roots = [];
        foreach ($rootList as $root) {
            if (! is_string($root) || trim($root) === '') {
                continue;
            }

            $expanded = trim($root);
            if ($expanded === '~') {
                $expanded = $home;
            } elseif (str_starts_with($expanded, '~/')) {
                $expanded = $home.substr($expanded, 1);
            }

            $path = realpath($expanded);
            if ($path !== false && is_dir($path) && is_readable($path)) {
                $roots[$path] = $path;
            }
        }

        return array_values($roots);
    }

    private function homePath(): string
    {
        $configured = config('projects.home');
        $home = is_string($configured) && $configured !== '' ? $configured : getenv('HOME');
        $path = is_string($home) ? realpath($home) : false;

        if ($path === false || ! is_dir($path)) {
            throw new InvalidArgumentException('The current user home directory is unavailable.');
        }

        return $path;
    }

    private function isWithin(string $path, string $home): bool
    {
        return $path === $home || str_starts_with($path, $home.DIRECTORY_SEPARATOR);
    }

    private function shouldSkip(string $entry): bool
    {
        return $entry === '.'
            || $entry === '..'
            || str_starts_with($entry, '.')
            || in_array($entry, self::SKIPPED_DIRECTORIES, true);
    }

    private function fuzzyScore(string $query, string $candidate): ?int
    {
        $query = mb_strtolower($query);
        $candidate = mb_strtolower($candidate);
        $position = 0;
        $score = 0;
        $lastMatch = -2;

        foreach (mb_str_split($query) as $character) {
            $match = mb_strpos($candidate, $character, $position);
            if ($match === false) {
                return null;
            }

            $score += $match === $lastMatch + 1 ? 8 : max(1, 5 - ($match - $position));
            if ($match === 0 || in_array(mb_substr($candidate, $match - 1, 1), ['/', '-', '_', ' '], true)) {
                $score += 6;
            }
            $lastMatch = $match;
            $position = $match + 1;
        }

        if (str_contains($candidate, $query)) {
            $score += 30;
        }

        return $score;
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) || (is_string($value) && is_numeric($value))
            ? (int) $value
            : $default;
    }
}
