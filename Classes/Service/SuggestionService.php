<?php

namespace TalanHdf\SemanticSuggestion\Service;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Pagination\ArrayPaginator;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

class SuggestionService
{
    public const DEFAULT_QUALITY_LEVEL = 0.3;
    public const MAX_RECENCY_DAYS = 30;

    /**
     * Candidate rows are fetched beyond $maxSuggestions because exclusions and the
     * language re-check are applied in PHP afterwards.
     */
    private const CANDIDATE_POOL_FACTOR = 5;
    private const MIN_CANDIDATE_POOL = 50;

    protected array $settings = [];

    public function __construct(
        protected PageAnalysisService $pageAnalysisService,
        protected FileRepository $fileRepository,
        protected PageRepository $pageRepository,
        protected UtilityService $utility,
        protected ConfigurationManagerInterface $configurationManager
    ) {
        // Initialiser les settings depuis le ConfigurationManager
        $this->initializeSettings();
    }
    
    protected function initializeSettings(): void
    {
        $this->settings = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
            'semanticsuggestion_suggestions'
        );
    }

    /**
     * Generates semantic suggestions for a given page based on analysis.
     *
     * @param array $controllerSettings Configuration settings for the suggestion generation
     * @param int $currentPageId The ID of the current page
     * @param int $currentPage Current page number for pagination
     * @param int $itemsPerPage Number of items per page
     * @return array Array containing suggestions, pagination info, and analysis results
     */
    public function generateSuggestions(array $controllerSettings, int $currentPageId, int $currentPage, int $itemsPerPage): array
    {
        $parentPageId = isset($controllerSettings['parentPageId']) ? (int)$controllerSettings['parentPageId'] : 0;
        $depth = isset($controllerSettings['recursive']) ? (int)$controllerSettings['recursive'] : 0;

        // NEW: Unified quality configuration
        $qualityLevel = $this->getQualityLevel($controllerSettings);
        $displayThreshold = $qualityLevel; // Display at quality level

        // Legacy support for proximityThreshold (fallback)
        $proximityThreshold = $displayThreshold;

        $excludePages = GeneralUtility::intExplode(',', $controllerSettings['excludePages'] ?? '', true);
        $maxSuggestions = isset($controllerSettings['maxSuggestions']) ? (int)$controllerSettings['maxSuggestions'] : 3;
        $excerptLength = isset($controllerSettings['excerptLength']) ? (int)$controllerSettings['excerptLength'] : 150;
        $currentLanguageUid = $this->utility->getCurrentLanguageUid();

        $pages = $this->getPages($parentPageId, $depth);
        $analysisData = $this->pageAnalysisService->analyzePages($pages, $currentLanguageUid);
        $analysisResults = $analysisData['results'] ?? [];

        $suggestions = $this->findSimilarPages(
            $analysisResults, 
            $currentPageId, 
            $proximityThreshold, 
            $excludePages, 
            $currentLanguageUid, 
            $maxSuggestions,
            $excerptLength,
            $controllerSettings
        );

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

        $this->utility->logDebug('Suggestions generated', [
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

    /**
     * Generates semantic suggestions from the database for a given page.
     *
     * @param int $currentPageId The ID of the current page
     * @param int $currentPage Current page number for pagination
     * @param int $itemsPerPage Number of items per page
     * @return array Array containing suggestions and pagination info
     */
    public function generateSuggestionsFromDatabase(int $currentPageId, int $currentPage, int $itemsPerPage): array
    {
        $this->utility->logDebug('Generating suggestions from database', [
            'pageId' => $currentPageId,
            'currentPage' => $currentPage,
            'itemsPerPage' => $itemsPerPage
        ]);

        // NEW: Unified quality configuration
        $qualityLevel = $this->getQualityLevel($this->settings);
        $displayThreshold = $qualityLevel; // Display at quality level

        // Legacy support
        $proximityThreshold = $displayThreshold;

        $maxSuggestions = (int)($this->settings['maxSuggestions'] ?? 3);
        $currentLanguageUid = $this->utility->getCurrentLanguageUid();
        $excludePages = GeneralUtility::intExplode(',', $this->settings['excludePages'] ?? '', true);
        $excerptLength = (int)($this->settings['excerptLength'] ?? 150);

        $suggestions = $this->findSimilarPagesFromDatabase(
            $currentPageId,
            $proximityThreshold,
            $excludePages,
            $currentLanguageUid,
            $maxSuggestions,
            $excerptLength
        );

        // Implémenter la pagination
        $paginator = new ArrayPaginator($suggestions, $currentPage, $itemsPerPage);
        $paginatedSuggestions = [];
        foreach ($paginator->getPaginatedItems() as $pageId => $suggestion) {
            $paginatedSuggestions[$pageId] = $suggestion;
        }

        $pagination = [
            'currentPage' => $currentPage,
            'numberOfPages' => $paginator->getNumberOfPages(),
            'hasNextPage' => $paginator->getCurrentPageNumber() < $paginator->getNumberOfPages(),
            'hasPreviousPage' => $paginator->getCurrentPageNumber() > 1,
            'startRecord' => ($currentPage - 1) * $itemsPerPage + 1,
            'endRecord' => min($currentPage * $itemsPerPage, count($suggestions)),
            'totalItems' => count($suggestions)
        ];

        $this->utility->logDebug('Suggestions generated', [
            'count' => count($paginatedSuggestions),
            'totalItems' => $pagination['totalItems']
        ]);

        return [
            'currentPageTitle' => $this->pageRepository->getPage($currentPageId)['title'] ?? 'Current Page',
            'suggestions' => $paginatedSuggestions,
            'pagination' => $pagination
        ];
    }

    protected function prepareExcerpt(array $pageData, int $excerptLength, array $settings = []): string
    {
        // Utiliser le paramètre $settings si fourni, sinon utiliser $this->settings
        $sources = GeneralUtility::trimExplode(',', $settings['excerptSources'] ?? $this->settings['excerptSources'] ?? 'bodytext,description,abstract', true);

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

    protected function findSimilarPages(
        array $analysisResults, 
        int $currentPageId, 
        float $threshold, 
        array $excludePages, 
        int $currentLanguageUid, 
        int $maxSuggestions,
        int $excerptLength = 150,
        array $settings = []
    ): array {
        $this->utility->logDebug('Finding similar pages', [
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
                    $this->utility->logDebug('Page excluded', [
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
                $excerpt = $this->prepareExcerpt($pageData, $excerptLength, $settings);

                $recencyScore = $this->calculateRecencyScore($pageData['tstamp']);

                $suggestions[$pageId] = [
                    'similarity' => $similarity['score'],
                    'commonKeywords' => implode(', ', $similarity['commonKeywords'] ?? []),
                    'relevance' => $similarity['relevance'],
                    'aboveThreshold' => true,
                    'data' => $pageData,
                    'excerpt' => $excerpt,
                    'recency' => $recencyScore
                ];
                $suggestions[$pageId]['data']['media'] = $this->getPageMedia($pageId);

                $this->utility->logDebug('Added suggestion', [
                    'pageId' => $pageId,
                    'similarity' => $similarity['score'],
                    'pageLangUid' => $pageLangUid
                ]);

                if (count($suggestions) >= $maxSuggestions) {
                    break;
                }
            }
        } else {
            $this->utility->logDebug('No similarities found for current page', ['currentPageId' => $currentPageId]);
        }

        $this->utility->logDebug('Found similar pages', ['count' => count($suggestions)]);
        return $suggestions;
    }

    protected function findSimilarPagesFromDatabase(
        int $currentPageId,
        float $threshold,
        array $excludePages,
        int $currentLanguageUid,
        int $maxSuggestions,
        int $excerptLength = 150
    ): array {
        $this->utility->logDebug('Finding similar pages from database', [
            'currentPageId' => $currentPageId,
            'threshold' => $threshold,
            'languageUid' => $currentLanguageUid,
            'maxSuggestions' => $maxSuggestions
        ]);

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('tx_semanticsuggestion_similarities');

        $queryBuilder
            ->select('*')
            ->from('tx_semanticsuggestion_similarities')
            ->where(
                $queryBuilder->expr()->eq('page_id', $queryBuilder->createNamedParameter($currentPageId, \Doctrine\DBAL\ParameterType::INTEGER)), // MODIFIÉ
                $queryBuilder->expr()->gte('similarity_score', $queryBuilder->createNamedParameter($threshold, \Doctrine\DBAL\ParameterType::STRING)), // MODIFIÉ (PDO::PARAM_STR -> STRING)
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($currentLanguageUid, \Doctrine\DBAL\ParameterType::INTEGER)) // MODIFIÉ
            )
            ->orderBy('similarity_score', 'DESC');

        // Restrict to the current site. Without this, overlapping scheduler task scopes
        // (or a task started from page 0) mix rows from several trees together.
        $rootPageId = $this->resolveRootPageId($currentPageId);
        if ($rootPageId > 0) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('root_page_id', $queryBuilder->createNamedParameter($rootPageId, \Doctrine\DBAL\ParameterType::INTEGER))
            );
        }

        // Over-fetch: excludePages and the language re-check below run in PHP, so a
        // LIMIT of exactly $maxSuggestions would return fewer results than requested
        // as soon as one candidate is filtered out.
        $queryBuilder->setMaxResults(max(self::MIN_CANDIDATE_POOL, $maxSuggestions * self::CANDIDATE_POOL_FACTOR));

        $similarities = $queryBuilder->executeQuery()->fetchAllAssociative();

        $this->utility->logDebug('Database query results', [
            'count' => count($similarities),
            'firstResult' => $similarities[0] ?? null
        ]);

        $suggestions = [];
        foreach ($similarities as $similarity) {
            $similarPageId = (int)$similarity['similar_page_id'];

            if (in_array($similarPageId, $excludePages)) {
                $this->utility->logDebug('Page excluded', ['pageId' => $similarPageId]);
                continue;
            }

            $pageData = $this->pageRepository->getPage($similarPageId);
            if (!$pageData) {
                $this->utility->logDebug('Page not found', ['pageId' => $similarPageId]);
                continue;
            }
            
            // Vérifier que la page suggérée est bien dans la même langue
            $pageLang = (int)($pageData['sys_language_uid'] ?? 0);
            if ($pageLang !== $currentLanguageUid) {
                $this->utility->logDebug('Page excluded due to language mismatch', [
                    'pageId' => $similarPageId,
                    'pageLang' => $pageLang,
                    'currentLang' => $currentLanguageUid
                ]);
                continue;
            }

            $excerpt = $this->prepareExcerpt($pageData, $excerptLength);
            $pageData['media'] = $this->getPageMedia($similarPageId);
            $suggestions[$similarPageId] = [
                'similarity' => $similarity['similarity_score'],
                'data' => $pageData,
                'excerpt' => $excerpt,
            ];

            // The SQL LIMIT is now a candidate pool, so the cap is enforced here.
            if (count($suggestions) >= $maxSuggestions) {
                break;
            }
        }

        $this->utility->logDebug('Suggestions prepared', ['count' => count($suggestions)]);
        return $suggestions;
    }

    /**
     * Resolve the site root page UID for a page, or 0 when it belongs to no site.
     */
    protected function resolveRootPageId(int $pageId): int
    {
        if ($pageId <= 0) {
            return 0;
        }

        try {
            return GeneralUtility::makeInstance(SiteFinder::class)
                ->getSiteByPageId($pageId)
                ->getRootPageId();
        } catch (\Exception $e) {
            $this->utility->logDebug('Could not resolve site root, suggestions not scoped to a site', [
                'pageId' => $pageId,
                'exception' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    protected function calculateRecencyScore(int $timestamp): float
    {
        $now = time();
        $age = $now - $timestamp;
        $maxAge = self::MAX_RECENCY_DAYS * 24 * 60 * 60; // 30 jours en secondes

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
        $pages = [];
        $languageAspect = GeneralUtility::makeInstance(Context::class)->getAspect('language');
        $languageId = $languageAspect->getId();

        $pageRecords = $this->pageRepository->getMenu(
            $parentPageId,
            '*',
            'sorting',
            '',
            false,
            false, // Changé de '' à false pour éviter l'erreur de type
            $languageId
        );

        foreach ($pageRecords as $pageRecord) {
            $pages[$pageRecord['uid']] = $pageRecord;
            if ($depth > 1) {
                $subpages = $this->getPages($pageRecord['uid'], $depth - 1);
                $pages = array_merge($pages, $subpages);
            }
        }
        $this->utility->logDebug('Retrieved pages', ['pages' => $pages]);
        return $pages;
    }

    /**
     * Get quality level from settings with backward compatibility
     */
    protected function getQualityLevel(array $settings): float
    {
        // NEW: Quality level (unified configuration)
        if (isset($settings['qualityLevel'])) {
            return (float)$settings['qualityLevel'];
        }

        // Legacy support: use proximityThreshold if available
        if (isset($settings['proximityThreshold'])) {
            return (float)$settings['proximityThreshold'];
        }

        // Default quality level for TF-IDF
        return self::DEFAULT_QUALITY_LEVEL;
    }

}