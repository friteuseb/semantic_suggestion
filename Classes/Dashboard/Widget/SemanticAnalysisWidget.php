<?php
namespace Talan\SemanticSuggestion\Dashboard\Widget;

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;
use TYPO3\CMS\Fluid\View\StandaloneView;
use Talan\SemanticSuggestion\Service\PageAnalysisService;

class SemanticAnalysisWidget implements WidgetInterface
{
    protected PageAnalysisService $pageAnalysisService;

    public function __construct(
        protected readonly WidgetConfigurationInterface $configuration,
        protected readonly StandaloneView $view,
        protected readonly array $options = []
    ) {
        $this->pageAnalysisService = GeneralUtility::makeInstance(PageAnalysisService::class);
    }

    public function renderWidget(): string
    {
        $this->view->setTemplatePathAndFilename(
            'EXT:semantic_suggestion/Resources/Private/Templates/Widget/SemanticAnalysisWidget.html'
        );
        
        $parentPageId = $this->options['parentPageId'] ?? 4;
        
        $analysisResults = $this->pageAnalysisService->analyzePages($parentPageId);
    

        // Format the analysis results
        foreach ($analysisResults as &$pageData) {
            foreach ($pageData['similarities'] as &$similarity) {
                $similarity['commonKeywords'] = implode(', ', $similarity['commonKeywords']);
            }
        }

        $this->view->assignMultiple([
            'configuration' => $this->configuration,
            'analysisResults' => $analysisResults,
            'widgetTitle' => $this->getLanguageService()->sL('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang.xlf:widget.title'),
            'widgetContentTitle' => $this->getLanguageService()->sL('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang.xlf:widget.contentTitle'),
            'pageTitle' => $this->getLanguageService()->sL('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang.xlf:widget.pageTitle'),
            'similarityScore' => $this->getLanguageService()->sL('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang.xlf:widget.similarityScore'),
            'commonKeywords' => $this->getLanguageService()->sL('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang.xlf:widget.commonKeywords'),
            'relevance' => $this->getLanguageService()->sL('LLL:EXT:semantic_suggestion/Resources/Private/Language/locallang.xlf:widget.relevance'),
        ]);

        return $this->view->render();
    }



    public function renderWidgetContent(): string
    {
        return $this->renderWidget();
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    protected function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}