<?php
defined('TYPO3') or die();

(static function() {
    \TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerModule(
        'SemanticSuggestion',
        'web',
        'semantic_suggestion',
        '',
        [
            \TalanHdf\SemanticSuggestion\Controller\SemanticBackendController::class => 'index,list',
        ],
        [
            'access' => 'user,group',
            'icon'   => 'EXT:semantic_suggestion/Resources/Public/Icons/module-semantic-suggestion.svg',
            'labels' => 'LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_mod.xlf',
        ]
    );
})();