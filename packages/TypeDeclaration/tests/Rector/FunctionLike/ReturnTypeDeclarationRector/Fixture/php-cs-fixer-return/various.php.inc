<?php

namespace Rector\TypeDeclaration\Tests\Rector\ClassMethod\ReturnTypeDeclarationRector\Fixture\PhpCsFixerReturn\Various;

/** @return Bar */ function my_foo($value) {
    return $value;
}
/** @return My\Bar */ function my_foo2($value) {
    return $value;
}
/** @return \My\Bar */ function my_foo3($value) {
    return $value;
}
interface Foo {
    /** @return Bar */
    function my_foo4($value);
}
/** @return void */ function my_foo5() {
}

?>
-----
<?php

namespace Rector\TypeDeclaration\Tests\Rector\ClassMethod\ReturnTypeDeclarationRector\Fixture\PhpCsFixerReturn\Various;

/** @return Bar */ function my_foo($value): \Rector\TypeDeclaration\Tests\Rector\ClassMethod\ReturnTypeDeclarationRector\Fixture\PhpCsFixerReturn\Various\Bar {
    return $value;
}
/** @return My\Bar */ function my_foo2($value): \Rector\TypeDeclaration\Tests\Rector\ClassMethod\ReturnTypeDeclarationRector\Fixture\PhpCsFixerReturn\Various\My\Bar {
    return $value;
}
/** @return \My\Bar */ function my_foo3($value): \My\Bar {
    return $value;
}
interface Foo {
    /** @return Bar */
    function my_foo4($value): \Rector\TypeDeclaration\Tests\Rector\ClassMethod\ReturnTypeDeclarationRector\Fixture\PhpCsFixerReturn\Various\Bar;
}
/** @return void */ function my_foo5(): void {
}

?>
