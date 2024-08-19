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
            $viewData['suggestions'] = $this->enrichSuggestionsWithNlp($viewData['suggestions']);
        }

        $formattedSuggestions = $this->formatSuggestions($viewData['suggestions']);
        
        $templatePath = $nlpEnabled
            ? 'EXT:semantic_suggestion/Resources/Private/Templates/Suggestions/SuggestionsNlp.html'
            : 'EXT:semantic_suggestion/Resources/Private/Templates/Suggestions/List.html';
    
        $this->view->setTemplatePathAndFilename(GeneralUtility::getFileAbsFileName($templatePath));
        $this->view->assignMultiple($viewData);
        $this->view->assign('suggestions', $formattedSuggestions);
        $this->view->assign('nlpEnabled', $nlpEnabled);
        $this->view->assign('apiUrl', $this->nlpService->getApiUrl());
        $this->view->assign('debugConfig', $nlpConfig);
        $this->view->assign('debugNlpEnabled', $nlpEnabled);
    
        return $this->htmlResponse();
    }


    private function getNlpResultsForPages(array $pageUids): array
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_semanticsuggestion_nlp_results');
        
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

        $nlpResultsByPageUid = [];
        foreach ($nlpResults as $result) {
            $nlpResultsByPageUid[$result['page_uid']] = [
                'sentiment' => $result['sentiment'],
                'keyphrases' => json_decode($result['keyphrases'], true),
                'category' => $result['category'],
                'named_entities' => json_decode($result['named_entities'], true),
                'readability_score' => $result['readability_score'],
                'word_count' => $result['word_count'],
                'sentence_count' => $result['sentence_count'],
                'average_sentence_length' => $result['average_sentence_length'],
                'lexical_diversity' => $result['lexical_diversity'],
                'top_n_grams' => json_decode($result['top_n_grams'], true),
                'semantic_coherence' => $result['semantic_coherence'],
                'sentiment_distribution' => json_decode($result['sentiment_distribution'], true),
            ];
        }

        return $nlpResultsByPageUid;
    }

    private function formatSuggestions(array $suggestions): array
    {
        return array_map(function ($suggestion) {
            if (isset($suggestion['nlp'])) {
                $suggestion['nlp'] = $this->formatNlpData($suggestion['nlp']);
            }
            return $suggestion;
        }, $suggestions);
    }

    private function formatNlpData(array $nlpData): array
    {
        $formatted = $nlpData;
        $jsonFields = ['keyphrases', 'named_entities', 'top_n_grams', 'sentiment_distribution'];
        
        foreach ($jsonFields as $field) {
            if (isset($formatted[$field]) && is_string($formatted[$field])) {
                $formatted[$field] = json_decode($formatted[$field], true) ?? [];
            }
        }

        // Convertir le sentiment en texte
        $formatted['sentiment'] = $this->convertSentimentToText($formatted['sentiment'] ?? '');

        return $formatted;
    }

    private function convertSentimentToText(string $sentiment): string
    {
        switch ($sentiment) {
            case '1 stars':
                return 'Très négatif';
            case '2 stars':
                return 'Négatif';
            case '3 stars':
                return 'Neutre';
            case '4 stars':
                return 'Positif';
            case '5 stars':
                return 'Très positif';
            default:
                return $sentiment;
        }
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
            $pageData['tt_content'] = $this->pageAnalysisService->getPageContentForAnalysis($pageId);
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



    private function enrichSuggestionsWithNlp(array $suggestions): array
    {
        $this->logger->info('Enriching suggestions with NLP data');

        $pageUids = array_column(array_column($suggestions, 'data'), 'uid');
        $this->logger->debug('Page UIDs for NLP enrichment', ['uids' => $pageUids]);

        $nlpResultsByPageUid = $this->getNlpResultsForPages($pageUids);

        foreach ($suggestions as &$suggestion) {
            $pageUid = $suggestion['data']['uid'];
            if (isset($nlpResultsByPageUid[$pageUid])) {
                $suggestion['nlp'] = $nlpResultsByPageUid[$pageUid];
                $this->logger->debug('NLP data added to suggestion', ['pageUid' => $pageUid, 'nlpData' => $suggestion['nlp']]);
            } else {
                $this->logger->info('No existing NLP data found for page, performing new analysis', ['pageUid' => $pageUid]);
                $content = $this->pageAnalysisService->getPageContent($pageUid);
                $nlpResults = $this->nlpService->analyzeContent($content);
                $this->nlpService->storeNlpResults($pageUid, $nlpResults);
                $suggestion['nlp'] = $nlpResults;
            }
        }

        return $suggestions;
    }
}