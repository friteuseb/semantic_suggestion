<?php
namespace TalanHdf\SemanticSuggestion\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TalanHdf\SemanticSuggestion\Service\PageAnalysisService;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Cache\CacheManager;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use TalanHdf\SemanticSuggestion\Service\NlpService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use TYPO3\CMS\Core\Database\Connection;

class SuggestionsController extends ActionController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected PageAnalysisService $pageAnalysisService;
    protected FileRepository $fileRepository;
    protected NlpService $nlpService;
    protected ?CacheManager $cacheManager = null;

    public function __construct(
        PageAnalysisService $pageAnalysisService, 
        FileRepository $fileRepository,
        LoggerInterface $logger,
        NlpService $nlpService
    ) {
        $this->pageAnalysisService = $pageAnalysisService;
        $this->fileRepository = $fileRepository;
        $this->nlpService = $nlpService;
        $this->setLogger($logger);
    }

    private function getCacheManager(): CacheManager
    {
        if ($this->cacheManager === null) {
            $this->cacheManager = GeneralUtility::makeInstance(CacheManager::class);
        }
        return $this->cacheManager;
    }

    public function listAction(): ResponseInterface
    {
        $this->logger->info('listAction called');
    
        $currentPageId = $GLOBALS['TSFE']->id;
        $viewData = $this->getCachedOrGenerateSuggestions($currentPageId);
    
        $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
        $nlpConfig = $extensionConfiguration->get('semantic_suggestion');
        
        $this->logger->info('Semantic Suggestion Configuration', ['config' => $nlpConfig]);
        
        $nlpEnabled = ($nlpConfig['enableNlpAnalysis'] ?? false) && $this->nlpService->isEnabled();
        
        $this->logger->info('NLP Enabled Status', ['nlpEnabled' => $nlpEnabled]);
        
        if ($nlpEnabled) {
            $this->enrichSuggestionsWithNlp($viewData['suggestions']);
        }
    
        $templatePath = $nlpEnabled
            ? 'EXT:semantic_suggestion/Resources/Private/Templates/Suggestions/SuggestionsNlp.html'
            : 'EXT:semantic_suggestion/Resources/Private/Templates/Suggestions/List.html';
    
        $this->view->setTemplatePathAndFilename(GeneralUtility::getFileAbsFileName($templatePath));
        $this->view->assignMultiple($viewData);
        $this->view->assign('nlpEnabled', $nlpEnabled);
        $this->view->assign('apiUrl', $this->nlpService->getApiUrl());
        $this->view->assign('debugConfig', $nlpConfig);
        $this->view->assign('debugNlpEnabled', $nlpEnabled);
    
        return $this->htmlResponse();
    }


    protected function getCachedOrGenerateSuggestions(int $currentPageId): array
    {
        $cacheIdentifier = 'suggestions_' . $currentPageId;
        $cache = $this->getCacheManager()->getCache('semantic_suggestion');

        if ($cache->has($cacheIdentifier)) {
            $this->logger->debug('Cache hit for suggestions', ['pageId' => $currentPageId]);
            return $cache->get($cacheIdentifier);
        }

        $this->logger->debug('Cache miss for suggestions', ['pageId' => $currentPageId]);
        $viewData = $this->generateSuggestions($currentPageId);
        
        if (!empty($viewData['suggestions'])) {
            $cache->set($cacheIdentifier, $viewData, ['tx_semanticsuggestion'], 3600);
        } else {
            $this->logger->warning('No suggestions generated', ['pageId' => $currentPageId]);
        }

        return $viewData;
    }
    protected function enrichSuggestionsWithNlp(array &$suggestions): void
    {
        $this->logger->info('Enriching suggestions with NLP data');

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_semanticsuggestion_nlp_results');
        $pageUids = array_column($suggestions, 'data');
        $pageUids = array_column($pageUids, 'uid');
        
        $this->logger->debug('Page UIDs for NLP enrichment', ['uids' => $pageUids]);

        $queryBuilder = $connection->createQueryBuilder();
        $nlpResults = $queryBuilder
            ->select('*')
            ->from('tx_semanticsuggestion_nlp_results')
            ->where(
                $queryBuilder->expr()->in('page_uid', $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY))
            )
            ->execute()
            ->fetchAll();

        $this->logger->debug('NLP results fetched', ['count' => count($nlpResults)]);

        $nlpResultsByPageUid = array_column($nlpResults, null, 'page_uid');
        
        foreach ($suggestions as &$suggestion) {
            $pageUid = $suggestion['data']['uid'];
            if (isset($nlpResultsByPageUid[$pageUid])) {
                $nlpResult = $nlpResultsByPageUid[$pageUid];
                $suggestion['nlp'] = [
                    'sentiment' => $nlpResult['sentiment'],
                    'keyphrases' => json_decode($nlpResult['keyphrases'], true),
                    'category' => $nlpResult['category'],
                    'named_entities' => json_decode($nlpResult['named_entities'], true),
                    'readability_score' => $nlpResult['readability_score'],
                ];
                $this->logger->debug('NLP data added to suggestion', ['pageUid' => $pageUid, 'nlpData' => $suggestion['nlp']]);
            } else {
                $this->logger->warning('No NLP data found for page', ['pageUid' => $pageUid]);
            }
        }
    }
    

    protected function generateSuggestions(int $currentPageId): array
    {
        $this->logger->info('Generating suggestions for page', ['currentPageId' => $currentPageId]);
    
        $parentPageId = isset($this->settings['parentPageId']) ? (int)$this->settings['parentPageId'] : 0; 
        $proximityThreshold = isset($this->settings['proximityThreshold']) ? (float)$this->settings['proximityThreshold'] : 0.3; 
        $maxSuggestions = isset($this->settings['maxSuggestions']) ? (int)$this->settings['maxSuggestions'] : 5; 
        $depth = isset($this->settings['recursive']) ? (int)$this->settings['recursive'] : 0; 
        $excludePages = GeneralUtility::intExplode(',', $this->settings['excludePages'] ?? '', true);
    
        $this->logger->debug('Settings for suggestion generation', [
            'parentPageId' => $parentPageId,
            'proximityThreshold' => $proximityThreshold,
            'maxSuggestions' => $maxSuggestions,
            'depth' => $depth,
            'excludePages' => $excludePages
        ]);
    
        $analysisData = $this->pageAnalysisService->analyzePages($parentPageId, $depth);
        $analysisResults = $analysisData['results'] ?? [];
    
        $this->logger->debug('Analysis results', [
            'totalPages' => count($analysisResults),
            'currentPagePresent' => isset($analysisResults[$currentPageId])
        ]);
    
        $suggestions = $this->findSimilarPages($analysisResults, $currentPageId, $proximityThreshold, $maxSuggestions, $excludePages);
    
        $this->logger->info('Suggestions generated', [
            'count' => count($suggestions),
            'currentPageId' => $currentPageId
        ]);
    
        $viewData = [
            'currentPageTitle' => $analysisResults[$currentPageId]['title']['content'] ?? 'Current Page',
            'suggestions' => $suggestions,
            'analysisResults' => $analysisResults,
            'proximityThreshold' => $proximityThreshold,
            'maxSuggestions' => $maxSuggestions,
        ];
    
        return $viewData;
    }


