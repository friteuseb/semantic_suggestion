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
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Pagination\ArrayPaginator;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use Psr\Log\LoggerInterface;


class SuggestionsController extends ActionController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const DEFAULT_ITEMS_PER_PAGE = 10;

    protected PageAnalysisService $pageAnalysisService;
    protected FileRepository $fileRepository;
    protected ?PageRepository $pageRepository = null;
    protected array $debugLogs = [];

    public function __construct(
        PageAnalysisService $pageAnalysisService, 
        FileRepository $fileRepository
    ) {
        $this->pageAnalysisService = $pageAnalysisService;
        $this->fileRepository = $fileRepository;
        $this->setLogger(GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__));
    }

    /**
     * @param PageRepository $pageRepository
     */
    public function injectPageRepository(PageRepository $pageRepository)
    {
        $this->pageRepository = $pageRepository;
    }

    private function logDebug(string $message, array $context = []): void
{
    $debugMode = $this->pageAnalysisService->getSettings()['debugMode'] ?? false;
    if ($debugMode && $this->logger instanceof LoggerInterface) {
        $this->logger->debug($message, $context);
        $this->debugLogs[] = ['message' => $message, 'context' => $context];
    }
}


    public function listAction(int $currentPage = 1, int $itemsPerPage = self::DEFAULT_ITEMS_PER_PAGE): ResponseInterface
    {
        $this->logDebug('listAction called', ['currentPage' => $currentPage, 'itemsPerPage' => $itemsPerPage]);
    
        $currentPageId = $GLOBALS['TSFE']->id;
        $currentLanguageUid = $this->getCurrentLanguageUid();
    
        $cacheIdentifier = 'suggestions_' . $currentPageId . '_' . $currentLanguageUid . '_' . $currentPage . '_' . $itemsPerPage;
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $cache = $cacheManager->getCache('semantic_suggestion');
    
        try {
            if ($cache->has($cacheIdentifier)) {
                $this->logDebug('Cache hit for suggestions', ['pageId' => $currentPageId, 'currentPage' => $currentPage, 'languageUid' => $currentLanguageUid]);
                $viewData = $cache->get($cacheIdentifier);
            } else {
                $this->logDebug('Cache miss for suggestions', ['pageId' => $currentPageId, 'currentPage' => $currentPage, 'languageUid' => $currentLanguageUid]);
                $viewData = $this->generateSuggestions($currentPageId, $currentPage, $itemsPerPage, $currentLanguageUid);
                
                if (!empty($viewData['suggestions'])) {
                    $cache->set($cacheIdentifier, $viewData, ['tx_semanticsuggestion'], 3600);
                } else {
                    $this->logDebug('No suggestions generated', ['pageId' => $currentPageId, 'currentPage' => $currentPage, 'languageUid' => $currentLanguageUid]);
                }
            }
    
            $viewData['debugLogs'] = $this->debugLogs;
            $this->view->assignMultiple($viewData);
    
        } catch (\Exception $e) {
            $this->logger->error('Error in listAction', ['exception' => $e->getMessage()]);
            $this->view->assign('error', 'An error occurred while generating suggestions.');
        }
    
        return $this->htmlResponse();
    }
    
    protected function generateSuggestions(int $currentPageId, int $currentPage, int $itemsPerPage): array
    {
        $parentPageId = isset($this->settings['parentPageId']) ? (int)$this->settings['parentPageId'] : 0; 
        $depth = isset($this->settings['recursive']) ? (int)$this->settings['recursive'] : 0; 
        $proximityThreshold = isset($this->settings['proximityThreshold']) ? (float)$this->settings['proximityThreshold'] : 0.3; 
        $excludePages = GeneralUtility::intExplode(',', $this->settings['excludePages'] ?? '', true);
        $maxSuggestions = isset($this->settings['maxSuggestions']) ? (int)$this->settings['maxSuggestions'] : 3; // Default to 3 if not set
        $currentLanguageUid = $this->getCurrentLanguageUid();

        $pages = $this->getPages($parentPageId, $depth);
        $analysisData = $this->pageAnalysisService->analyzePages($pages, $currentLanguageUid);
        $analysisResults = $analysisData['results'] ?? [];
    
        $currentLanguageUid = $this->getCurrentLanguageUid();
        $suggestions = $this->findSimilarPages($analysisResults, $currentPageId, $proximityThreshold, $excludePages, $currentLanguageUid, $maxSuggestions);

        // Pagination des suggestions
        $paginator = new ArrayPaginator($suggestions, $currentPage, $itemsPerPage);

        $paginatedSuggestions = [];
        foreach ($paginator->getPaginatedItems() as $pageId => $suggestion) {
            $paginatedSuggestions[$pageId] = $suggestion;
        }

        // Calculate pagination information
        $totalItems = count($suggestions);
        $numberOfPages = $paginator->getNumberOfPages();
        $currentPage = $paginator->getCurrentPageNumber();
        $hasNextPage = $currentPage < $numberOfPages;
        $hasPreviousPage = $currentPage > 1;

        $this->logDebug('Suggestions generated', [
            'count' => count($paginatedSuggestions),
            'parentPageId' => $parentPageId,
            'currentPageId' => $currentPageId,
            'proximityThreshold' => $proximityThreshold,
            'currentPage' => $currentPage,
            'itemsPerPage' => $itemsPerPage,
            'totalItems' => $totalItems,
            'numberOfPages' => $numberOfPages,
            'maxSuggestions' => $maxSuggestions
        ]);

        return [
            'currentPageTitle' => $analysisResults[$currentPageId]['title']['content'] ?? 'Current Page',
            'suggestions' => $paginatedSuggestions,
            'pagination' => [
                'currentPage' => $currentPage,
                'itemsPerPage' => $itemsPerPage,
                'numberOfPages' => $numberOfPages,
                'hasNextPage' => $hasNextPage,
                'hasPreviousPage' => $hasPreviousPage,
                'nextPage' => $hasNextPage ? $currentPage + 1 : null,
                'previousPage' => $hasPreviousPage ? $currentPage - 1 : null,
                'firstPageNumber' => 1,
                'lastPageNumber' => $numberOfPages,
                'startRecord' => ($currentPage - 1) * $itemsPerPage + 1,
                'endRecord' => min($currentPage * $itemsPerPage, $totalItems),
                'totalItems' => $totalItems,
            ],
            'analysisResults' => $analysisResults,
            'proximityThreshold' => $proximityThreshold,
            'maxSuggestions' => $maxSuggestions,
            'debugLogs' => $this->debugLogs,
        ];
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

    protected function findSimilarPages(array $analysisResults, int $currentPageId, float $threshold, array $excludePages, int $currentLanguageUid, int $maxSuggestions): array
    {
        $this->logDebug('Finding similar pages', [
            'currentPageId' => $currentPageId,
            'threshold' => $threshold,
            'currentLanguageUid' => $currentLanguageUid,
            'maxSuggestions' => $maxSuggestions
        ]);
    
        $suggestions = [];
        $pageRepository = GeneralUtility::makeInstance(PageRepository::class);
    
        if (isset($analysisResults[$currentPageId]['similarities'])) {
            $similarities = $analysisResults[$currentPageId]['similarities'];
            arsort($similarities);
            foreach ($similarities as $pageId => $similarity) {
                $pageLangUid = $analysisResults[$pageId]['sys_language_uid'] ?? 0;
                $sameLanguage = ($pageLangUid == $currentLanguageUid) || 
                                ($pageLangUid == 0 && $currentLanguageUid == 0);
    
                if ($similarity['score'] < $threshold || in_array($pageId, $excludePages) || !$sameLanguage) {
                    $this->logDebug('Page excluded', [
                        'pageId' => $pageId, 
                        'reason' => $similarity['score'] < $threshold ? 'below threshold' : 
                            (!$sameLanguage ? 'different language' : 'in exclude list'),
                        'pageLangUid' => $pageLangUid,
                        'currentLanguageUid' => $currentLanguageUid
                    ]);
                    continue;
                }
                
                $pageData = $pageRepository->getPage($pageId);
                $pageData['tt_content'] = $this->getPageContents($pageId);
                $excerpt = $this->prepareExcerpt($pageData, (int)($this->settings['excerptLength'] ?? 150));
    
                $recencyScore = $this->calculateRecencyScore($pageData['tstamp']);
                
                $suggestions[$pageId] = [
                    'similarity' => $similarity['score'],
                    'commonKeywords' => implode(', ', $similarity['commonKeywords']),
                    'relevance' => $similarity['relevance'],
                    'aboveThreshold' => true,
                    'data' => $pageData,
                    'excerpt' => $excerpt,
                    'recency' => $recencyScore
                ];
                $suggestions[$pageId]['data']['media'] = $this->getPageMedia($pageId);
                
                $this->logDebug('Added suggestion', [
                    'pageId' => $pageId, 
                    'similarity' => $similarity['score'],
                    'pageLangUid' => $pageLangUid
                ]);

                if (count($suggestions) >= $maxSuggestions) {
                    break;
                }
            }
        } else {
            $this->logDebug('No similarities found for current page', ['currentPageId' => $currentPageId]);
        }
    
        $this->logDebug('Found similar pages', ['count' => count($suggestions)]);
        return $suggestions;
    }


    protected function calculateRecencyScore($timestamp)
    {
        $now = time();
        $age = $now - $timestamp;
        $maxAge = 30 * 24 * 60 * 60; // 30 jours en secondes

        return max(0, 1 - ($age / $maxAge));
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

    protected function getPages(int $parentPageId, int $depth): array
    {
        if ($this->pageRepository === null) {
            $this->pageRepository = GeneralUtility::makeInstance(PageRepository::class);
        }
    
        $pages = [];
        $languageAspect = GeneralUtility::makeInstance(Context::class)->getAspect('language');
        $languageId = $languageAspect->getId();
    
        $pageRecords = $this->pageRepository->getMenu(
            $parentPageId,
            '*',
            'sorting',
            '',
            false,
            '',
            $languageId
        );
    
        foreach ($pageRecords as $pageRecord) {
            $pages[$pageRecord['uid']] = $pageRecord;
            if ($depth > 1) {
                $subpages = $this->getPages($pageRecord['uid'], $depth - 1);
                $pages = array_merge($pages, $subpages);
            }
        }
        if ($this->logger instanceof LoggerInterface) {
            $this->logDebug('Retrieved pages', ['pages' => $pages]);
        }
        return $pages;
    }

    protected function getCurrentLanguageUid(): int
    {
        return GeneralUtility::makeInstance(Context::class)->getAspect('language')->getId();
    }

}