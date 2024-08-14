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
use Psr\Log\LoggerInterface;

class SuggestionsController extends ActionController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected PageAnalysisService $pageAnalysisService;
    protected FileRepository $fileRepository;

    public function __construct(
        PageAnalysisService $pageAnalysisService, 
        FileRepository $fileRepository,
        LoggerInterface $logger
    ) {
        $this->pageAnalysisService = $pageAnalysisService;
        $this->fileRepository = $fileRepository;
        $this->setLogger($logger);
    }




   public function listAction(): ResponseInterface
{
    $this->logger->info('listAction called');

    $currentPageId = $GLOBALS['TSFE']->id;
    $cacheIdentifier = 'suggestions_' . $currentPageId;
    $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
    $cache = $cacheManager->getCache('semantic_suggestion');

    try {
        if ($cache->has($cacheIdentifier)) {
            $this->logger->debug('Cache hit for suggestions', ['pageId' => $currentPageId]);
            $viewData = $cache->get($cacheIdentifier);
        } else {
            $this->logger->debug('Cache miss for suggestions', ['pageId' => $currentPageId]);
            $viewData = $this->generateSuggestions($currentPageId);
            
            if (!empty($viewData['suggestions'])) {
                $cache->set($cacheIdentifier, $viewData, ['tx_semanticsuggestion'], 3600);
            } else {
                $this->logger->warning('No suggestions generated', ['pageId' => $currentPageId]);
            }
        }

        // Vérifier si l'extension NLP est activée et utiliser le template approprié
        $nlpEnabled = \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('semantic_suggestion');

        // Récupérer la configuration de l'extension
        $extensionConfiguration = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Configuration\ExtensionConfiguration::class);
        $nlpConfig = $extensionConfiguration->get('semantic_suggestion');
    
        // Afficher la configuration dans les logs
        $this->logger->info('Semantic Suggestion Configuration', ['config' => $nlpConfig]);
    
        // Vérifier si l'analyse NLP est activée
        $nlpEnabled = ($nlpConfig['enableNlpAnalysis'] ?? false) && \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('semantic_suggestion');
    
        $this->logger->info('NLP Enabled Status', ['nlpEnabled' => $nlpEnabled]);
    
        // Passer les informations de débogage à la vue
        $this->view->assign('debugConfig', $nlpConfig);
        $this->view->assign('debugNlpEnabled', $nlpEnabled);
    
        if ($nlpEnabled) {
            $this->logger->info('NLP analysis is enabled, using NlpSuggestions template');
            $templatePath = 'EXT:semantic_suggestion/Resources/Private/Templates/Suggestions/SuggestionsNlp.html';
        } else {
            $this->logger->info('NLP analysis is disabled, using default Suggestions template');
            $templatePath = 'EXT:semantic_suggestion/Resources/Private/Templates/Suggestions/List.html';
        }
    
        $this->view->setTemplatePathAndFilename(GeneralUtility::getFileAbsFileName($templatePath));
        $this->view->assignMultiple($viewData);
        $this->view->assign('nlpEnabled', $nlpEnabled);

    } catch (\Exception $e) {
        $this->logger->error('Error in listAction', ['exception' => $e->getMessage()]);
        $this->view->assign('error', 'An error occurred while generating suggestions.');
    }

    return $this->htmlResponse();
}


    
    protected function generateSuggestions(int $currentPageId): array
    {
        $parentPageId = isset($this->settings['parentPageId']) ? (int)$this->settings['parentPageId'] : 0; 
        $proximityThreshold = isset($this->settings['proximityThreshold']) ? (float)$this->settings['proximityThreshold'] : 0.3; 
        $maxSuggestions = isset($this->settings['maxSuggestions']) ? (int)$this->settings['maxSuggestions'] : 5; 
        $depth = isset($this->settings['recursive']) ? (int)$this->settings['recursive'] : 0; 
        $excludePages = GeneralUtility::intExplode(',', $this->settings['excludePages'] ?? '', true);
    
        $analysisData = $this->pageAnalysisService->analyzePages($parentPageId, $depth);
        $analysisResults = $analysisData['results'] ?? [];
    
        $suggestions = $this->findSimilarPages($analysisResults, $currentPageId, $proximityThreshold, $maxSuggestions, $excludePages);
    
        $this->logger->debug('Suggestions generated', [
            'count' => count($suggestions),
            'parentPageId' => $parentPageId,
            'currentPageId' => $currentPageId,
            'proximityThreshold' => $proximityThreshold,
            'maxSuggestions' => $maxSuggestions,
            'depth' => $depth
        ]);
    
        $viewData = [
            'currentPageTitle' => $analysisResults[$currentPageId]['title']['content'] ?? 'Current Page',
            'suggestions' => $suggestions,
            'analysisResults' => $analysisResults,
            'proximityThreshold' => $proximityThreshold,
            'maxSuggestions' => $maxSuggestions,
        ];
    
        // Ajout des données NLP si l'extension est présente
        if (\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::isLoaded('semantic_suggestion_nlp')) {
            $nlpAnalyzer = GeneralUtility::makeInstance(\TalanHdf\SemanticSuggestion\Service\NlpService::class);
            foreach ($viewData['suggestions'] as &$suggestion) {
                $pageUid = $suggestion['data']['uid'];
                $pageContent = $suggestion['data']['tt_content'] ?? '';
                $suggestion['nlp'] = $nlpAnalyzer->analyzeContent($pageContent);
                $currentPageContent = $this->getPageContents($currentPageId);
                $suggestion['nlpSimilarity'] = $nlpAnalyzer->calculateNlpSimilarity(
                    $nlpAnalyzer->analyzeContent($currentPageContent),
                    $suggestion['nlp']
                );
            }
            $viewData['nlpEnabled'] = true;
        } else {
            $viewData['nlpEnabled'] = false;
        }

        return $viewData;
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
            arsort($similarities);
            foreach ($similarities as $pageId => $similarity) {
                if (count($suggestions) >= $maxSuggestions) break;
                if ($similarity['score'] < $threshold || in_array($pageId, $excludePages)) {
                    $this->logger->debug('Page excluded', ['pageId' => $pageId, 'reason' => $similarity['score'] < $threshold ? 'below threshold' : 'in exclude list']);
                    continue;
                }
                
                $pageData = $pageRepository->getPage($pageId);
                $pageData['tt_content'] = $this->getPageContents($pageId);
                $excerpt = $this->prepareExcerpt($pageData, (int)($this->settings['excerptLength'] ?? 150));
    
                // Calcul du score de récence
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
    
        return trim($content);
    }
}