<?php
namespace TalanHdf\SemanticSuggestion\Task;

use TYPO3\CMS\Scheduler\Task\AbstractTask;
use TalanHdf\SemanticSuggestion\Service\NlpService;
use TalanHdf\SemanticSuggestion\Service\PageAnalysisService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;

class NlpAnalysisTask extends AbstractTask
{
    protected $configurationManager;
    protected $nlpService;
    protected $pageAnalysisService;

    public function __construct()
    {
        parent::__construct();
    }

    public function execute()
    {
        $this->initializeServices();

        $settings = $this->getSettings();

        $pages = $this->pageAnalysisService->getAllPages(
            $settings['parentPageId'],
            $settings['recursive'],
            $settings['excludePages']
        );

        foreach ($pages as $page) {
            $content = $this->pageAnalysisService->getPageContent($page['uid']);
            $nlpResults = $this->nlpService->analyzeContent($content);

            $this->storeNlpResults($page['uid'], $nlpResults);
        }

        return true;
    }

    protected function initializeServices()
    {
        $this->configurationManager = GeneralUtility::makeInstance(ConfigurationManagerInterface::class);
        $this->nlpService = GeneralUtility::makeInstance(NlpService::class);
        $this->pageAnalysisService = GeneralUtility::makeInstance(PageAnalysisService::class);
    }

    protected function getSettings(): array
    {
        $typoscriptSettings = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS,
            'SemanticSuggestion'
        );

        return [
            'parentPageId' => (int)($typoscriptSettings['parentPageId'] ?? 0),
            'recursive' => (int)($typoscriptSettings['recursive'] ?? 0),
            'excludePages' => GeneralUtility::intExplode(',', $typoscriptSettings['excludePages'] ?? '', true),
        ];
    }

    protected function storeNlpResults(int $pageUid, array $nlpResults)
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_semanticsuggestion_nlp_results');

        $data = [
            'page_uid' => $pageUid,
            'sentiment' => $nlpResults['sentiment'] ?? '',
            'keyphrases' => json_encode($nlpResults['keyphrases'] ?? []),
            'category' => $nlpResults['category'] ?? '',
            'named_entities' => json_encode($nlpResults['named_entities'] ?? []),
            'readability_score' => $nlpResults['readability_score'] ?? 0.0,
            'tstamp' => time()
        ];

        $existingRecord = $connection->select(['uid'], 'tx_semanticsuggestion_nlp_results', ['page_uid' => $pageUid])->fetch();

        if ($existingRecord) {
            $connection->update('tx_semanticsuggestion_nlp_results', $data, ['uid' => $existingRecord['uid']]);
        } else {
            $data['crdate'] = time();
            $connection->insert('tx_semanticsuggestion_nlp_results', $data);
        }
    }

    public function __sleep()
    {
        return ['taskUid', 'disabled', 'description', 'execution', 'type'];
    }

    public function __wakeup()
    {
        $this->initializeServices();
    }
}