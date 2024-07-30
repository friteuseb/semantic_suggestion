<?php
defined('TYPO3') or die();

(static function() {
    \TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerModule(
        'semantic_suggestion',
        'web',
        'semanticproximity',
        '',
        [
            \Talan\SemanticSuggestion\Controller\BackendController::class => 'list',
        ],
        [
            'access' => 'user,group',
            'icon'   => 'EXT:semantic_suggestion/Resources/Public/Icons/user_mod_semanticproximity.svg',
            'labels' => 'LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_semanticproximity.xlf',
        ]
    );
})();