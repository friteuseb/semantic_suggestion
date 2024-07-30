<?php
defined('TYPO3') or die();

(static function() {
    \TYPO3\CMS\Extbase\Utility\ExtensionUtility::configurePlugin(
        'SemanticSuggestion',
        'Suggestions',
        [
            \Talan\SemanticSuggestion\Controller\SuggestionsController::class => 'list'
        ],
        // non-cacheable actions
        [
            \Talan\SemanticSuggestion\Controller\SuggestionsController::class => 'list'
        ]
    );

    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptSetup(
        '@import "EXT:semantic_suggestion/Configuration/TsConfig/Page/Dashboard.tsconfig"'
    );

    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptSetup(
        '<INCLUDE_TYPOSCRIPT: source="FILE:EXT:semantic_suggestion/Configuration/TypoScript/setup.typoscript">'
    );

    // Register logger for the extension
    $GLOBALS['TYPO3_CONF_VARS']['LOG']['Talan']['SemanticSuggestion']['writerConfiguration'] = [
        \TYPO3\CMS\Core\Log\LogLevel::DEBUG => [
            // configuration for the writer
            \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
                // configuration for the writer
                'logFile' => 'typo3temp/logs/semantic_suggestion.log'
            ]
        ]
    ];
})();
