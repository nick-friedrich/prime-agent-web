<?php

namespace Tests\Unit;

use App\Services\LocalProjectDirectories;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class LocalProjectDirectoriesTest extends TestCase
{
    private string $temporaryHome;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryHome = sys_get_temp_dir().'/prime-agent-directories-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->temporaryHome, 0755, true);
        config([
            'projects.home' => $this->temporaryHome,
            'projects.discovery_roots' => $this->temporaryHome.'/dev',
            'projects.max_depth' => 5,
            'projects.max_directories' => 100,
            'projects.cache_seconds' => 0,
            'cache.default' => 'array',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryHome);

        parent::tearDown();
    }

    public function test_it_discovers_and_fuzzy_searches_repositories_while_skipping_dependencies(): void
    {
        $this->makeDirectory('dev');
        $first = $this->makeDirectory('dev/prime-agent-web');
        $second = $this->makeDirectory('dev/tools/fuzzy-target');
        $ignored = $this->makeDirectory('dev/vendor/ignored-repository');
        File::makeDirectory($first.'/.git');
        File::put($second.'/.git', 'gitdir: /tmp/example');
        File::makeDirectory($ignored.'/.git');

        $results = (new LocalProjectDirectories)->search('fzt');

        $this->assertSame('fuzzy-target', $results[0]['name']);
        $this->assertSame(realpath($second), $results[0]['path']);
        $this->assertFalse(collect($results)->contains('name', 'ignored-repository'));
        $this->assertTrue((new LocalProjectDirectories)->isGitRepository($second));
    }

    public function test_discovery_skips_unreadable_directories(): void
    {
        $repository = $this->makeDirectory('dev/private-repository');
        File::makeDirectory($repository.'/.git');
        chmod($repository, 0000);

        try {
            if (is_readable($repository)) {
                $this->markTestSkipped('The test process can read permissionless directories.');
            }

            $this->assertSame([], (new LocalProjectDirectories)->search('private-repository'));
        } finally {
            chmod($repository, 0755);
        }
    }

    public function test_scan_depth_and_directory_limit_are_enforced(): void
    {
        $deepRepository = $this->makeDirectory('dev/one/two/repository');
        File::makeDirectory($deepRepository.'/.git');
        config(['projects.max_depth' => 1]);

        $this->assertSame([], (new LocalProjectDirectories)->search());

        config(['projects.max_depth' => 5, 'projects.max_directories' => 1]);
        $this->assertSame([], (new LocalProjectDirectories)->search());
    }

    public function test_browsing_returns_canonical_home_scoped_directories(): void
    {
        $repository = $this->makeDirectory('workspace/example');
        File::makeDirectory($repository.'/.git');
        $outside = sys_get_temp_dir().'/prime-agent-outside-'.bin2hex(random_bytes(6));
        File::makeDirectory($outside);
        symlink($outside, $this->temporaryHome.'/outside-link');

        try {
            $home = (new LocalProjectDirectories)->browse();
            $workspace = (new LocalProjectDirectories)->browse($this->temporaryHome.'/workspace/../workspace');

            $this->assertSame(realpath($this->temporaryHome), $home['current']);
            $this->assertNull($home['parent']);
            $this->assertSame(realpath($this->temporaryHome.'/workspace'), $workspace['current']);
            $this->assertSame([[
                'name' => 'example',
                'path' => realpath($repository),
                'is_git' => true,
            ]], $workspace['directories']);
            $this->assertFalse(collect($home['directories'])->contains('name', 'outside-link'));
        } finally {
            File::delete($this->temporaryHome.'/outside-link');
            File::deleteDirectory($outside);
        }
    }

    public function test_browsing_rejects_missing_and_out_of_home_paths(): void
    {
        $directories = new LocalProjectDirectories;

        try {
            $directories->browse($this->temporaryHome.'/missing');
            $this->fail('Missing directories should be rejected.');
        } catch (InvalidArgumentException $error) {
            $this->assertSame('Choose an existing directory.', $error->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('limited to your home directory');
        $directories->browse(sys_get_temp_dir());
    }

    public function test_browsing_rejects_a_symlink_that_escapes_home(): void
    {
        $outside = sys_get_temp_dir().'/prime-agent-symlink-target-'.bin2hex(random_bytes(6));
        File::makeDirectory($outside);
        $link = $this->temporaryHome.'/escaped';
        symlink($outside, $link);

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('limited to your home directory');
            (new LocalProjectDirectories)->browse($link);
        } finally {
            File::delete($link);
            File::deleteDirectory($outside);
        }
    }

    private function makeDirectory(string $relativePath): string
    {
        $path = $this->temporaryHome.'/'.$relativePath;
        File::makeDirectory($path, 0755, true);

        return $path;
    }
}
