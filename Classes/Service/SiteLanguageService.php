<?php

declare(strict_types=1);

namespace TalanHdf\SemanticSuggestion\Service;

use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Context\Context;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service to automatically detect and map TYPO3 site languages
 * Eliminates the need for hard-coded language ID mappings
 */
class SiteLanguageService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected SiteFinder $siteFinder;
    protected Context $context;
    protected array $languageCache = [];

    public function __construct(
        ?SiteFinder $siteFinder = null,
        ?Context $context = null,
        ?LoggerInterface $logger = null
    ) {
        $this->siteFinder = $siteFinder ?? GeneralUtility::makeInstance(SiteFinder::class);
        $this->context = $context ?? GeneralUtility::makeInstance(Context::class);
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Get language code from TYPO3 site configuration for a specific language UID
     */
    public function getLanguageCodeByUid(int $languageUid, ?int $rootPageId = null): ?string
    {
        $cacheKey = "{$languageUid}_{$rootPageId}";
        
        if (isset($this->languageCache[$cacheKey])) {
            return $this->languageCache[$cacheKey];
        }

        try {
            // Try to get site from root page ID or current request
            $site = null;
            
            if ($rootPageId !== null) {
                $site = $this->siteFinder->getSiteByPageId($rootPageId);
            } else {
                // Try to get from current context
                $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
                if ($request && $request->getAttribute('site')) {
                    $site = $request->getAttribute('site');
                }
            }
            
            if ($site === null) {
                $this->logger->warning('No site found for language detection', [
                    'languageUid' => $languageUid,
                    'rootPageId' => $rootPageId
                ]);
                return null;
            }

            $siteLanguage = $site->getLanguageById($languageUid);
            if ($siteLanguage) {
                $locale = $siteLanguage->getLocale();
                $languageCode = strtolower(substr($locale->getLanguageCode(), 0, 2));
                
                $this->languageCache[$cacheKey] = $languageCode;
                
                $this->logger->debug('Language code detected from site configuration', [
                    'languageUid' => $languageUid,
                    'languageCode' => $languageCode,
                    'locale' => $locale->getName(),
                    'siteIdentifier' => $site->getIdentifier()
                ]);
                
                return $languageCode;
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Error detecting language from site configuration', [
                'languageUid' => $languageUid,
                'rootPageId' => $rootPageId,
                'exception' => $e->getMessage()
            ]);
        }

        return null;
    }

    /**
     * Get all available languages from TYPO3 site configuration
     */
    public function getAllSiteLanguages(?int $rootPageId = null): array
    {
        try {
            $site = null;
            
            if ($rootPageId !== null) {
                $site = $this->siteFinder->getSiteByPageId($rootPageId);
            } else {
                $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
                if ($request && $request->getAttribute('site')) {
                    $site = $request->getAttribute('site');
                }
            }
            
            if ($site === null) {
                return [];
            }

            $languages = [];
            foreach ($site->getLanguages() as $siteLanguage) {
                $languageUid = $siteLanguage->getLanguageId();
                $locale = $siteLanguage->getLocale();
                $languageCode = strtolower(substr($locale->getLanguageCode(), 0, 2));
                
                $languages[$languageUid] = [
                    'uid' => $languageUid,
                    'code' => $languageCode,
                    'title' => $siteLanguage->getTitle(),
                    'locale' => $locale->getName(),
                    'hreflang' => $siteLanguage->getHreflang()
                ];
            }

            $this->logger->debug('All site languages detected', [
                'siteIdentifier' => $site->getIdentifier(),
                'languages' => array_map(fn($lang) => "{$lang['uid']}:{$lang['code']}", $languages)
            ]);

            return $languages;
            
        } catch (\Exception $e) {
            $this->logger->error('Error getting all site languages', [
                'rootPageId' => $rootPageId,
                'exception' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Detect language code for a page using content analysis as fallback
     */
    public function detectLanguageForPage(array $pageData, ?object $languageDetector = null): string
    {
        // First try: TYPO3 site configuration
        $languageUid = (int)($pageData['sys_language_uid'] ?? 0);
        $rootPageId = $this->findRootPageId($pageData);
        
        $siteLanguageCode = $this->getLanguageCodeByUid($languageUid, $rootPageId);
        if ($siteLanguageCode !== null) {
            $this->logger->debug('Language detected from TYPO3 site config', [
                'pageId' => $pageData['uid'] ?? 'unknown',
                'languageUid' => $languageUid,
                'detectedCode' => $siteLanguageCode
            ]);
            return $siteLanguageCode;
        }

        // Second try: Content analysis with nlp_tools
        if ($languageDetector !== null) {
            $contentText = $this->extractContentForAnalysis($pageData);
            if (!empty($contentText)) {
                try {
                    $detectedCode = $languageDetector->detectLanguage($contentText);
                    $this->logger->debug('Language detected from content analysis', [
                        'pageId' => $pageData['uid'] ?? 'unknown',
                        'detectedCode' => $detectedCode,
                        'contentLength' => strlen($contentText)
                    ]);
                    return $detectedCode;
                } catch (\Exception $e) {
                    $this->logger->warning('Content language detection failed', [
                        'pageId' => $pageData['uid'] ?? 'unknown',
                        'exception' => $e->getMessage()
                    ]);
                }
            }
        }

        // Third try: Default fallback
        $defaultCode = 'en';
        $this->logger->debug('Using fallback language', [
            'pageId' => $pageData['uid'] ?? 'unknown',
            'fallbackCode' => $defaultCode
        ]);
        
        return $defaultCode;
    }

    /**
     * Check if two pages are in compatible languages for similarity comparison
     */
    public function areLanguagesCompatible(string $lang1, string $lang2): bool
    {
        // Same language is always compatible
        if ($lang1 === $lang2) {
            return true;
        }

        // For now, only same languages are compatible
        // Could be extended later for similar languages (e.g., de/at, en/us)
        return false;
    }

    private function findRootPageId(array $pageData): ?int
    {
        // Try to find the site root page ID from the page data
        // This is a simplified implementation
        $pid = (int)($pageData['pid'] ?? 0);
        
        // If it's already a root page (pid = 0), use the page itself
        if ($pid === 0) {
            return (int)($pageData['uid'] ?? null);
        }
        
        // For now, return the page ID - could be improved with recursive lookup
        return (int)($pageData['uid'] ?? null);
    }

    private function extractContentForAnalysis(array $pageData): string
    {
        $contentParts = [];
        
        // Extract text from common fields
        $fields = ['title', 'description', 'keywords', 'abstract'];
        foreach ($fields as $field) {
            if (!empty($pageData[$field])) {
                $contentParts[] = strip_tags((string)$pageData[$field]);
            }
        }
        
        return implode(' ', $contentParts);
    }

    /**
     * Clear the language cache (useful for testing)
     */
    public function clearCache(): void
    {
        $this->languageCache = [];
    }
}