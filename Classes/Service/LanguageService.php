<?php
namespace TalanHdf\SemanticSuggestion\Service;

use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerAwareInterface;

class LanguageService implements LoggerAwareInterface
{
    protected SiteFinder $siteFinder;
    protected Context $context;
    protected LogManager $logManager;
    protected LoggerInterface $logger;

    public function __construct(SiteFinder $siteFinder, Context $context, LogManager $logManager)
    {
        $this->siteFinder = $siteFinder;
        $this->context = $context;
        $this->logManager = $logManager;
        $this->logger = $logManager->getLogger(__CLASS__); // Initialisation par défaut
    }

    /**
     * Implémentation de LoggerAwareInterface
     */
    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function getLanguageStatistics(array $pages, array $siteLanguages): array
    {
        $languageStats = [];

        foreach ($siteLanguages as $language) {
            if (!$language instanceof SiteLanguage) {
                $this->logger->warning('Invalid language object received', ['language' => $language]);
                continue;
            }

            $languageId = $language->getLanguageId();
            $languageStats[$languageId] = [
                'count' => 0,
                'info' => [
                    'title' => $language->getTitle(),
                    'twoLetterIsoCode' => method_exists($language, 'getTwoLetterIsoCode') ? $language->getTwoLetterIsoCode() : substr($language->getLocale(), 0, 2),
                    'flagIdentifier' => $language->getFlagIdentifier(),
                ],
            ];
        }

        $total = 0;
        foreach ($pages as $page) {
            $languageId = $page['sys_language_uid'] ?? 0;
            if (isset($languageStats[$languageId])) {
                $languageStats[$languageId]['count']++;
                $total++;
            }
        }

        // Computed here rather than in the template: Fluid evaluates a single binary
        // operation per expression, so the chained {count / total * 100} the templates
        // used silently produced an empty value and every row displayed 0.0%.
        foreach ($languageStats as $languageId => $stat) {
            $languageStats[$languageId]['percentage'] = $total > 0
                ? $stat['count'] / $total * 100
                : 0.0;
        }

        return ['statistics' => $languageStats];
    }

    public function getSiteLanguages(int $pageId): array
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageId);
            return $site->getLanguages();
        } catch (\TYPO3\CMS\Core\Exception\SiteNotFoundException $e) {
            $this->logger->warning('No site found for page ID ' . $pageId, ['exception' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Récupère toutes les langues configurées dans l'instance TYPO3
     *
     * @return array Liste de toutes les langues disponibles
     */
    public function getAllLanguages(): array
    {
        $languages = [];
        $sites = $this->siteFinder->getAllSites();

        foreach ($sites as $site) {
            foreach ($site->getAllLanguages() as $language) {
                $languageId = $language->getLanguageId();
                $languages[$languageId] = [
                    'title' => $language->getTitle(),
                    'twoLetterIsoCode' => $language->getTwoLetterIsoCode(),
                    'flagIdentifier' => $language->getFlagIdentifier(),
                ];
            }
        }

        // Assurer la présence de la langue par défaut
        if (!isset($languages[0]) && !empty($sites)) {
            $siteKeys = array_keys($sites);
            $firstKey = reset($siteKeys);
            if ($firstKey !== false) {
                $defaultLanguage = $sites[$firstKey]->getDefaultLanguage();
                $languages[0] = [
                    'title' => $defaultLanguage->getTitle(),
                    'twoLetterIsoCode' => $defaultLanguage->getTwoLetterIsoCode(),
                    'flagIdentifier' => $defaultLanguage->getFlagIdentifier(),
                ];
            } else {
                // Fallback si aucun site n'est disponible
                $languages[0] = [
                    'title' => 'Default',
                    'twoLetterIsoCode' => 'en',
                    'flagIdentifier' => 'en',
                ];
            }
        }

        ksort($languages);
        return $languages;
    }

    /**
     * Récupère l'ID de la langue courante
     *
     * @return int ID de la langue courante
     */
    public function getCurrentLanguageUid(): int
    {
        return $this->context->getAspect('language')->getId();
    }
}