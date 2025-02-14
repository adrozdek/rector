<?php declare(strict_types=1);

namespace Rector\Doctrine\Tests\Rector\Class_\AddUuidMirrorForRelationPropertyRector;

use Rector\Doctrine\Rector\Class_\AddUuidMirrorForRelationPropertyRector;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

final class AddUuidMirrorForRelationPropertyRectorTest extends AbstractRectorTestCase
{
    public function test(): void
    {
        $this->doTestFiles([
            __DIR__ . '/Fixture/fixture.php.inc',
            __DIR__ . '/Fixture/skip_already_added.php.inc',
            __DIR__ . '/Fixture/to_many.php.inc',
        ]);
    }

    protected function getRectorClass(): string
    {
        return AddUuidMirrorForRelationPropertyRector::class;
    }
}
