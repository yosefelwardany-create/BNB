<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Domain\Users\Support\PermissionRegistry;
use Tests\TestCase;

/**
 * Every permission the interface asks about must be one the server grants.
 *
 * This exists because of a real failure, and it is a quiet one. The admin screens
 * hide a control the signed-in person may not use — `can('reviews.manage')` — and
 * a permission that does not exist is never held by anybody, so the control is
 * hidden from *everyone*, including the administrator. No error, no warning, no
 * failing test: the button simply is not there, and the feature looks unbuilt.
 *
 * Two of them shipped that way — `reviews.manage` and `calendar.manage`, where
 * the real names are `reviews.respond` and `calendar.update` — and a person had
 * to notice a missing button to find them.
 *
 * Neither half of the stack can catch this alone: TypeScript does not know what
 * the registry contains, and PHPUnit does not read the screens. So this test
 * reads them, which is ugly and worth it.
 */
class InterfacePermissionNamesTest extends TestCase
{
    public function test_every_permission_named_in_the_interface_exists(): void
    {
        $unknown = [];

        foreach ($this->permissionsUsedInTheInterface() as $permission => $files) {
            if (! PermissionRegistry::exists($permission)) {
                $unknown[] = sprintf('%s (in %s)', $permission, implode(', ', array_unique($files)));
            }
        }

        $this->assertSame([], $unknown, implode("\n", [
            'The interface asks about permissions the server does not define.',
            'A control gated on one of these is hidden from everybody, including an administrator:',
            ...$unknown,
        ]));
    }

    public function test_the_scan_actually_finds_something(): void
    {
        // Without this, a change to the interface's layout that stopped the
        // regex matching would leave the test above passing against nothing —
        // green, and checking no more than an empty list.
        $found = $this->permissionsUsedInTheInterface();

        $this->assertGreaterThan(15, count($found));
        $this->assertArrayHasKey('properties.create', $found);
    }

    /**
     * Every `can('…')` and `canAny('…')` argument in the admin application.
     *
     * @return array<string, list<string>>
     */
    private function permissionsUsedInTheInterface(): array
    {
        $found = [];

        foreach ($this->sourceFiles() as $path) {
            $source = (string) file_get_contents($path);

            preg_match_all("/\bcan(?:Any)?\(\s*((?:'[^']+'\s*,?\s*)+)\)/", $source, $matches);

            foreach ($matches[1] as $arguments) {
                preg_match_all("/'([^']+)'/", $arguments, $names);

                foreach ($names[1] as $permission) {
                    // The wildcard a platform administrator holds is not a
                    // permission in the registry and never will be.
                    if ($permission === '*') {
                        continue;
                    }

                    $found[$permission][] = basename($path);
                }
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $root = base_path('frontend/src');

        if (! is_dir($root)) {
            $this->markTestSkipped('The admin application is not present in this checkout.');
        }

        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile()
                && in_array($file->getExtension(), ['ts', 'tsx'], true)
                // Test fixtures name permissions that are deliberately made up.
                && ! str_contains($file->getFilename(), '.test.')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
