<?php
return [
    'web_SemanticSuggestion' => [
        'parent' => 'web',
        'position' => ['after' => 'web_info'],
        'access' => 'user,group',
        'workspaces' => 'live',
        'path' => '/module/web/SemanticSuggestion',
        'labels' => 'LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_mod.xlf',
        'icon' => 'EXT:semantic_suggestion/Resources/Public/Icons/module-semantic-suggestion.svg',
        'iconIdentifier' => 'module-semantic-suggestion',
        'extensionName' => 'SemanticSuggestion',
        'controllerActions' => [
            \TalanHdf\SemanticSuggestion\Controller\SemanticBackendController::class => [
                'index', 'list'
            ],
        ],
    ],
];
