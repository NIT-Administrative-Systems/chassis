<?php

declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\CodeQuality\Rector\Class_\CompleteDynamicPropertiesRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\StaticCall\RemoveParentCallWithoutParentRector;
use Rector\EarlyReturn\Rector\Return_\ReturnBinaryOrToEarlyReturnRector;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\PHPUnit;
use Rector\Set\ValueObject\SetList;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use Rector\TypeDeclaration\Rector\Closure\AddClosureVoidReturnTypeWhereNoReturnRector;
use Rector\TypeDeclaration\Rector\Closure\ClosureReturnTypeRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withSets([
        SetList::PHP_83,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::EARLY_RETURN,
        SetList::TYPE_DECLARATION,
    ])
    ->withDowngradeSets(php83: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withSkip([
        __DIR__ . '/vendor',
        AddClosureVoidReturnTypeWhereNoReturnRector::class,
        ReturnBinaryOrToEarlyReturnRector::class,
        ClosureReturnTypeRector::class,
        AddArrowFunctionReturnTypeRector::class,
        RemoveParentCallWithoutParentRector::class,
        CompleteDynamicPropertiesRector::class,
        AddOverrideAttributeToOverriddenMethodsRector::class,
    ])
    ->withRules([
        // PHPUnit: annotations to attributes + provider conventions
        PHPUnit\AnnotationsToAttributes\Rector\Class_\CoversAnnotationWithValueToAttributeRector::class,
        PHPUnit\AnnotationsToAttributes\Rector\ClassMethod\DataProviderAnnotationToAttributeRector::class,
        PHPUnit\PHPUnit110\Rector\Class_\NamedArgumentForDataProviderRector::class,
        PHPUnit\PHPUnit70\Rector\Class_\RemoveDataProviderTestPrefixRector::class,
        PHPUnit\PHPUnit100\Rector\Class_\PublicDataProviderClassMethodRector::class,
        PHPUnit\PHPUnit100\Rector\Class_\StaticDataProviderClassMethodRector::class,

        // PHPUnit: assertion modernizations + code quality
        PHPUnit\PHPUnit90\Rector\MethodCall\ReplaceAtMethodWithDesiredMatcherRector::class,
        PHPUnit\PHPUnit80\Rector\MethodCall\SpecificAssertContainsRector::class,
        PHPUnit\PHPUnit100\Rector\MethodCall\PropertyExistsWithoutAssertRector::class,
        PHPUnit\CodeQuality\Rector\MethodCall\AssertNotOperatorRector::class,
        PHPUnit\CodeQuality\Rector\MethodCall\AssertCompareOnCountableWithMethodToAssertCountRector::class,
        PHPUnit\CodeQuality\Rector\MethodCall\FlipAssertRector::class,
        PHPUnit\CodeQuality\Rector\FuncCall\AssertFuncCallToPHPUnitAssertRector::class,
        PHPUnit\CodeQuality\Rector\Class_\PreferPHPUnitThisCallRector::class,
    ])
    ->withCache(
        cacheDirectory: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rector',
        cacheClass: FileCacheStorage::class,
    )
    ->withParallel();
