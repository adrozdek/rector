<?php declare(strict_types=1);

namespace Rector\DomainDrivenDesign\Tests\Rector\ObjectToScalarDocBlockRector;

use Rector\DomainDrivenDesign\Rector\ObjectToScalar\ObjectToScalarDocBlockRector;
use Rector\DomainDrivenDesign\Tests\Rector\ObjectToScalarDocBlockRector\Source\SomeValueObject;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

final class ObjectToScalarDocBlockRectorTest extends AbstractRectorTestCase
{
    public function test(): void
    {
        $this->doTestFiles([
            __DIR__ . '/Fixture/nullable_property.php.inc',
            __DIR__ . '/Fixture/fixture2.php.inc',
            __DIR__ . '/Fixture/fixture3.php.inc',
        ]);
    }

    /**
     * @return mixed[]
     */
    protected function getRectorsWithConfiguration(): array
    {
        return [
            ObjectToScalarDocBlockRector::class => [
                '$valueObjectsToSimpleTypes' => [
                    SomeValueObject::class => 'string',
                ],
            ],
        ];
    }
}
