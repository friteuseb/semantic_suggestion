<?php
namespace TalanHdf\SemanticSuggestion\Service;

use TalanHdf\SemanticSuggestion\Loader\LoaderInterface;
use TalanHdf\SemanticSuggestion\Analyzer\AnalyzerInterface;

class SemanticSuggestionService
{
    protected LoaderInterface $loader;
    protected AnalyzerInterface $analyzer;

    public function __construct(LoaderInterface $loader, AnalyzerInterface $analyzer)
    {
        $this->loader = $loader;
        $this->analyzer = $analyzer;
    }

    public function getSuggestions($currentIdentifier, array $potentialSuggestions): array
    {
        $currentContent = $this->loader->load($currentIdentifier);
        $currentAnalysis = $this->analyzer->analyze($currentContent);

        $suggestions = [];
        foreach ($potentialSuggestions as $identifier) {
            $content = $this->loader->load($identifier);
            $analysis = $this->analyzer->analyze($content);
            $similarity = $this->analyzer->calculateSimilarity($currentAnalysis, $analysis);
            
            if ($similarity > $this->getThreshold()) {
                $suggestions[] = [
                    'identifier' => $identifier,
                    'similarity' => $similarity,
                    // Ajoutez d'autres informations si nécessaire
                ];
            }
        }

        return $suggestions;
    }

    protected function getThreshold(): float
    {
        // Récupérez le seuil de similarité depuis la configuration
    }

    public function analyzePages(array $pages, int $currentLanguageUid): array
    {
        $startTime = microtime(true);
    
        if (empty($pages)) {
            $this->logger?->warning('No pages provided for analysis');
            return [
                'results' => [],
                'metrics' => [
                    'executionTime' => microtime(true) - $startTime,
                    'totalPages' => 0,
                    'similarityCalculations' => 0,
                    'fromCache' => false,
                ],
            ];
        }
    
        $language = $this->getCurrentLanguage();
        $stopwords = $this->stopWordsService->getStopWordsForLanguage($language);
    
        $this->logDebug('Starting page analysis', [
            'pageCount' => count($pages),
            'languageUid' => $currentLanguageUid,
            'language' => $language,
            'stopwordsCount' => count($stopwords)
        ]);
    
    
        $pagesByLanguage = [];
        foreach ($pages as $page) {
            $lang = $page['sys_language_uid'] ?? 0;
            $pagesByLanguage[$lang] = ($pagesByLanguage[$lang] ?? 0) + 1;
        }
    
    
        $firstPage = null;
        foreach ($pages as $page) {
            if ($page !== null) {
                $firstPage = $page;
                break;
            }
        }
    
        if ($firstPage === null) {
            $this->logger?->warning('No valid pages found in the provided array');
            return [
                'results' => [],
                'metrics' => [
                    'executionTime' => microtime(true) - $startTime,
                    'totalPages' => 0,
                    'similarityCalculations' => 0,
                    'fromCache' => false,
                ],
            ];
        }
    
        $parentPageId = $firstPage['pid'] ?? 0;
        $depth = $this->calculateDepth($pages);
        $cacheIdentifier = "semantic_analysis_{$parentPageId}_{$depth}_{$language}";
    
        if ($this->cache->has($cacheIdentifier)) {
            $cachedResult = $this->cache->get($cacheIdentifier);
            $cachedResult['metrics']['fromCache'] = true;
            $cachedResult['metrics']['executionTime'] = microtime(true) - $startTime;
            return $cachedResult;
        }
    
        try {
            $this->logDebug('Analyzing pages', ['pageCount' => count($pages), 'languageUid' => $currentLanguageUid]);
            $totalPages = count($pages);
            $analysisResults = [];
    
            foreach ($pages as $page) {
                if (isset($page['uid'])) {
                    $analysisResults[$page['uid']] = $this->preparePageData($page, $currentLanguageUid);
                } else {
                    $this->logger?->warning('Page without UID encountered', ['page' => $page]);
                }
            }
    
            $similarityCalculations = 0;
            foreach ($analysisResults as $pageId => &$pageData) {
                foreach ($analysisResults as $comparisonPageId => $comparisonPageData) {
                    if ($pageId !== $comparisonPageId) {
                        $similarity = $this->calculateSimilarity($pageData, $comparisonPageData);
                        $pageData['similarities'][$comparisonPageId] = [
                            'score' => $similarity['finalSimilarity'],
                            'semanticSimilarity' => $similarity['semanticSimilarity'],
                            'recencyBoost' => $similarity['recencyBoost'],
                            'commonKeywords' => $this->findCommonKeywords($pageData, $comparisonPageData),
                            'relevance' => $this->determineRelevance($similarity['finalSimilarity']),
                            'ageInDays' => round((time() - ($comparisonPageData['content_modified_at'] ?? time())) / (24 * 3600), 1),
                        ];
                        
                        $similarityCalculations++;
                    }
                }
            }
    
            $result = [
                'results' => $analysisResults,
                'metrics' => [
                    'executionTime' => microtime(true) - $startTime,
                    'totalPages' => $totalPages,
                    'similarityCalculations' => $similarityCalculations,
                    'fromCache' => false,
                ],
            ];
    
            $this->cache->set(
                $cacheIdentifier,
                $result,
                ['tx_semanticsuggestion', "pages_{$parentPageId}"],
                86400
            );
    
            $this->logDebug('Analysis complete', [
                'executionTime' => microtime(true) - $startTime,
                'totalPages' => $totalPages,
                'similarityCalculations' => $similarityCalculations
            ]);
    
            return $result;
    
        } catch (\Exception $e) {
            $this->logger?->error('Error during page analysis', ['exception' => $e->getMessage()]);
            return [
                'results' => [],
                'metrics' => [
                    'executionTime' => microtime(true) - $startTime,
                    'totalPages' => 0,
                    'similarityCalculations' => 0,
                    'fromCache' => false,
                    'error' => $e->getMessage(),
                ],
            ];
        }
    }

