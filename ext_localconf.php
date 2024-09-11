<?php
defined('TYPO3') or die();

(static function() {
    // Register a Fluid namespace for your extension's ViewHelpers
    // This allows you to easily use them in Fluid templates
    // $GLOBALS['TYPO3_CONF_VARS']['SYS']['fluid']['namespaces']['semanticSuggestion'] = ['TalanHdf\\SemanticSuggestion\\ViewHelpers'];

    // Configure an Extbase plugin named "Suggestions"
    // Associates the 'list' action of the SuggestionsController to this plugin
    \TYPO3\CMS\Extbase\Utility\ExtensionUtility::configurePlugin(
        'SemanticSuggestion', // Extension key
        'Suggestions', // Plugin name
        [
            \TalanHdf\SemanticSuggestion\Controller\SuggestionsController::class => 'list' 
        ]
    );

    \TYPO3\CMS\Extbase\Utility\ExtensionUtility::configurePlugin(
        'SemanticSuggestion',
        'SemanticBackend',
        [
            \TalanHdf\SemanticSuggestion\Controller\SemanticBackendController::class => 'index'
        ],
        // non-cacheable actions
        [
            \TalanHdf\SemanticSuggestion\Controller\SemanticBackendController::class => 'index'
        ]
    );
    

    // Register your DataHandlerHook to be executed during data processing and saving operations in TYPO3
    // This allows you to intervene in the data flow
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = 
        \TalanHdf\SemanticSuggestion\Hooks\DataHandlerHook::class;
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][] = 
        \TalanHdf\SemanticSuggestion\Hooks\DataHandlerHook::class;

    // Import the TypoScript file 'setup.typoscript' from your extension into the global TypoScript configuration
    // This file likely contains basic setup for your extension
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptSetup(
        '@import "EXT:semantic_suggestion/Configuration/TypoScript/setup.typoscript"'
    );


    // Import the TypoScript file 'constants.typoscript' into the global TypoScript configuration
    // This file likely contains constants used by your extension
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptConstants(
        '@import "EXT:semantic_suggestion/Configuration/TypoScript/constants.typoscript"'
    );

    // Configure a cache named 'semantic_suggestion' if it doesn't already exist
    // Uses a variable frontend and a file backend
    // Default cache lifetime is 86400 seconds (1 day)
    // The cache is associated with the 'pages' group, meaning it will be cleared when pages are modified
    if (!isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['semantic_suggestion'])) {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['semantic_suggestion'] = [
            'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
            'backend' => \TYPO3\CMS\Core\Cache\Backend\FileBackend::class,
            'options' => [
                'defaultLifetime' => 86400
            ],
            'groups' => ['pages']
        ];
    }

    $GLOBALS['TYPO3_CONF_VARS']['LOG']['TalanHdf']['SemanticSuggestion']['writerConfiguration'] = [
        \TYPO3\CMS\Core\Log\LogLevel::DEBUG => [
            \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
                'logFile' => 'typo3temp/logs/semantic_suggestion.log'
            ],
        ],
    ];
    
})();
