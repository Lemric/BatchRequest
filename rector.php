<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Doctrine\Set\DoctrineSetList;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;
use Rector\Symfony\Set\SymfonySetList;
use Rector\TypeDeclaration\Rector\Property\TypedPropertyFromStrictConstructorRector;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ]);
    $rectorConfig->rule(TypedPropertyFromStrictConstructorRector::class);

    $rectorConfig->import(DoctrineSetList::ANNOTATIONS_TO_ATTRIBUTES);
    $rectorConfig->import(SymfonySetList::ANNOTATIONS_TO_ATTRIBUTES);

    $rectorConfig->sets([
        DoctrineSetList::DOCTRINE_CODE_QUALITY,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::PHP_82,
        SetList::TYPE_DECLARATION,
        SetList::NAMING,
        LevelSetList::UP_TO_PHP_82
    ]);
};
