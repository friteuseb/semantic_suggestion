<?php
namespace TalanHdf\SemanticSuggestion\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TalanHdf\SemanticSuggestion\Service\PageAnalysisService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use Psr\Container\ContainerInterface;

class SuggestionsViewHelper extends AbstractViewHelper
{
    protected ?PageAnalysisService $pageAnalysisService = null;

    public function initializeArguments()
    {
        $this->registerArgument('pageUid', 'int', 'The UID of the current page', true);
        $this->registerArgument('parentPageId', 'int', 'The parent page ID', false, 0);
        $this->registerArgument('proximityThreshold', 'float', 'The proximity threshold', false, 0.3);
        $this->registerArgument('maxSuggestions', 'int', 'Maximum number of suggestions', false, 5);
        $this->registerArgument('depth', 'int', 'Depth of analysis', false, 0);
    }

    public function render()
    {
        if ($this->pageAnalysisService === null) {
            // FIXME: GeneralUtility::getContainer() is marked internal and should not be used
            $container = GeneralUtility::getContainer();
            $this->pageAnalysisService = $container->get(PageAnalysisService::class);
        }

        $currentPageId = $this->arguments['pageUid'];
        $parentPageId = $this->arguments['parentPageId'];
        $proximityThreshold = $this->arguments['proximityThreshold'];
        $maxSuggestions = $this->arguments['maxSuggestions'];
        $depth = $this->arguments['depth'];

        $analysisData = $this->pageAnalysisService->analyzePages($parentPageId, $depth);
        $analysisResults = $analysisData['results'] ?? [];

        $suggestions = $this->findSimilarPages($analysisResults, $currentPageId, $proximityThreshold, $maxSuggestions);

        $this->templateVariableContainer->add('suggestions', $suggestions);
        $content = $this->renderChildren();
        $this->templateVariableContainer->remove('suggestions');

        return $content;
    }

    protected function findSimilarPages(array $analysisResults, int $currentPageId, float $threshold, int $maxSuggestions): array
    {
        $suggestions = [];
        $pageRepository = GeneralUtility::makeInstance(PageRepository::class);

        if (isset($analysisResults[$currentPageId]['similarities'])) {
            $similarities = $analysisResults[$currentPageId]['similarities'];
            arsort($similarities);
            foreach ($similarities as $pageId => $similarity) {
                if (count($suggestions) >= $maxSuggestions) {
                    break;
                }
                if ($similarity['score'] < $threshold) {
                    continue;
                }
                
                $pageData = $pageRepository->getPage($pageId);
                $suggestions[$pageId] = [
                    'similarity' => $similarity['score'],
                    'commonKeywords' => implode(', ', $similarity['commonKeywords']),
                    'relevance' => $similarity['relevance'],
                    'data' => $pageData,
                ];
            }
        }
        return $suggestions;
    }
}
