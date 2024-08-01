<?php
namespace TalanHdf\SemanticSuggestion\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TalanHdf\SemanticSuggestion\Service\PageAnalysisService;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

class SemanticBackendController extends ActionController
{
    protected $moduleTemplateFactory;
    protected $pageAnalysisService;
    protected $configurationManager;

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory, 
        PageAnalysisService $pageAnalysisService,
        ConfigurationManagerInterface $configurationManager
    ) {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->pageAnalysisService = $pageAnalysisService;
        $this->configurationManager = $configurationManager;
    }

    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        // Récupérer la configuration TypoScript complète
        $fullTypoScript = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
        );

        // Extraire la configuration spécifique à notre extension
        $extensionConfig = $fullTypoScript['plugin.']['tx_semanticsuggestion_suggestions.']['settings.'] ?? [];

        $parentPageId = (int)($extensionConfig['parentPageId'] ?? 0);
        $depth = (int)($extensionConfig['recursive'] ?? 1);
        $proximityThreshold = (float)($extensionConfig['proximityThreshold'] ?? 0.5);
        $maxSuggestions = (int)($extensionConfig['maxSuggestions'] ?? 5);
        $analyzedFields = $extensionConfig['analyzedFields.'] ?? [];

        $analysisResults = $this->pageAnalysisService->analyzePages($parentPageId, $depth);

        $moduleTemplate->assignMultiple([
            'parentPageId' => $parentPageId,
            'depth' => $depth,
            'proximityThreshold' => $proximityThreshold,
            'maxSuggestions' => $maxSuggestions,
            'analyzedFields' => $analyzedFields,
            'analysisResults' => $analysisResults,
        ]);

        $moduleTemplate->setContent($this->view->render());
        return $moduleTemplate->renderResponse();
    }
}