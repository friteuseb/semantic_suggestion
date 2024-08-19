<?php
defined('TYPO3') or die();

(static function() {
    // Configure the main Extbase plugin
    \TYPO3\CMS\Extbase\Utility\ExtensionUtility::configurePlugin(
        'SemanticSuggestion',
        'Suggestions',
        [
            \TalanHdf\SemanticSuggestion\Controller\SuggestionsController::class => 'list' 
        ]
    );

    // Configure the NLP configuration plugin
    \TYPO3\CMS\Extbase\Utility\ExtensionUtility::configurePlugin(
        'SemanticSuggestion',
        'NlpConfig',
        [
            \TalanHdf\SemanticSuggestion\Controller\ConfigurationController::class => 'index,save'
        ],
        [
            \TalanHdf\SemanticSuggestion\Controller\ConfigurationController::class => 'index,save'
        ]
    );

    // Register hooks
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass'][] = 
        \TalanHdf\SemanticSuggestion\Hooks\DataHandlerHook::class;
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'][] = 
        \TalanHdf\SemanticSuggestion\Hooks\DataHandlerHook::class;

    // Register NLP analysis hooks
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['semantic_suggestion']['nlpAnalysis'][] = 
        \TalanHdf\SemanticSuggestion\Hooks\PageAnalysisHook::class . '->analyze';
    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['semantic_suggestion']['nlpStatistics'][] = 
        \TalanHdf\SemanticSuggestion\Hooks\NlpAnalysisHook::class;

    // Add TypoScript
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptSetup(
        '@import "EXT:semantic_suggestion/Configuration/TypoScript/setup.typoscript"'
    );
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptConstants(
        '@import "EXT:semantic_suggestion/Configuration/TypoScript/constants.typoscript"'
    );

    // Configure cache
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

    $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][\TalanHdf\SemanticSuggestion\Task\NlpAnalysisTask::class] = [
        'extension' => 'semantic_suggestion',
        'title' => 'LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang.xlf:nlpAnalysisTask.name',
        'description' => 'LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang.xlf:nlpAnalysisTask.description',
        'additionalFields' => \TalanHdf\SemanticSuggestion\Task\NlpAnalysisTaskAdditionalFieldProvider::class
    ];
   

})();
