<?php
return [
    'semantic_suggestion' => [
        'parent' => 'web',
        'position' => ['after' => 'web_info'],
        'access' => 'user,group',
        'path' => '/module/semantic-suggestion',
        'labels' => 'LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_mod.xlf',
        'icon' => 'EXT:semantic_suggestion/Resources/Public/Icons/module-semantic-suggestion.svg',
        'controllerActions' => [
            \TalanHdf\SemanticSuggestion\Controller\SemanticBackendController::class => [
                'index',
            ],
        ],
    ],
];
