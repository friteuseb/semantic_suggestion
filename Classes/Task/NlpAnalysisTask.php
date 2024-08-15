<?php
namespace TalanHdf\SemanticSuggestion\Task;

use TYPO3\CMS\Scheduler\Task\AbstractTask;
use TalanHdf\SemanticSuggestion\Service\NlpService;
use TalanHdf\SemanticSuggestion\Service\PageAnalysisService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Log\LogManager;

class NlpAnalysisTask extends AbstractTask
{
    protected $configurationManager;
    protected $nlpService;
    protected $pageAnalysisService;
    protected ?LoggerInterface $logger = null;

    public function __construct()
    {
        parent::__construct();
        $this->initializeServices();
    }

    public function execute()
    {
        try {
            $this->initializeServices();
            $settings = $this->getSettings();
    
            $analysisData = $this->pageAnalysisService->analyzePages(
                $settings['parentPageId'],
                $settings['recursive'],
                $settings['excludePages']
            );
    
            if (!isset($analysisData['results']) || !is_array($analysisData['results'])) {
                throw new \Exception('Aucun résultat d\'analyse valide n\'a été retourné.');
            }
    
            $totalPages = count($analysisData['results']);
            $this->initializeTaskProgress($totalPages);
    
            $processedPages = 0;
            foreach ($analysisData['results'] as $pageId => $pageData) {
                if (isset($pageData['nlp'])) {
                    $this->storeNlpResults($pageId, $pageData['nlp']);
                }
                $processedPages++;
                $this->updateTaskProgress($processedPages);
            }
    
            $this->finalizeTaskProgress();
    
            $this->logger?->info('Analyse NLP terminée', [
                'totalPages' => $totalPages,
                'executionTime' => $analysisData['metrics']['executionTime']
            ]);
    
            return true;
        } catch (\Exception $e) {
            $this->finalizeTaskProgress('error');
            $this->logger?->error('Erreur lors de l\'exécution de la tâche NLP : ' . $e->getMessage(), ['exception' => $e]);
            return false;
        }
    }
    
    protected function updateProgress($current, $total)
    {
        $progress = ($current / $total) * 100;
        $this->logger?->info("Progression de l'analyse NLP", [
            'current' => $current,
            'total' => $total,
            'percentage' => round($progress, 2)
        ]);
        // Vous pouvez également stocker cette progression dans la base de données si nécessaire
    }

    protected function initializeServices()
    {
        $this->configurationManager = GeneralUtility::makeInstance(ConfigurationManagerInterface::class);
        $this->nlpService = GeneralUtility::makeInstance(NlpService::class);
        $this->pageAnalysisService = GeneralUtility::makeInstance(PageAnalysisService::class);
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
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
            'proximityThreshold' => (float)($typoscriptSettings['proximityThreshold'] ?? 0.5),
            'maxSuggestions' => (int)($typoscriptSettings['maxSuggestions'] ?? 5),
            'excerptLength' => (int)($typoscriptSettings['excerptLength'] ?? 150),
            'recencyWeight' => (float)($typoscriptSettings['recencyWeight'] ?? 0.2),
            'nlpWeight' => (float)($typoscriptSettings['nlpWeight'] ?? 0.3),
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


    protected function initializeTaskProgress($totalPages)
{
    $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_semanticsuggestion_nlp_task_progress');
    $connection->insert('tx_semanticsuggestion_nlp_task_progress', [
        'task_id' => $this->taskUid,
        'total_pages' => $totalPages,
        'processed_pages' => 0,
        'status' => 'running',
        'start_time' => time(),
        'last_update' => time(),
    ]);
}

protected function updateTaskProgress($processedPages)
{
    $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_semanticsuggestion_nlp_task_progress');
    $connection->update(
        'tx_semanticsuggestion_nlp_task_progress',
        [
            'processed_pages' => $processedPages,
            'last_update' => time(),
        ],
        ['task_id' => $this->taskUid]
    );
}

protected function finalizeTaskProgress($status = 'completed')
{
    $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_semanticsuggestion_nlp_task_progress');
    $connection->update(
        'tx_semanticsuggestion_nlp_task_progress',
        ['status' => $status],
        ['task_id' => $this->taskUid]
    );
}



    public function getTaskTitle(): string
    {
        return 'Analyse NLP des pages';
    }

    public function getTaskDescription(): string
    {
        return 'Effectue une analyse NLP sur toutes les pages configurées';
    }

    public function __sleep()
    {
        return ['taskUid', 'disabled', 'description', 'execution'];
    }

    public function __wakeup()
    {
        $this->initializeServices();
    }
}