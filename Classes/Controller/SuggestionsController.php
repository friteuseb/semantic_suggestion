<?php
namespace TalanHdf\SemanticSuggestion\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TalanHdf\SemanticSuggestion\Service\ItemAnalysisService;
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
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use GeorgRinger\News\Domain\Repository\NewsRepository;


class SuggestionsController extends ActionController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const DEFAULT_ITEMS_PER_PAGE = 10;

    protected ItemAnalysisService $itemAnalysisService;
    protected FileRepository $fileRepository;
    protected ?PageRepository $pageRepository = null;
    protected ?NewsRepository $newsRepository = null;

    public function __construct(
        ItemAnalysisService $itemAnalysisService, 
        FileRepository $fileRepository,
        NewsRepository $newsRepository
    ) {
        $this->itemAnalysisService = $itemAnalysisService;
        $this->fileRepository = $fileRepository;
        $this->newsRepository = $newsRepository;
        $this->setLogger(GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__));

}


    protected function getNewsRecords(int $parentPageId, int $depth): array
    {
        $newsRecords = [];
        $newsItems = $this->newsRepository->findByPid($parentPageId, $depth);
        
        foreach ($newsItems as $newsItem) {
            $newsRecords[$newsItem->getUid()] = [
                'uid' => $newsItem->getUid(),
                'pid' => $newsItem->getPid(),
                'title' => $newsItem->getTitle(),
                'abstract' => $newsItem->getTeaser(),
                'bodytext' => $newsItem->getBodytext(),
                'datetime' => $newsItem->getDatetime()->getTimestamp(),
                'type' => 'news'
            ];
        }
        
        return $newsRecords;
    }
    /**
     * @param PageRepository $pageRepository
     */
    public function injectPageRepository(PageRepository $pageRepository)
    {
        $this->pageRepository = $pageRepository;
    }

    public function listAction(int $currentPage = 1, int $itemsPerPage = self::DEFAULT_ITEMS_PER_PAGE): ResponseInterface
    {
        $this->logger->info('listAction called', ['currentPage' => $currentPage, 'itemsPerPage' => $itemsPerPage]);

        $currentPageId = $GLOBALS['TSFE']->id;
        $cacheIdentifier = 'suggestions_' . $currentPageId . '_' . $currentPage . '_' . $itemsPerPage;
        $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        $cache = $cacheManager->getCache('semantic_suggestion');

        try {
            if ($cache->has($cacheIdentifier)) {
                $this->logger->debug('Cache hit for suggestions', ['pageId' => $currentPageId, 'currentPage' => $currentPage]);
                $viewData = $cache->get($cacheIdentifier);
            } else {
                $this->logger->debug('Cache miss for suggestions', ['pageId' => $currentPageId, 'currentPage' => $currentPage]);
                $viewData = $this->generateSuggestions($currentPageId, $currentPage, $itemsPerPage);
                
                if (!empty($viewData['suggestions'])) {
                    $cache->set($cacheIdentifier, $viewData, ['tx_semanticsuggestion'], 3600); // Cache for 1 hour
                } else {
                    $this->logger->warning('No suggestions generated', ['pageId' => $currentPageId, 'currentPage' => $currentPage]);
                }
            }

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
        $maxSuggestions = isset($this->settings['maxSuggestions']) ? (int)$this->settings['maxSuggestions'] : 3;
        $enableNewsAnalysis = isset($this->settings['enableNewsAnalysis']) ? (bool)$this->settings['enableNewsAnalysis'] : false;
    
        $pages = $this->getPages($parentPageId, $depth);
        
        if ($enableNewsAnalysis && ExtensionManagementUtility::isLoaded('news')) {
            $newsRecords = $this->getNewsRecords($parentPageId, $depth);
            $pages = array_merge($pages, $newsRecords);
        }
        
        $analysisData = $this->itemAnalysisService->analyzeItems($pages);
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

        $this->logger->debug('Suggestions generated', [
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
        ];
    }

    protected function prepareExcerpt($itemData, int $excerptLength, string $type = 'page'): string
    {
        if ($type === 'news') {
            $sources = ['bodytext', 'abstract'];
        } else {
            $sources = GeneralUtility::trimExplode(',', $this->settings['excerptSources'] ?? 'bodytext,description,abstract', true);
        }
        
        foreach ($sources as $source) {
            $content = $type === 'news' ? $itemData->{'get' . ucfirst($source)}() : ($itemData[$source] ?? '');
            
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
        $this->logger->info('Finding similar pages and news items', [
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
            foreach ($similarities as $itemId => $similarity) {
                $itemLangUid = $analysisResults[$itemId]['sys_language_uid'] ?? 0;
                $sameLanguage = ($itemLangUid == $currentLanguageUid) || 
                                ($itemLangUid == 0 && $currentLanguageUid == 0);
    
                if ($similarity['score'] < $threshold || in_array($itemId, $excludePages) || !$sameLanguage) {
                    $this->logger->debug('Item excluded', [
                        'itemId' => $itemId, 
                        'reason' => $similarity['score'] < $threshold ? 'below threshold' : 
                            (!$sameLanguage ? 'different language' : 'in exclude list'),
                        'itemLangUid' => $itemLangUid,
                        'currentLanguageUid' => $currentLanguageUid
                    ]);
                    continue;
                }
                
                $itemData = $analysisResults[$itemId]['type'] === 'news' 
                    ? $this->newsRepository->findByUid($itemId) 
                    : $pageRepository->getPage($itemId);
                
                if ($analysisResults[$itemId]['type'] !== 'news') {
                    $itemData['tt_content'] = $this->getPageContents($itemId);
                }
                
                $excerpt = $this->prepareExcerpt($itemData, (int)($this->settings['excerptLength'] ?? 150), $analysisResults[$itemId]['type']);
    
                $recencyScore = $this->calculateRecencyScore($itemData['tstamp'] ?? $itemData['datetime']);
                
                $suggestions[$itemId] = [
                    'similarity' => $similarity['score'],
                    'commonKeywords' => implode(', ', $similarity['commonKeywords']),
                    'relevance' => $similarity['relevance'],
                    'aboveThreshold' => true,
                    'data' => $itemData,
                    'excerpt' => $excerpt,
                    'recency' => $recencyScore,
                    'type' => $analysisResults[$itemId]['type']
                ];
                
                if ($analysisResults[$itemId]['type'] !== 'news') {
                    $suggestions[$itemId]['data']['media'] = $this->getPageMedia($itemId);
                }
                
                $this->logger->debug('Added suggestion', [
                    'itemId' => $itemId, 
                    'similarity' => $similarity['score'],
                    'itemLangUid' => $itemLangUid,
                    'type' => $analysisResults[$itemId]['type']
                ]);

                if (count($suggestions) >= $maxSuggestions) {
                    break;
                }
            }
        } else {
            $this->logger->warning('No similarities found for current item', ['currentPageId' => $currentPageId]);
        }
    
        $this->logger->info('Found similar pages and news items', ['count' => count($suggestions)]);
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
            $this->logger->debug('Retrieved pages', ['pages' => $pages]);
        }
        return $pages;
    }

    protected function getCurrentLanguageUid(): int
    {
        return GeneralUtility::makeInstance(Context::class)->getAspect('language')->getId();
    }
}