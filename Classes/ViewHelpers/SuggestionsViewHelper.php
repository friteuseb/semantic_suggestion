<?php
namespace TalanHdf\SemanticSuggestion\ViewHelpers;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use TalanHdf\SemanticSuggestion\Service\PageAnalysisService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Context\Context;

class SuggestionsViewHelper extends AbstractViewHelper
{
    protected ?PageAnalysisService $pageAnalysisService = null;
    protected ?FileRepository $fileRepository = null;
    protected ?Context $context = null;

    public function initializeArguments()
    {
        $this->registerArgument('pageUid', 'int', 'The UID of the current page', true);
        $this->registerArgument('parentPageId', 'int', 'The parent page ID', false, 0);
        $this->registerArgument('proximityThreshold', 'float', 'The proximity threshold', false, 0.3);
        $this->registerArgument('maxSuggestions', 'int', 'Maximum number of suggestions', false, 5);
        $this->registerArgument('depth', 'int', 'Depth of analysis', false, 0);
        $this->registerArgument('includeImages', 'bool', 'Include page images in suggestions', false, true);
        $this->registerArgument('excerptLength', 'int', 'Length of the excerpt', false, 150);
    }

    public function render()
    {
        $container = GeneralUtility::getContainer();
        $this->pageAnalysisService = $container->get(PageAnalysisService::class);
        $this->fileRepository = $container->get(FileRepository::class);
        $this->context = $container->get(Context::class);

        $currentPageId = $this->arguments['pageUid'];
        $parentPageId = $this->arguments['parentPageId'];
        $proximityThreshold = $this->arguments['proximityThreshold'];
        $maxSuggestions = $this->arguments['maxSuggestions'];
        $depth = $this->arguments['depth'];
        $includeImages = $this->arguments['includeImages'];
        $excerptLength = $this->arguments['excerptLength'];

        $analysisData = $this->pageAnalysisService->analyzePages($parentPageId, $depth);
        $analysisResults = $analysisData['results'] ?? [];

        $suggestions = $this->findSimilarPages($analysisResults, $currentPageId, $proximityThreshold, $maxSuggestions, $includeImages, $excerptLength);

        $this->templateVariableContainer->add('suggestions', $suggestions);
        $content = $this->renderChildren();
        $this->templateVariableContainer->remove('suggestions');

        return $content;
    }

    protected function findSimilarPages(array $analysisResults, int $currentPageId, float $threshold, int $maxSuggestions, bool $includeImages, int $excerptLength): array
    {
        $suggestions = [];
        $pageRepository = GeneralUtility::makeInstance(PageRepository::class);

        if (isset($analysisResults[$currentPageId]['similarities'])) {
            $similarities = $analysisResults[$currentPageId]['similarities'];
            arsort($similarities);
            foreach ($similarities as $pageId => $similarity) {
                if (count($suggestions) >= $maxSuggestions) break;
                if ($similarity['score'] < $threshold) continue;
                
                $pageData = $pageRepository->getPage($pageId);
                $suggestions[$pageId] = [
                    'similarity' => $similarity['score'],
                    'commonKeywords' => implode(', ', $similarity['commonKeywords']),
                    'relevance' => $similarity['relevance'],
                    'data' => $pageData,
                    'lastModified' => $this->formatDate($pageData['tstamp'] ?? $pageData['crdate'] ?? 0),
                    'excerpt' => $this->getExcerpt($pageData, $excerptLength),
                ];

                if ($includeImages) {
                    $suggestions[$pageId]['media'] = $this->getPageMedia($pageId);
                }
            }
        }
        return $suggestions;
    }

    protected function formatDate($timestamp)
    {
        $dateTime = new \DateTime('@' . $timestamp);
        $format = $GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] ?: 'd/m/Y';
        $format .= ' H:i';
        return $dateTime->format($format);
    }
    protected function getExcerpt($pageData, $length)
    {
        $content = $pageData['description'] ?? $pageData['abstract'] ?? '';
        return mb_substr(strip_tags($content), 0, $length) . (mb_strlen($content) > $length ? '...' : '');
    }

    protected function getPageMedia($pageId)
    {
        $fileObjects = $this->fileRepository->findByRelation('pages', 'media', $pageId);
        return !empty($fileObjects) ? $fileObjects[0] : null;
    }
}