    protected function preparePageData(array $page, int $currentLanguageUid): array
    {
        $preparedData = [
            'uid' => $page['uid'],
            'sys_language_uid' => $page['sys_language_uid'] ?? 0,
            'isTranslation' => isset($page['_PAGES_OVERLAY']),
        ];
    
        $language = $this->getCurrentLanguage();
    
        foreach ($this->settings['analyzedFields'] as $field => $weight) {
            $originalContent = $page[$field] ?? '';
    
            if ($field === 'content' && empty($originalContent)) {
                try {
                    $originalContent = $this->getPageContent($page['uid'], $currentLanguageUid);
                } catch (\Exception $e) {
                    $this->logger->error('Error fetching page content', [
                        'pageId' => $page['uid'], 
                        'language' => $currentLanguageUid, 
                        'exception' => $e->getMessage()
                    ]);
                    $originalContent = '';
                }
            }
    
            if (!empty($originalContent) && is_string($originalContent)) {

                
                $processedContent = $this->stopWordsService->removeStopWords($originalContent, $language);

                
                $preparedData[$field] = [
                    'content' => $processedContent,
                    'weight' => (float)$weight
                ];
            } else {
                $preparedData[$field] = [
                    'content' => '',
                    'weight' => (float)$weight
                ];
            }
        }
    
        $preparedData['content_modified_at'] = $page['content_modified_at'] ?? $page['crdate'] ?? time();

        $this->logDebug('Page data prepared', [
            'pageUid' => $page['uid'],
            'language' => $currentLanguageUid,
            'fieldsProcessed' => array_keys($this->settings['analyzedFields']),
            'contentLength' => strlen($preparedData['content']['content'] ?? '')
        ]);
        return $preparedData;
    }


    protected function getCurrentLanguage(): string
    {
        $languageAspect = $this->context->getAspect('language');
        $languageId = $languageAspect->getId();
        
        try {
            $currentPageId = $this->getCurrentPageId();
            if ($currentPageId === null) {
                throw new \RuntimeException('Unable to determine current page ID');
            }
    
            $currentSite = $this->siteFinder->getSiteByPageId($currentPageId);
            $siteLanguage = $currentSite->getLanguageById($languageId);
            if ($siteLanguage) {
                $language = strtolower(substr($siteLanguage->getHreflang(), 0, 2));
                $this->logDebug('Language detected', ['language' => $language, 'languageId' => $languageId]);
                return $language;
            }
        } catch (\Exception $e) {
            $this->logger?->warning('Failed to detect language automatically', ['exception' => $e->getMessage()]);
        }
    
        // Fallback to default language
        $defaultLanguage = $this->settings['defaultLanguage'] ?? 'en';
        $this->logDebug('Using fallback language', ['language' => $defaultLanguage]);
        return $defaultLanguage;
    }

}