protected function findSimilarPages(array $analysisResults, int $currentPageId, float $threshold, int $maxSuggestions, array $excludePages): array
{
    $this->logger->info('Finding similar pages', [
        'currentPageId' => $currentPageId,
        'threshold' => $threshold,
        'maxSuggestions' => $maxSuggestions
    ]);

    $suggestions = [];
    $pageRepository = GeneralUtility::makeInstance(PageRepository::class);

    if (isset($analysisResults[$currentPageId]['similarities'])) {
        $similarities = $analysisResults[$currentPageId]['similarities'];
        $this->logger->debug('Similarities found for current page', ['count' => count($similarities)]);
        
        arsort($similarities);
        foreach ($similarities as $pageId => $similarity) {
            $this->logger->debug('Checking similarity', ['pageId' => $pageId, 'score' => $similarity['score']]);
            
            if (count($suggestions) >= $maxSuggestions) {
                $this->logger->debug('Max suggestions reached', ['maxSuggestions' => $maxSuggestions]);
                break;
            }
            if ($similarity['score'] < $threshold) {
                $this->logger->debug('Page excluded: below threshold', ['pageId' => $pageId, 'score' => $similarity['score'], 'threshold' => $threshold]);
                continue;
            }
            if (in_array($pageId, $excludePages)) {
                $this->logger->debug('Page excluded: in exclude list', ['pageId' => $pageId]);
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
            
            $this->logger->debug('Added suggestion', ['pageId' => $pageId, 'similarity' => $similarity['score']]);
        }
    } else {
        $this->logger->warning('No similarities found for current page', ['currentPageId' => $currentPageId]);
    }

    $this->logger->info('Found similar pages', ['count' => count($suggestions)]);
    return $suggestions;
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
}