#!/bin/bash

# Création du module backend pour Semantic Suggestion

# Assurez-vous d'exécuter ce script à la racine de votre extension

# Création des dossiers nécessaires
mkdir -p Classes/Controller
mkdir -p Configuration/Backend
mkdir -p Resources/Private/Templates/Backend
mkdir -p Resources/Private/Language
mkdir -p Resources/Public/Icons

# Création du contrôleur backend
cat > Classes/Controller/SemanticBackendController.php << EOL
<?php
namespace TalanHdf\SemanticSuggestion\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class SemanticBackendController extends ActionController
{
    protected ModuleTemplateFactory \$moduleTemplateFactory;

    public function __construct(ModuleTemplateFactory \$moduleTemplateFactory)
    {
        \$this->moduleTemplateFactory = \$moduleTemplateFactory;
    }

    public function indexAction(): ResponseInterface
    {
        \$moduleTemplate = \$this->moduleTemplateFactory->create(\$this->request);
        \$moduleTemplate->setContent(\$this->view->render());
        return \$this->htmlResponse(\$moduleTemplate->renderContent());
    }
}
EOL

# Création du fichier de configuration du module
cat > Configuration/Backend/Modules.php << EOL
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
EOL

# Création du template Fluid pour le module backend
cat > Resources/Private/Templates/Backend/Index.html << EOL
<html xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers"
      data-namespace-typo3-fluid="true">

<f:layout name="Default" />

<f:section name="content">
    <h1>Semantic Suggestion Backend Module</h1>
    <p>Welcome to the Semantic Suggestion backend module. Here you can manage and view semantic analysis results.</p>
</f:section>

</html>
EOL

# Création du fichier de langue pour le module
cat > Resources/Private/Language/locallang_mod.xlf << EOL
<?xml version="1.0" encoding="UTF-8"?>
<xliff version="1.2" xmlns="urn:oasis:names:tc:xliff:document:1.2">
    <file source-language="en" datatype="plaintext" original="messages" date="2024-08-01T12:00:00Z" product-name="semantic_suggestion">
        <header/>
        <body>
            <trans-unit id="mlang_tabs_tab">
                <source>Semantic Suggestion</source>
            </trans-unit>
            <trans-unit id="mlang_labels_tabdescr">
                <source>Manage semantic analysis and view results</source>
            </trans-unit>
            <trans-unit id="mlang_labels_tablabel">
                <source>Semantic Analysis</source>
            </trans-unit>
        </body>
    </file>
</xliff>
EOL

# Création de l'icône du module (vous devrez remplacer ceci par une vraie icône SVG)
cat > Resources/Public/Icons/module-semantic-suggestion.svg << EOL
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16">
    <path fill="#666" d="M1 1h14v14H1z"/>
    <path fill="#fff" d="M2 2h12v12H2z"/>
    <text x="4" y="12" font-family="Arial" font-size="12" fill="#666">SS</text>
</svg>
EOL

# Mise à jour du fichier ext_tables.php pour enregistrer le module
if grep -q "SemanticBackendController" ext_tables.php; then
    echo "Module already registered in ext_tables.php"
else
    echo "
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
);" >> ext_tables.php
fi

echo "Module backend créé avec succès !"
echo "N'oubliez pas de vider les caches TYPO3 et de recharger le backend pour voir le nouveau module."
