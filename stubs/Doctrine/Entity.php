<?php

namespace Doctrine\ORM\Mapping;

if (interface_exists('Doctrine\ORM\Mapping\Entity')) {
    return;
}

/**
 * @Annotation
 * @Target("CLASS")
 */
final class Entity implements Annotation
{
    /**
     * @var string
     */
    public $repositoryClass;

    /**
     * @var boolean
     */
    public $readOnly = false;
}
