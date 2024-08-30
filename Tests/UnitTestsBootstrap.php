<?php

use TYPO3\TestingFramework\Core\SystemEnvironmentBuilder;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

call_user_func(function () {
    $testbase = new \TYPO3\TestingFramework\Core\Testbase();
    $testbase->defineOriginalRootPath();
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/tests');
    $testbase->createDirectory(ORIGINAL_ROOT . 'typo3temp/var/transient');

    SystemEnvironmentBuilder::run(0, SystemEnvironmentBuilder::REQUESTTYPE_BE | SystemEnvironmentBuilder::REQUESTTYPE_CLI);
    UnitTestCase::setClassIsSingletonForTesting();
});