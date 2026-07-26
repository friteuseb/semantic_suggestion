<?php
defined('TYPO3') or die();

use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Cache\Backend\FileBackend;
use TYPO3\CMS\Core\Log\LogLevel;
use TYPO3\CMS\Core\Log\Writer\FileWriter;

(static function() {

    $versionInformation = GeneralUtility::makeInstance(Typo3Version::class);
    $isTypo3Version12OrLower = $versionInformation->getMajorVersion() < 13;

    // Frontend plugin 'Suggestions' configuration (unconditional)
    ExtensionUtility::configurePlugin(
        'SemanticSuggestion', 'Suggestions',
        [\TalanHdf\SemanticSuggestion\Controller\SuggestionsController::class => 'list'],
        []  
    );

    // Backend plugin 'SemanticBackend' configuration (conditional based on TYPO3 version)
    if ($isTypo3Version12OrLower) {
        ExtensionUtility::configurePlugin(
            'SemanticSuggestion', 'SemanticBackend',
            [\TalanHdf\SemanticSuggestion\Controller\LegacySemanticBackendController::class => 'index'], // V12
            [\TalanHdf\SemanticSuggestion\Controller\LegacySemanticBackendController::class => 'index']  // V12 Non-cacheable
        );
    } else {
        ExtensionUtility::configurePlugin(
            'SemanticSuggestion', 'SemanticBackend',
            [\TalanHdf\SemanticSuggestion\Controller\SemanticBackendController::class => 'index'], // V13
            [\TalanHdf\SemanticSuggestion\Controller\SemanticBackendController::class => 'index']  // V13 Non-cacheable
        );
    }

    // DataHandler cache clearing.
    // Registered unconditionally: DataHandler still consumes these hooks in TYPO3 14.3,
    // and the AfterDatamapOperationEvent / AfterCommandmapOperationEvent classes the
    // previous EventListener subscribed to never existed in any core version, which left
    // cache clearing completely dead on v14 (see #24).
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = \TalanHdf\SemanticSuggestion\Hooks\DataHandlerHook::class;
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][] = \TalanHdf\SemanticSuggestion\Hooks\DataHandlerHook::class;

    // TypoScript setup and constants import (unconditional)
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptSetup('@import "EXT:semantic_suggestion/Configuration/TypoScript/setup.typoscript"');
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptConstants('@import "EXT:semantic_suggestion/Configuration/TypoScript/constants.typoscript"');

    // Cache configuration (unconditional)
    if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['semantic_suggestion'])) {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['semantic_suggestion'] = [
            'frontend' => VariableFrontend::class, 'backend' => FileBackend::class,
            'options' => ['defaultLifetime' => 86400], 'groups' => ['pages']
        ];
    }

    // Upgrade wizard registration for TYPO3 12, which discovers wizards through
    // SC_OPTIONS. TYPO3 13+ picks it up from the install.upgradewizard DI tag
    // declared in Configuration/Services.php.
    if ($isTypo3Version12OrLower) {
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['ext/install']['update']['semanticSuggestionMigrateRootPageId']
            = \TalanHdf\SemanticSuggestion\Upgrades\MigrateRootPageIdUpgradeWizard::class;
    }

    // Scheduler task registration for background similarity generation
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][\TalanHdf\SemanticSuggestion\Task\GenerateSimilaritiesTask::class] = [
        'extension' => 'semantic_suggestion',
        'title' => 'LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:scheduler.task.title',
        'description' => 'LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_be.xlf:scheduler.task.description',
        'additionalFields' => \TalanHdf\SemanticSuggestion\Task\GenerateSimilaritiesAdditionalFieldProvider::class
    ];

    // Logger configuration (simplified path logic)
    $logFilePath = 'typo3temp/logs/semantic_suggestion.log'; // Chemin simple et fiable
    // Vous pouvez essayer de rendre le chemin absolu :
    // $logFilePath = GeneralUtility::getFileAbsFileName($logFilePath);
    $GLOBALS['TYPO3_CONF_VARS']['LOG']['TalanHdf']['SemanticSuggestion']['writerConfiguration'] = [
        LogLevel::DEBUG => [ FileWriter::class => [ 'logFile' => $logFilePath ] ],
    ];

})();