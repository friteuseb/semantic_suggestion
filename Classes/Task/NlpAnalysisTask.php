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

    // New configuration properties
    protected $parentPageId;
    protected $recursive;
    protected $excludePages;
    protected $proximityThreshold;
    protected $maxSuggestions;
    protected $excerptLength;
    protected $recencyWeight;
    protected $nlpWeight;

    public function __construct()
    {
        parent::__construct();
        $this->initializeServices();
        $this->loadDefaultConfiguration();
        $this->configurationManager = GeneralUtility::makeInstance(ConfigurationManagerInterface::class);
        $this->pageAnalysisService = GeneralUtility::makeInstance(\TalanHdf\SemanticSuggestion\Service\PageAnalysisService::class);
 

          // Initialize with default values
        $this->parentPageId = 0;
        $this->recursive = 0;
        $this->excludePages = '';  // Initialize with an empty string
        $this->proximityThreshold = 0.5;
        $this->maxSuggestions = 5;
        $this->excerptLength = 150;
        $this->recencyWeight = 0.2;
        $this->nlpWeight = 0.3;
    }

    protected function getTypoScriptConfiguration(): array
    {
        $fullTypoScript = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
        );
    
        return $fullTypoScript['plugin.']['tx_semanticsuggestion_suggestions.']['settings.'] ?? [];
    }

    
    protected function loadDefaultConfiguration()
    {
        $settings = $this->getSettings();
        $this->parentPageId = $settings['parentPageId'];
        $this->recursive = $settings['recursive'];
        $this->excludePages = $settings['excludePages'];
        $this->proximityThreshold = $settings['proximityThreshold'];
        $this->maxSuggestions = $settings['maxSuggestions'];
        $this->excerptLength = $settings['excerptLength'];
        $this->recencyWeight = $settings['recencyWeight'];
        $this->nlpWeight = $settings['nlpWeight'];
    }

    // Getters and setters for each property
    public function getParentPageId()
    {
        return $this->parentPageId;
    }

    public function setParentPageId($parentPageId)
    {
        $this->parentPageId = $parentPageId;
    }

    public function getRecursive()
    {
        return $this->recursive;
    }

    public function setRecursive($recursive)
    {
        $this->recursive = $recursive;
    }

    public function getExcludePages()
    {
        return $this->excludePages;
    }

    public function setExcludePages($excludePages)
    {
        $this->excludePages = $excludePages;
    }

    public function getProximityThreshold()
    {
        return $this->proximityThreshold;
    }

    public function setProximityThreshold($proximityThreshold)
    {
        $this->proximityThreshold = $proximityThreshold;
    }

    public function getMaxSuggestions()
    {
        return $this->maxSuggestions;
    }

    public function setMaxSuggestions($maxSuggestions)
    {
        $this->maxSuggestions = $maxSuggestions;
    }

    public function getExcerptLength()
    {
        return $this->excerptLength;
    }

    public function setExcerptLength($excerptLength)
    {
        $this->excerptLength = $excerptLength;
    }

    public function getRecencyWeight()
    {
        return $this->recencyWeight;
    }

    public function setRecencyWeight($recencyWeight)
    {
        $this->recencyWeight = $recencyWeight;
    }

    public function getNlpWeight()
    {
        return $this->nlpWeight;
    }

    public function setNlpWeight($nlpWeight)
    {
        $this->nlpWeight = $nlpWeight;
    }

    public function execute()
    {
        try {
            $config = $this->getTypoScriptConfiguration();
    
            $analysisData = $this->pageAnalysisService->analyzePages(
                (int)($config['parentPageId'] ?? 0),
                (int)($config['recursive'] ?? 0),
                GeneralUtility::intExplode(',', $config['excludePages'] ?? '', true),
                [
                    'proximityThreshold' => (float)($config['proximityThreshold'] ?? 0.5),
                    'maxSuggestions' => (int)($config['maxSuggestions'] ?? 5),
                    'excerptLength' => (int)($config['excerptLength'] ?? 150),
                    'recencyWeight' => (float)($config['recencyWeight'] ?? 0.2),
                    'nlpWeight' => (float)($config['nlpWeight'] ?? 0.3)
                ]
            );
    
            if (!isset($analysisData['results']) || !is_array($analysisData['results'])) {
                throw new \Exception('Aucun résultat d\'analyse valide n\'a été retourné.');
            }
    
            $totalPages = count($analysisData['results']);
            $this->initializeTaskProgress($totalPages);
    
            foreach ($analysisData['results'] as $pageId => $pageData) {
                if (isset($pageData['nlp'])) {
                    $this->storeNlpResults($pageId, $pageData['nlp']);
                }
                $this->updateTaskProgress($pageId);
            }
    
            $this->finalizeTaskProgress();
    
            $this->logger?->info('Analyse NLP terminée', [
                'totalPages' => $totalPages,
                'executionTime' => $analysisData['metrics']['executionTime'] ?? 0
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