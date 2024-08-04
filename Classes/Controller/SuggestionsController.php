<?php
namespace TalanHdf\SemanticSuggestion\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TalanHdf\SemanticSuggestion\Service\PageAnalysisService;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Log\LogManager;


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
        $parentPageId = (int)$this->settings['parentPageId'];
        $proximityThreshold = (float)($this->settings['proximityThreshold'] ?? 0.3);
        $maxSuggestions = (int)($this->settings['maxSuggestions'] ?? 5);
        $excerptLength = (int)($this->settings['excerptLength'] ?? 100);
        $depth = (int)($this->settings['recursive'] ?? 0);
        $currentPageId = $GLOBALS['TSFE']->id;
        $excludePages = GeneralUtility::intExplode(',', $this->settings['excludePages'] ?? '', true);
    
        $analysisData = $this->pageAnalysisService->analyzePages($parentPageId, $depth);
        $analysisResults = $analysisData['results'] ?? [];
    
        $suggestions = $this->findSimilarPages($analysisResults, $currentPageId, $proximityThreshold, $maxSuggestions, $excludePages);
    
        $this->view->assignMultiple([
            'currentPageTitle' => $analysisResults[$currentPageId]['title']['content'] ?? 'Current Page',
            'suggestions' => $suggestions,
            'analysisResults' => $analysisResults,
            'proximityThreshold' => $proximityThreshold,
            'maxSuggestions' => $maxSuggestions,
        ]);
    
        return $this->htmlResponse();
    }
    

    protected function prepareExcerpt(array $pageData, int $excerptLength): string
    {
        $logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
        $sources = GeneralUtility::trimExplode(',', $this->settings['excerptSources'] ?? 'bodytext,description,abstract', true);
        $logger->debug('Excerpt sources', ['sources' => $sources]);
        
        foreach ($sources as $source) {
            $logger->debug('Checking source', ['source' => $source]);
            if ($source === 'bodytext' && !empty($pageData['tt_content'])) {
                $content = implode(' ', $pageData['tt_content']);
                $logger->debug('Bodytext content', ['length' => strlen($content)]);
            } elseif (!empty($pageData[$source])) {
                $content = $pageData[$source];
                $logger->debug('Content from page data', ['source' => $source, 'length' => strlen($content)]);
            } else {
                $logger->debug('Source not found or empty', ['source' => $source]);
                continue;
            }
            
            if (!empty($content)) {
                $excerpt = mb_substr(strip_tags($content), 0, $excerptLength) . (mb_strlen($content) > $excerptLength ? '...' : '');
                $logger->debug('Excerpt prepared', ['length' => strlen($excerpt)]);
                return $excerpt;
            }
        }
        
        $logger->debug('No excerpt could be prepared');
        return '';
    }

    
    protected function findSimilarPages(array $analysisResults, int $currentPageId, float $threshold, int $maxSuggestions, array $excludePages): array
    {
        $logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
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
                
                $logger->debug('Suggestion prepared', [
                    'pageId' => $pageId,
                    'excerptLength' => strlen($excerpt),
                    'hasExcerpt' => !empty($excerpt)
                ]);
                
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

    protected function getPageLink(int $pageId): string
    {
        return $this->uriBuilder->reset()->setTargetPageUid($pageId)->buildFrontendUri();
    }

    protected function getPageMedia(int $pageId)
    {
        $fileObjects = $this->fileRepository->findByRelation('pages', 'media', $pageId);
        return !empty($fileObjects) ? $fileObjects[0] : null;
    }
    protected function getPageContents(int $pageId): array
    {
        $logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
        $contents = [];
    
        // Définissez les colPos que vous voulez récupérer
        $colPositions = [3, 8, 0, 9];
    
        foreach ($colPositions as $colPos) {
            $content = $this->getContentForColPos($pageId, $colPos);
            if (!empty($content)) {
                $contents[] = $content;
            }
        }
    
        $logger->debug('Page contents retrieved', [
            'pageId' => $pageId,
            'contentCount' => count($contents)
        ]);
    
        return $contents;
    }
    
    protected function getContentForColPos(int $pageId, int $colPos, int $languageUid = 0, int $slide = 0): string
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('tt_content');
        $constraints = [
            $queryBuilder->expr()->eq('colPos', $queryBuilder->createNamedParameter($colPos, \PDO::PARAM_INT)),
            $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageUid, \PDO::PARAM_INT)),
            $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT)),
            $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \PDO::PARAM_INT))
        ];
    
        // Gestion du "slide"
        $currentPageId = $pageId;
        $content = '';
        while ($currentPageId > 0 && empty($content) && $slide >= 0) {
            $constraints[] = $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($currentPageId, \PDO::PARAM_INT));
            
            $result = $queryBuilder
                ->select('*')
                ->from('tt_content')
                ->where(...$constraints)
                ->orderBy('sorting')
                ->execute()
                ->fetchAllAssociative();
    
            foreach ($result as $element) {
                $content .= $this->renderContentElement($element) . ' ';
            }
    
            // Si aucun contenu n'est trouvé et que le slide est activé, remontez d'un niveau
            if (empty($content) && $slide > 0) {
                $parentPage = $this->getParentPage($currentPageId);
                $currentPageId = $parentPage ? $parentPage['uid'] : 0;
                $slide--;
            } else {
                break;
            }
        }
    
        return trim($content);
    }
    
    protected function renderContentElement(array $element): string
    {
        // Simplification du rendu des éléments de contenu
        switch ($element['CType']) {
            case 'text':
            case 'textpic':
            case 'textmedia':
                return $element['bodytext'] ?? '';
            case 'header':
                return $element['header'] ?? '';
            case 'bullets':
                return $element['bodytext'] ?? '';
            case 'table':
                return $element['bodytext'] ?? '';
            case 'uploads':
                return $element['media'] ?? '';
            default:
                return $element['header'] ?? '';
        }
    }
    
    protected function getParentPage(int $pageId): ?array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        return $queryBuilder
            ->select('uid', 'pid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, \PDO::PARAM_INT))
            )
            ->execute()
            ->fetchAssociative();
    }

}