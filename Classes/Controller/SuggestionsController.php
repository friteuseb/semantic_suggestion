<?php
namespace TalanHdf\SemanticSuggestion\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TalanHdf\SemanticSuggestion\Service\PageAnalysisService;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Core\Cache\CacheManager;

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
        $cacheIdentifier = 'suggestions_' . $GLOBALS['TSFE']->id;
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $cache = $cacheManager->getCache('semantic_suggestion');
    
        if ($cache->has($cacheIdentifier)) {
            $this->view->assignMultiple($cache->get($cacheIdentifier));
        } else {
            $parentPageId = (int)$this->settings['parentPageId'];
            $proximityThreshold = (float)($this->settings['proximityThreshold'] ?? 0.3);
            $maxSuggestions = (int)($this->settings['maxSuggestions'] ?? 5);
            $depth = (int)($this->settings['recursive'] ?? 0);
            $currentPageId = $GLOBALS['TSFE']->id;
            $excludePages = GeneralUtility::intExplode(',', $this->settings['excludePages'] ?? '', true);
    
            $analysisResults = $this->pageAnalysisService->analyzePages($parentPageId, $depth);
            $suggestions = $this->findSimilarPages($analysisResults, $currentPageId, $proximityThreshold, $maxSuggestions, $excludePages);
    
            $viewData = [
                'currentPageTitle' => $analysisResults[$currentPageId]['title']['content'] ?? 'Current Page',
                'suggestions' => $suggestions,
                'analysisResults' => $analysisResults,
                'proximityThreshold' => $proximityThreshold,
                'maxSuggestions' => $maxSuggestions,
            ];
    
            $this->view->assignMultiple($viewData);
            $cache->set($cacheIdentifier, $viewData, ['tx_semanticsuggestion']);
        }
    
        return $this->htmlResponse();
    }

    protected function prepareExcerpt(array $pageData, int $excerptLength): string
    {
        $sources = GeneralUtility::trimExplode(',', $this->settings['excerptSources'] ?? 'bodytext,description,abstract', true);
        
        foreach ($sources as $source) {
            $content = $source === 'bodytext' ? ($pageData['tt_content'] ?? '') : ($pageData[$source] ?? '');
            
            if (!empty($content)) {
                $content = strip_tags($content);
                $content = preg_replace('/\s+/', ' ', $content);
                $content = trim($content);
                
                return mb_substr($content, 0, $excerptLength) . (mb_strlen($content) > $excerptLength ? '...' : '');
            }
        }
        
        return '';
    }

    protected function findSimilarPages(array $analysisResults, int $currentPageId, float $threshold, int $maxSuggestions, array $excludePages): array    {
        $suggestions = [];
        $pageRepository = GeneralUtility::makeInstance(PageRepository::class);
    
        if (isset($analysisResults[$currentPageId]['similarities'])) {
            $similarities = $analysisResults[$currentPageId]['similarities'];
            arsort($similarities);
            foreach ($similarities as $pageId => $similarity) {
                if (count($suggestions) >= $maxSuggestions) break;
                if ($similarity['score'] < $threshold || in_array($pageId, $excludePages)) continue;
                            
                $pageData = $pageRepository->getPage($pageId);
                $pageData['tt_content'] = $this->getPageContents($pageId);
                $excerpt = $this->prepareExcerpt($pageData, (int)($this->settings['excerptLength'] ?? 150));
                
                $suggestions[$pageId] = [
                    'similarity' => $similarity['score'],
                    'commonKeywords' => implode(', ', $similarity['commonKeywords']),
                    'relevance' => $similarity['relevance'],
                    'aboveThreshold' => true,
                    'data' => $pageData,
                    'excerpt' => $excerpt
                ];
                $suggestions[$pageId]['data']['media'] = $this->getPageMedia($pageId);
            }
        }
        return $suggestions;
    }

    protected function getPageMedia(int $pageId)
    {
        $fileObjects = $this->fileRepository->findByRelation('pages', 'media', $pageId);
        return !empty($fileObjects) ? $fileObjects[0] : null;
    }

    protected function getPageContents(int $pageId): string
    {
        $contentObject = GeneralUtility::makeInstance(ContentObjectRenderer::class);
        $contentObject->start([], 'pages');

        $content = '';
        $commonColPos = [0, 1, 2, 3, 8, 9];

        foreach ($commonColPos as $colPos) {
            $conf = [
                'table' => 'tt_content',
                'select.' => [
                    'orderBy' => 'sorting',
                    'where' => 'colPos = ' . $colPos,
                    'pidInList' => $pageId
                ]
            ];

            $colPosContent = $contentObject->cObjGetSingle('CONTENT', $conf);
            if (!empty(trim($colPosContent))) {
                $content .= ' ' . $colPosContent;
            }
        }

        return $content;
    }
}