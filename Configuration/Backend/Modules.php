<?php
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TalanHdf\SemanticSuggestion\Controller\LegacySemanticBackendController;
use TalanHdf\SemanticSuggestion\Controller\SemanticBackendController;
use TalanHdf\SemanticSuggestion\Controller\DebugController;

$versionInformation = GeneralUtility::makeInstance(Typo3Version::class);
$isV13OrGreater = $versionInformation->getMajorVersion() >= 13;

// Définir les actions du contrôleur en fonction de la version
$controllerActions = [];
if ($isV13OrGreater) {
    // Pour v13 et plus
    $controllerActions[SemanticBackendController::class] = ['index']; // Utilisez le contrôleur v13
} else {
    // Pour v12 et moins
    $controllerActions[LegacySemanticBackendController::class] = ['index']; // Utilisez le contrôleur v12
}

// Debug controller (same for all versions)
$debugControllerActions = [
    DebugController::class => ['index', 'execute']
];

// Configuration de base du module
$moduleConfig = [
    'web_SemanticSuggestion' => [
        'parent' => 'web',
        'position' => ['after' => 'web_info'], // Ou une autre position si vous préférez
        'access' => 'user,group',
        'workspaces' => 'live',
        'path' => '/module/web/SemanticSuggestion', // Chemin utilisé principalement par v13+ pour le routing
        'labels' => 'LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_mod.xlf',
        'icon' => 'EXT:semantic_suggestion/Resources/Public/Icons/module-semantic-suggestion.svg',
        'iconIdentifier' => 'module-semantic-suggestion', // Gardez ceci pour la cohérence v13+
        'extensionName' => 'SemanticSuggestion', // Peut-être essayer 'semantic_suggestion' si ça pose problème
        'controllerActions' => $controllerActions, // Tableau défini conditionnellement ci-dessus
        // Gardez votre configuration de vue si elle est commune ou ajustez si nécessaire
        'view' => [
            'templateRootPaths' => [
                100 => 'EXT:semantic_suggestion/Resources/Private/Templates/',
            ],
            'partialRootPaths' => [
                100 => 'EXT:semantic_suggestion/Resources/Private/Partials/',
            ],
            'layoutRootPaths' => [
                100 => 'EXT:semantic_suggestion/Resources/Private/Layouts/',
            ],
        ],
    ],
];

// 🎯 AJOUT : Configuration spécifique par version pour masquer le pagetree
if ($isV13OrGreater) {
    // TYPO3 v13+ : Nouvelle méthode propre
    $moduleConfig['web_SemanticSuggestion']['inheritNavigationComponentFromMainModule'] = false;
} else {
    // TYPO3 v12 : Ancienne méthode
    $moduleConfig['web_SemanticSuggestion']['navigationComponent'] = '';
    $moduleConfig['web_SemanticSuggestion']['navigationComponentId'] = '';
}

// ════════════════════════════════════════════════════════════════════════════════
// DEBUG MODULE - Test & Monitoring
// ════════════════════════════════════════════════════════════════════════════════
$moduleConfig['web_SemanticSuggestionDebug'] = [
    'parent' => 'web',
    'position' => ['after' => 'web_SemanticSuggestion'],
    'access' => 'admin',
    'workspaces' => 'live',
    'path' => '/module/web/SemanticSuggestionDebug',
    'labels' => 'LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang_mod_debug.xlf',
    'iconIdentifier' => 'module-semantic-suggestion',
    'extensionName' => 'SemanticSuggestion',
    'controllerActions' => $debugControllerActions,
    'view' => [
        'templateRootPaths' => [
            100 => 'EXT:semantic_suggestion/Resources/Private/Templates/',
        ],
        'partialRootPaths' => [
            100 => 'EXT:semantic_suggestion/Resources/Private/Partials/',
        ],
        'layoutRootPaths' => [
            100 => 'EXT:semantic_suggestion/Resources/Private/Layouts/',
        ],
    ],
];

if ($isV13OrGreater) {
    $moduleConfig['web_SemanticSuggestionDebug']['inheritNavigationComponentFromMainModule'] = false;
} else {
    $moduleConfig['web_SemanticSuggestionDebug']['navigationComponent'] = '';
    $moduleConfig['web_SemanticSuggestionDebug']['navigationComponentId'] = '';
}

return $moduleConfig;