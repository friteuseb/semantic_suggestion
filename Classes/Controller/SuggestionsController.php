<?php
namespace Talan\SemanticSuggestion\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Talan\SemanticSuggestion\Service\PageAnalysisService;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;

class SuggestionsController extends ActionController
{
    protected $pageAnalysisService;
    protected $fileRepository;

    public function __construct(PageAnalysisService $pageAnalysisService, FileRepository $fileRepository)
    {
        $this->pageAnalysisService = $pageAnalysisService;
        $this->fileRepository = $fileRepository;
    }

    public function listAction(): ResponseInterface
    {
        $parentPageId = (int)$this->settings['parentPageId'];
        $proximityThreshold = (float)($this->settings['proximityThreshold'] ?? 0.3);
        $maxSuggestions = (int)($this->settings['maxSuggestions'] ?? 5);
        $excerptLength = (int)($this->settings['excerptLength'] ?? 100);
        $depth = (int)($this->settings['recursive'] ?? 0); // Ajoutez cette ligne
        $currentPageId = $GLOBALS['TSFE']->id;
    
        $analysisResults = $this->pageAnalysisService->analyzePages($parentPageId, $depth); // Modifiez cette ligne
    
        $suggestions = $this->findSimilarPages($analysisResults, $currentPageId, $proximityThreshold, $maxSuggestions);
    
        $this->view->assignMultiple([
            'currentPageTitle' => $analysisResults[$currentPageId]['title'] ?? 'Current Page',
            'suggestions' => $suggestions,
            'analysisResults' => $analysisResults,
            'proximityThreshold' => $proximityThreshold,
            'maxSuggestions' => $maxSuggestions,
        ]);
    
        return $this->htmlResponse();
    }
    
    protected function findSimilarPages(array $analysisResults, int $currentPageId, float $threshold, int $maxSuggestions): array
    {
        $suggestions = [];
        $pageRepository = GeneralUtility::makeInstance(PageRepository::class);

        if (isset($analysisResults[$currentPageId]['similarities'])) {
            $similarities = $analysisResults[$currentPageId]['similarities'];
            arsort($similarities);
            $count = 0;
            foreach ($similarities as $pageId => $similarity) {
                if ($count >= $maxSuggestions) break;
                $pageData = $pageRepository->getPage($pageId);
                $suggestions[$pageId] = [
                    'similarity' => $similarity['score'],
                    'commonKeywords' => implode(', ', $similarity['commonKeywords']),
                    'relevance' => $similarity['relevance'],
                    'aboveThreshold' => $similarity['score'] >= $threshold,
                    'data' => $pageData
                ];
                $suggestions[$pageId]['data']['media'] = $this->getPageMedia($pageId);

                $count++;
            }
        }
        return $suggestions;
    }

    protected function getPageLink(int $pageId): string
    {
        return $this->uriBuilder->reset()->setTargetPageUid($pageId)->buildFrontendUri();
    }

    protected function getPageMedia(int $pageId)
    {
        $fileObjects = $this->fileRepository->findByRelation('pages', 'media', $pageId);
        return !empty($fileObjects) ? $fileObjects[0] : null;
    }
}