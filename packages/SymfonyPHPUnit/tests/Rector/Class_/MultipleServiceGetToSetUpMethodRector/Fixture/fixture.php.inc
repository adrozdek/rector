<?php

namespace Rector\SymfonyPHPUnit\Tests\Rector\Class_\MultipleServiceGetToSetUpMethodRector\Fixture;

use Rector\SymfonyPHPUnit\Tests\Rector\Class_\MultipleServiceGetToSetUpMethodRector\Source\ItemRepository;
use Rector\SymfonyPHPUnit\Tests\Rector\Class_\MultipleServiceGetToSetUpMethodRector\Source\DummyKernelTestCase;

class SomeTest extends DummyKernelTestCase
{
    public function testOne()
    {
        $itemRepository = self::$container->get(ItemRepository::class);
        $itemRepository->doStuff();
    }

    public function testTwo()
    {
        $itemRepository = self::$container->get(ItemRepository::class);
        $itemRepository->doAnotherStuff();
    }
}

?>
-----
<?php

namespace Rector\SymfonyPHPUnit\Tests\Rector\Class_\MultipleServiceGetToSetUpMethodRector\Fixture;

use Rector\SymfonyPHPUnit\Tests\Rector\Class_\MultipleServiceGetToSetUpMethodRector\Source\ItemRepository;
use Rector\SymfonyPHPUnit\Tests\Rector\Class_\MultipleServiceGetToSetUpMethodRector\Source\DummyKernelTestCase;

class SomeTest extends DummyKernelTestCase
{
    /**
     * @var \Rector\SymfonyPHPUnit\Tests\Rector\Class_\MultipleServiceGetToSetUpMethodRector\Source\ItemRepository
     */
    private $itemRepository;
    protected function setUp()
    {
        parent::setUp();
        $this->itemRepository = self::$container->get(\Rector\SymfonyPHPUnit\Tests\Rector\Class_\MultipleServiceGetToSetUpMethodRector\Source\ItemRepository::class);
    }
    public function testOne()
    {
        $this->itemRepository->doStuff();
    }

    public function testTwo()
    {
        $this->itemRepository->doAnotherStuff();
    }
}

?>
