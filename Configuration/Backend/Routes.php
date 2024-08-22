<?php

use TalanHdf\SemanticSuggestion\Controller\SemanticBackendController;

return [
    'semantic_suggestion_proximity' => [
        'path' => '/semantic-suggestion/proximity',
        'target' => SemanticBackendController::class . '::indexAction'
    ],
];