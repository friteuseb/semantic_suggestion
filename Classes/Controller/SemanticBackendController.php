<?php
namespace TalanHdf\SemanticSuggestion\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TalanHdf\SemanticSuggestion\Service\PageAnalysisService;

class SemanticBackendController extends ActionController
{
    protected $moduleTemplateFactory;
    protected $pageAnalysisService;

    public function __construct(ModuleTemplateFactory $moduleTemplateFactory, PageAnalysisService $pageAnalysisService)
    {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->pageAnalysisService = $pageAnalysisService;
    }

    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $parentPageId = (int)$this->settings['parentPageId'];
        $depth = (int)$this->settings['recursive'];
        $proximityThreshold = (float)$this->settings['proximityThreshold'];
        $maxSuggestions = (int)$this->settings['maxSuggestions'];

        $analysisResults = $this->pageAnalysisService->analyzePages($parentPageId, $depth);

        $moduleTemplate->assignMultiple([
            'analysisResults' => $analysisResults,
            'proximityThreshold' => $proximityThreshold,
            'maxSuggestions' => $maxSuggestions,
            'analyzedFields' => $this->settings['analyzedFields'],
        ]);

        $moduleTemplate->setContent($this->view->render());
        return $moduleTemplate->renderResponse();
    }
}