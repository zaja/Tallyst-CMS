<?php

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Fail-closed DISCIPLINE guard. The `^/admin` firewall only requires ROLE_EDITOR, so an EA
 * CRUD controller WITHOUT an explicit role guard is reachable by editors — a silent
 * privilege hole. This test fails if any CRUD controller lacks a class-level #[IsGranted],
 * forcing every new one to declare ROLE_ADMIN or ROLE_EDITOR on purpose.
 */
class CrudControllerAccessAnnotationTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function crudControllers(): iterable
    {
        $files = array_merge(
            glob(__DIR__.'/../../src/Controller/Admin/*CrudController.php') ?: [],
            glob(__DIR__.'/../../modules/*/Controller/Admin/*CrudController.php') ?: [],
        );

        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            if (!preg_match('/namespace\s+([^;]+);/', $src, $ns) || !preg_match('/\bclass\s+(\w+)/', $src, $cls)) {
                continue;
            }
            $fqcn = $ns[1].'\\'.$cls[1];
            yield $fqcn => [$fqcn];
        }
    }

    #[DataProvider('crudControllers')]
    public function testEveryCrudControllerCarriesAnExplicitRoleGuard(string $fqcn): void
    {
        $attributes = (new \ReflectionClass($fqcn))->getAttributes(IsGranted::class);

        self::assertNotEmpty(
            $attributes,
            $fqcn.' must carry a class-level #[IsGranted(...)] — the ^/admin firewall only requires '
            .'ROLE_EDITOR, so an unguarded admin CRUD would be reachable by editors.'
        );

        $role = $attributes[0]->getArguments()[0] ?? ($attributes[0]->getArguments()['attribute'] ?? null);
        self::assertContains($role, ['ROLE_ADMIN', 'ROLE_EDITOR'], $fqcn.' must grant ROLE_ADMIN or ROLE_EDITOR.');
    }
}
