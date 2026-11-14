<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\LoanField;

use PHPUnit\Framework\TestCase;
use Plugins\LoanField\Provider;

/**
 * module.json and Provider.php are two statements of one fact. These tests
 * hold them to each other, and hold the Domain layer to its zero-imports rule.
 */
final class ManifestTest extends TestCase
{
    private const string ROOT = __DIR__ . '/..';

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $json = file_get_contents(self::ROOT . '/module.json');
        self::assertIsString($json);

        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function test_the_module_claims_the_domain_its_provider_solves(): void
    {
        $m = $this->manifest();
        $p = new Provider();

        self::assertSame('field.operations', $m['solves']);
        self::assertSame($m['solves'], $p->solves());
    }

    public function test_requires_mirror_between_manifest_and_provider(): void
    {
        $m = $this->manifest();
        $p = new Provider();

        $declared = $m['requires'];
        sort($declared);
        $wired = $p->requires();
        sort($wired);

        self::assertSame($declared, $wired);
        // organisation.structure supplies the user -> staff lookup that
        // `recorded_by` resolves through; it is a staff id, not a user id.
        self::assertSame(
            ['database.management', 'loans.management', 'organisation.structure', 'sync.offline'],
            $declared,
        );
    }

    public function test_exposes_mirror_between_manifest_and_provider(): void
    {
        $m = $this->manifest();
        $p = new Provider();

        self::assertSame($p->exposes(), $m['exposes']);
    }

    public function test_every_route_is_behind_auth_and_under_the_prefix(): void
    {
        $m = $this->manifest();

        self::assertSame('/api/field', $m['routePrefix']);
        self::assertContains('auth', $m['routeFilters']);
    }

    public function test_every_route_names_a_real_public_method_and_has_a_unique_name(): void
    {
        $m     = $this->manifest();
        $names = [];

        self::assertNotEmpty($m['routes']);

        foreach ($m['routes'] as $route) {
            self::assertContains($route['method'], ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']);
            self::assertStringStartsWith('/', $route['path'], 'a path must start with /');

            self::assertSame(1, substr_count($route['handler'], '@'), 'a handler is exactly Controller@method');
            [$class, $method] = explode('@', $route['handler']);

            self::assertTrue(class_exists($class), 'handler class must exist: ' . $class);
            self::assertTrue(method_exists($class, $method), 'handler method must exist: ' . $route['handler']);
            self::assertTrue((new \ReflectionMethod($class, $method))->isPublic());

            self::assertNotContains($route['name'], $names, 'duplicate route name: ' . $route['name']);
            $names[] = $route['name'];
        }
    }

    public function test_the_domain_layer_imports_nothing(): void
    {
        $files = glob(self::ROOT . '/Domain/*/*.php') ?: [];
        $files = array_merge($files, glob(self::ROOT . '/Domain/*.php') ?: []);

        self::assertNotEmpty($files);

        foreach ($files as $file) {
            $source = file_get_contents($file);
            self::assertIsString($source);

            self::assertDoesNotMatchRegularExpression(
                '/^use\s/m',
                $source,
                basename($file) . ' must have zero imports: Domain/ may not depend on anything outside itself',
            );
        }
    }

    public function test_every_env_key_the_provider_reads_is_declared_in_config(): void
    {
        $m        = $this->manifest();
        $declared = array_column($m['config'], 'key');

        $source = file_get_contents(self::ROOT . '/Provider.php');
        self::assertIsString($source);
        preg_match_all("/env\\('([A-Z0-9_]+)'\\)/", $source, $reads);

        self::assertNotEmpty($reads[1]);

        foreach ($reads[1] as $key) {
            self::assertContains($key, $declared, $key . ' is read by Provider but not declared in config[]');
        }
    }
}
