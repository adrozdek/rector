<?php

namespace Rector\TypeDeclaration\Tests\Rector\ClassMethod\ReturnTypeDeclarationRector\Fixture\Nikic\Nullable;

/** @return Foo|null */
function test($value) {
    return $value;
}

?>
-----
<?php

namespace Rector\TypeDeclaration\Tests\Rector\ClassMethod\ReturnTypeDeclarationRector\Fixture\Nikic\Nullable;

/** @return Foo|null */
function test($value): ?\Rector\TypeDeclaration\Tests\Rector\ClassMethod\ReturnTypeDeclarationRector\Fixture\Nikic\Nullable\Foo {
    return $value;
}

?>
