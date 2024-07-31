<?php
defined('TYPO3') or die();

(static function() {
    \TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerModule(
        'semantic_suggestion',
        'web',
        'semanticproximity',
        '',
        [
            \TalanHdf\SemanticSuggestion\Controller\BackendController::class => 'list',
        ],
        [
            'access' => 'user,group',
            'icon'   => 'EXT:semantic_suggestion/Resources/Public/Icons/user_mod_semanticproximity.svg',
            'labels' => 'LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_semanticproximity.xlf',
        ]
    );
})();
// Register backend module
\TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerModule(
    'SemanticSuggestion',
    'web',
    'semantic_suggestion',
    '',
    [
        \TalanHdf\SemanticSuggestion\Controller\SemanticBackendController::class => 'index',
    ],
    [
        'access' => 'user,group',
        'icon'   => 'EXT:semantic_suggestion/Resources/Public/Icons/module-semantic-suggestion.svg',
        'labels' => 'LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_mod.xlf',
    ]
);
