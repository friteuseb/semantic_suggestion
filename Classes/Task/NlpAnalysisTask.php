<?php
namespace TalanHdf\SemanticSuggestion\Task;

use TYPO3\CMS\Scheduler\Task\AbstractTask;
use TalanHdf\SemanticSuggestion\Service\NlpService;
use TalanHdf\SemanticSuggestion\Service\PageAnalysisService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class NlpAnalysisTask extends AbstractTask
{
    public function execute()
    {
        $nlpService = GeneralUtility::makeInstance(NlpService::class);
        $pageAnalysisService = GeneralUtility::makeInstance(PageAnalysisService::class);
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_semanticsuggestion_nlp_results');

        $pages = $pageAnalysisService->getAllPages();

        foreach ($pages as $page) {
            $content = $pageAnalysisService->getPageContent($page['uid']);
            $nlpResults = $nlpService->analyzeContent($content);

            $connection->insert('tx_semanticsuggestion_nlp_results', [
                'page_uid' => $page['uid'],
                'sentiment' => $nlpResults['sentiment'],
                'keyphrases' => json_encode($nlpResults['keyphrases']),
                'category' => $nlpResults['category'],
                'named_entities' => json_encode($nlpResults['named_entities']),
                'readability_score' => $nlpResults['readability_score'],
                'tstamp' => time(),
                'crdate' => time(),
            ]);
        }

        return true;
    }
}