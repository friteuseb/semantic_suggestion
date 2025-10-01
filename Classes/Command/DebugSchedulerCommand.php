<?php

declare(strict_types=1);

namespace TalanHdf\SemanticSuggestion\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Core\Environment;
use TalanHdf\SemanticSuggestion\Service\PageAnalysisService;
use TalanHdf\SemanticSuggestion\Task\GenerateSimilaritiesTask;
use Cywolf\NlpTools\Service\LanguageDetectionService;
use Cywolf\NlpTools\Service\TextAnalysisService;
use Cywolf\NlpTools\Service\TextVectorizerService;

/**
 * ════════════════════════════════════════════════════════════════════════════════
 * CONFIGURATION - Modify these values to suit your production environment
 * ════════════════════════════════════════════════════════════════════════════════
 */
class DebugSchedulerCommand extends Command
{
    // ──────────────────────────────────────────────────────────────────────────────
    // DEFAULT CONFIGURATION (can be overridden by command-line options)
    // ──────────────────────────────────────────────────────────────────────────────

    /** @var int Starting page ID (root page of your site) */
    private const DEFAULT_START_PAGE_ID = 3;

    /** @var string Comma-separated list of page IDs to exclude */
    private const DEFAULT_EXCLUDE_PAGES = '';

    /** @var float Quality level threshold (0.0-1.0) */
    private const DEFAULT_QUALITY_LEVEL = 0.3;

    /** @var int Language ID to process (-1 for all languages) */
    private const DEFAULT_LANGUAGE_ID = -1;

    /** @var bool Enable detailed debug logging */
    private const ENABLE_DEBUG_LOGGING = true;

    /** @var string Log file path (relative to var/ directory) */
    private const LOG_FILE = 'log/semantic_debug.log';

    /** @var int Maximum number of pages to analyze in test mode */
    private const TEST_MODE_PAGE_LIMIT = 10;

    // ──────────────────────────────────────────────────────────────────────────────
    // END OF CONFIGURATION
    // ──────────────────────────────────────────────────────────────────────────────

    private ?string $logFilePath = null;
    /** @var resource|null */
    private $logFileHandle = null;
    protected function configure(): void
    {
        $this
            ->setDescription('Debug and monitor the semantic similarity generation task')
            ->addOption(
                'test',
                't',
                InputOption::VALUE_NONE,
                'Test mode: analyze only 10 pages'
            )
            ->addOption(
                'dry-run',
                'd',
                InputOption::VALUE_NONE,
                'Dry run: simulate without writing to database'
            )
            ->addOption(
                'start-page',
                's',
                InputOption::VALUE_REQUIRED,
                'Starting page ID (default: from config)',
                (string)self::DEFAULT_START_PAGE_ID
            )
            ->addOption(
                'exclude',
                'e',
                InputOption::VALUE_REQUIRED,
                'Comma-separated list of page IDs to exclude',
                self::DEFAULT_EXCLUDE_PAGES
            )
            ->addOption(
                'quality',
                null,
                InputOption::VALUE_REQUIRED,
                'Quality level threshold (0.0-1.0)',
                (string)self::DEFAULT_QUALITY_LEVEL
            )
            ->addOption(
                'language',
                'l',
                InputOption::VALUE_REQUIRED,
                'Language ID to process (-1 for all)',
                (string)self::DEFAULT_LANGUAGE_ID
            )
            ->addOption(
                'watch',
                'w',
                InputOption::VALUE_NONE,
                'Watch mode: show real-time log output'
            )
            ->addOption(
                'stats-only',
                null,
                InputOption::VALUE_NONE,
                'Show only final statistics without detailed progress'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🔍 Semantic Similarity Task Debugger & Executor');

        $testMode = $input->getOption('test');
        $dryRun = $input->getOption('dry-run');
        $watchMode = $input->getOption('watch');
        $statsOnly = $input->getOption('stats-only');
        $startPageId = (int)$input->getOption('start-page');
        $excludePages = $input->getOption('exclude');
        $qualityLevel = (float)$input->getOption('quality');
        $languageId = (int)$input->getOption('language');

        // Initialize log file
        $this->initializeLogFile($io);

        if ($testMode) {
            $io->note('TEST MODE: Will analyze only 10 pages');
        }
        if ($dryRun) {
            $io->note('DRY RUN: No database writes will be performed');
        }

        // ===== SECTION 1: System Environment Checks =====
        $io->section('1️⃣ System Environment Checks');

        if (!$this->checkSystemEnvironment($io)) {
            return Command::FAILURE;
        }

        // ===== SECTION 2: PHP Extensions & Locales =====
        $io->section('2️⃣ PHP Extensions & Locales');

        if (!$this->checkPhpExtensions($io)) {
            return Command::FAILURE;
        }

        $this->checkLocales($io);

        // ===== SECTION 3: NLP Tools Services =====
        $io->section('3️⃣ NLP Tools Services');

        $nlpServices = $this->checkNlpServices($io);
        if (!$nlpServices) {
            return Command::FAILURE;
        }

        // ===== SECTION 4: Database & Cache =====
        $io->section('4️⃣ Database & Cache Status');

        $dbStatus = $this->checkDatabaseAndCache($io);
        if (!$dbStatus) {
            return Command::FAILURE;
        }

        // ===== SECTION 5: Task Configuration =====
        $io->section('5️⃣ Task Configuration');

        $this->displayTaskConfiguration($io, $startPageId, $excludePages, $qualityLevel, $languageId);

        // ===== SECTION 6: Pages Analysis =====
        $io->section('6️⃣ Pages to Analyze');

        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $site = $siteFinder->getSiteByPageId($startPageId);

        $languagesToProcess = [];
        if ($languageId >= 0) {
            try {
                $specificLanguage = $site->getLanguageById($languageId);
                $languagesToProcess = [$specificLanguage];
            } catch (\Exception $e) {
                $io->error("Language ID $languageId not found in site configuration");
                return Command::FAILURE;
            }
        } else {
            $languagesToProcess = $site->getAllLanguages();
        }

        $io->writeln("📚 Languages to process: " . count($languagesToProcess));

        $totalPages = 0;
        $pagesByLanguage = [];

        foreach ($languagesToProcess as $language) {
            $langId = $language->getLanguageId();
            $langCode = substr($language->getHreflang(), 0, 2);

            // Get pages count
            $pages = $this->getPages($startPageId, $langId, $excludePages);
            $pagesCount = count($pages);
            $totalPages += $pagesCount;
            $pagesByLanguage[$langId] = [
                'code' => $langCode,
                'count' => $pagesCount,
                'pages' => $pages
            ];

            $io->writeln("  - Language $langCode (ID: $langId): $pagesCount pages");
        }

        $io->writeln("\n📊 Total pages to analyze: $totalPages");
        $totalComparisons = 0;
        foreach ($pagesByLanguage as $langData) {
            $n = $langData['count'];
            $comparisons = $n * ($n - 1); // n×(n-1) comparaisons par langue
            $totalComparisons += $comparisons;
        }
        $io->writeln("🔢 Total similarity calculations: " . number_format($totalComparisons));

        $estimatedTimePerComparison = 0.01; // 10ms par comparaison (estimation)
        $estimatedTotalTime = $totalComparisons * $estimatedTimePerComparison;
        $io->writeln("⏱️  Estimated total time: " . $this->formatDuration($estimatedTotalTime));

        if ($totalPages === 0) {
            $io->warning('No pages found to analyze!');
            return Command::SUCCESS;
        }

        // ===== SECTION 7: Performance Test =====
        $io->section('7️⃣ Performance Test');

        if (!$this->performanceTest($io, $nlpServices, $pagesByLanguage)) {
            $io->warning('Performance test failed, but continuing...');
        }

        // ===== SECTION 8: Execute Analysis =====
        if (!$testMode && !$dryRun) {
            $io->section('8️⃣ Execute Full Analysis');

            if (!$io->confirm('Do you want to proceed with the full analysis?', false)) {
                $io->note('Analysis cancelled by user');
                return Command::SUCCESS;
            }
        } else {
            $io->section('8️⃣ Simulated Analysis (Test/Dry-Run Mode)');
        }

        $this->executeAnalysis($io, $pagesByLanguage, $startPageId, $qualityLevel, $testMode, $dryRun, $nlpServices);

        $io->success('✅ Debug analysis completed successfully!');
        return Command::SUCCESS;
    }

    protected function checkSystemEnvironment(SymfonyStyle $io): bool
    {
        $io->writeln('📋 PHP Version: ' . phpversion());

        $memoryLimit = ini_get('memory_limit');
        $io->writeln('💾 Memory Limit: ' . $memoryLimit);

        $maxExecutionTime = ini_get('max_execution_time');
        $io->writeln('⏱️  Max Execution Time: ' . ($maxExecutionTime === '0' ? 'Unlimited' : $maxExecutionTime . 's'));

        // Check if running in CLI
        if (php_sapi_name() !== 'cli') {
            $io->warning('Not running in CLI mode. This may cause issues with long-running tasks.');
        } else {
            $io->success('✅ Running in CLI mode');
        }

        return true;
    }

    protected function checkPhpExtensions(SymfonyStyle $io): bool
    {
        $requiredExtensions = [
            'mbstring' => 'Multi-byte string support',
            'intl' => 'Internationalization support',
            'iconv' => 'Character encoding conversion',
            'json' => 'JSON processing',
            'pdo' => 'Database access',
            'tokenizer' => 'Text tokenization',
        ];

        $missing = [];
        foreach ($requiredExtensions as $ext => $description) {
            if (extension_loaded($ext)) {
                $io->writeln("✅ $ext ($description)");
            } else {
                $io->writeln("❌ $ext ($description) - MISSING!");
                $missing[] = $ext;
            }
        }

        if (!empty($missing)) {
            $io->error('Missing required PHP extensions: ' . implode(', ', $missing));
            return false;
        }

        return true;
    }

    protected function checkLocales(SymfonyStyle $io): void
    {
        $io->writeln("\n🌍 Installed Locales:");

        $requiredLocales = [
            'fr_FR.UTF-8' => 'French',
            'de_DE.UTF-8' => 'German',
            'en_US.UTF-8' => 'English',
        ];

        exec('locale -a', $installedLocales);

        foreach ($requiredLocales as $locale => $language) {
            $installed = false;
            foreach ($installedLocales as $installedLocale) {
                if (stripos($installedLocale, str_replace('.UTF-8', '', $locale)) !== false) {
                    $installed = true;
                    break;
                }
            }

            if ($installed) {
                $io->writeln("✅ $language ($locale)");
            } else {
                $io->writeln("⚠️  $language ($locale) - Not installed (may affect text processing)");
            }
        }

        $currentLocale = setlocale(LC_ALL, 0);
        $io->writeln("\n📍 Current locale: $currentLocale");
    }

    protected function checkNlpServices(SymfonyStyle $io): array
    {
        $services = [];

        try {
            $services['languageDetector'] = GeneralUtility::makeInstance(LanguageDetectionService::class);
            $io->writeln('✅ LanguageDetectionService');
        } catch (\Exception $e) {
            $io->error('❌ LanguageDetectionService: ' . $e->getMessage());
            return [];
        }

        try {
            $services['textAnalyzer'] = GeneralUtility::makeInstance(TextAnalysisService::class);
            $io->writeln('✅ TextAnalysisService');
        } catch (\Exception $e) {
            $io->error('❌ TextAnalysisService: ' . $e->getMessage());
            return [];
        }

        try {
            $services['textVectorizer'] = GeneralUtility::makeInstance(TextVectorizerService::class);
            $io->writeln('✅ TextVectorizerService');
        } catch (\Exception $e) {
            $io->error('❌ TextVectorizerService: ' . $e->getMessage());
            return [];
        }

        return $services;
    }

    protected function checkDatabaseAndCache(SymfonyStyle $io): bool
    {
        // Database check
        try {
            $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
            $connection = $connectionPool->getConnectionForTable('tx_semanticsuggestion_similarities');
            $io->writeln('✅ Database connection: OK');

            // Check if table exists
            $schemaManager = $connection->createSchemaManager();
            $tables = $schemaManager->listTableNames();

            if (in_array('tx_semanticsuggestion_similarities', $tables)) {
                // Count existing similarities
                $queryBuilder = $connectionPool->getQueryBuilderForTable('tx_semanticsuggestion_similarities');
                $count = $queryBuilder
                    ->count('*')
                    ->from('tx_semanticsuggestion_similarities')
                    ->executeQuery()
                    ->fetchOne();

                $io->writeln("✅ Table 'tx_semanticsuggestion_similarities' exists ($count records)");
            } else {
                $io->error("❌ Table 'tx_semanticsuggestion_similarities' does not exist!");
                return false;
            }
        } catch (\Exception $e) {
            $io->error('❌ Database error: ' . $e->getMessage());
            return false;
        }

        // Cache check
        try {
            $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
            $cache = $cacheManager->getCache('semantic_suggestion');
            $io->writeln('✅ Cache "semantic_suggestion": Available');
        } catch (\Exception $e) {
            $io->warning('⚠️  Cache "semantic_suggestion" not available: ' . $e->getMessage());
        }

        return true;
    }

    protected function displayTaskConfiguration(SymfonyStyle $io, int $startPageId, string $excludePages, float $qualityLevel, int $languageId): void
    {
        $io->writeln("📄 Start Page ID: $startPageId");
        $io->writeln("🚫 Excluded Pages: " . ($excludePages ?: 'None'));
        $io->writeln("🎯 Quality Level: $qualityLevel");
        $io->writeln("💾 Storage Threshold: $qualityLevel");
        $io->writeln("🌍 Language Filter: " . ($languageId >= 0 ? "ID $languageId" : 'All languages'));
    }

    protected function getPages(int $startPageId, int $languageId, string $excludePages): array
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable('pages');

        $excludeArray = !empty($excludePages)
            ? GeneralUtility::intExplode(',', $excludePages, true)
            : [];

        $query = $queryBuilder
            ->select('uid', 'pid', 'title', 'sys_language_uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER))
            );

        if (!empty($excludeArray)) {
            $query->andWhere(
                $queryBuilder->expr()->notIn('uid', $queryBuilder->createNamedParameter($excludeArray, \Doctrine\DBAL\Connection::PARAM_INT_ARRAY))
            );
        }

        return $query->executeQuery()->fetchAllAssociative();
    }

    protected function performanceTest(SymfonyStyle $io, array $nlpServices, array $pagesByLanguage): bool
    {
        $io->writeln('🧪 Testing performance with sample data...');

        $testTexts = [
            'fr' => 'La technologie moderne transforme notre façon de travailler et de communiquer avec les autres personnes.',
            'de' => 'Die moderne Technologie verändert unsere Art zu arbeiten und mit anderen Menschen zu kommunizieren.',
            'en' => 'Modern technology transforms the way we work and communicate with other people.',
        ];

        foreach ($testTexts as $lang => $text) {
            $startTime = microtime(true);

            try {
                // Test language detection
                $detectedLang = $nlpServices['languageDetector']->detectLanguage($text);

                // Test text processing
                $processed = $nlpServices['textAnalyzer']->removeStopWords($text, $lang);
                $stemmed = $nlpServices['textAnalyzer']->stem($processed, $lang);

                $duration = (microtime(true) - $startTime) * 1000;
                $status = $detectedLang === $lang ? '✅' : '⚠️';

                $io->writeln("  $status $lang: " . round($duration, 2) . "ms (detected: $detectedLang)");
            } catch (\Exception $e) {
                $io->error("  ❌ $lang: " . $e->getMessage());
                return false;
            }
        }

        // Test TF-IDF
        $io->writeln("\n🧪 Testing TF-IDF vectorization...");
        $startTime = microtime(true);

        try {
            $tfidfResult = $nlpServices['textVectorizer']->createTfIdfVectors(array_values($testTexts), 'en');
            $duration = (microtime(true) - $startTime) * 1000;

            $io->writeln("  ✅ TF-IDF: " . round($duration, 2) . "ms");
            $io->writeln("  📊 Vocabulary size: " . count($tfidfResult['vocabulary']));

            if (count($tfidfResult['vectors']) >= 2) {
                $similarity = $nlpServices['textVectorizer']->cosineSimilarity(
                    $tfidfResult['vectors'][0],
                    $tfidfResult['vectors'][1]
                );
                $io->writeln("  🔗 Sample similarity: " . round($similarity, 4));
            }
        } catch (\Exception $e) {
            $io->error("  ❌ TF-IDF: " . $e->getMessage());
            return false;
        }

        return true;
    }

    protected function executeAnalysis(
        SymfonyStyle $io,
        array $pagesByLanguage,
        int $startPageId,
        float $qualityLevel,
        bool $testMode,
        bool $dryRun,
        array $nlpServices
    ): void {
        $overallStartTime = microtime(true);
        $totalPagesProcessed = 0;
        $totalComparisons = 0;
        $totalSimilaritiesSaved = 0;

        $this->log("═══════════════════════════════════════════════════════════");
        $this->log("Starting semantic similarity analysis");
        $this->log("Mode: " . ($testMode ? 'TEST' : 'FULL') . ($dryRun ? ' (DRY RUN)' : ''));
        $this->log("═══════════════════════════════════════════════════════════");

        foreach ($pagesByLanguage as $langId => $langData) {
            $langCode = $langData['code'];
            $pages = $langData['pages'];
            $pageCount = count($pages);

            if ($testMode && $pageCount > self::TEST_MODE_PAGE_LIMIT) {
                $pages = array_slice($pages, 0, self::TEST_MODE_PAGE_LIMIT);
                $pageCount = self::TEST_MODE_PAGE_LIMIT;
            }

            $io->section("Processing language: $langCode (ID: $langId) - $pageCount pages");
            $this->log("─────────────────────────────────────────────────────────");
            $this->log("Language: $langCode (ID: $langId) - $pageCount pages");

            $langStartTime = microtime(true);

            // Calculate total comparisons for progress bar
            $totalComparisons = $pageCount * ($pageCount - 1);
            $progressBar = $io->createProgressBar($totalComparisons);
            $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message% %elapsed:6s%/%estimated:-6s% %memory:6s%');
            $progressBar->setMessage('Starting...');

            // Initialize PageAnalysisService with error handling for CLI context
            try {
                $pageAnalysisService = GeneralUtility::makeInstance(PageAnalysisService::class);

                // Set progress callback to update the progress bar in real-time
                $pageAnalysisService->setProgressCallback(function($current, $total, $message) use ($progressBar) {
                    $progressBar->setProgress($current);
                    $progressBar->setMessage($message);
                });
            } catch (\Exception $e) {
                // If PageAnalysisService fails to initialize, skip this language
                $io->error("Failed to initialize PageAnalysisService: " . $e->getMessage());
                $this->log("ERROR initializing PageAnalysisService: " . $e->getMessage());
                continue;
            }

            // ═══ REAL EXECUTION ═══
            try {
                $this->log("Starting PageAnalysisService->analyzePages() for $pageCount pages");

                // Prepare pages array - filter to only what we need
                $pagesForAnalysis = [];
                foreach ($pages as $page) {
                    $pagesForAnalysis[$page['uid']] = $page;
                }

                $progressBar->start();

                // Call the real analysis service
                $analysisData = $pageAnalysisService->analyzePages($pagesForAnalysis, $langId);

                $progressBar->finish();
                $io->newLine(2);

                $comparisonsCalculated = $analysisData['metrics']['similarityCalculations'] ?? 0;
                $totalComparisons += $comparisonsCalculated;
                $totalPagesProcessed += $pageCount;

                $this->log("Analysis completed: $comparisonsCalculated comparisons calculated");

                // Save results if not in dry-run mode
                if (!$dryRun) {
                    $this->log("Saving results to database...");
                    $saveStartTime = microtime(true);

                    $similaritiesSaved = $this->saveResults(
                        $analysisData,
                        $startPageId,
                        $langId,
                        $qualityLevel
                    );

                    $saveDuration = microtime(true) - $saveStartTime;
                    $totalSimilaritiesSaved += $similaritiesSaved;

                    $this->log("Saved $similaritiesSaved similarities in " . round($saveDuration, 2) . "s");
                } else {
                    $this->log("DRY RUN: Skipping database save");
                }

                $langDuration = microtime(true) - $langStartTime;
                $avgTimePerPage = $pageCount > 0 ? $langDuration / $pageCount : 0;

                $io->writeln("✅ Language $langCode completed in " . $this->formatDuration($langDuration));
                $io->writeln("   ⏱️  Avg time per page: " . round($avgTimePerPage * 1000, 2) . "ms");
                $io->writeln("   🔢 Comparisons calculated: " . number_format($comparisonsCalculated));

                if (!$dryRun) {
                    $io->writeln("   💾 Similarities saved: " . number_format($similaritiesSaved));
                }

                $this->log("Language $langCode completed - Duration: " . round($langDuration, 2) . "s");

            } catch (\Exception $e) {
                $progressBar->finish();
                $io->newLine(2);
                $io->error("Error processing language $langCode: " . $e->getMessage());
                $this->log("ERROR: " . $e->getMessage());
                $this->log("Stack trace: " . $e->getTraceAsString());
            }
        }

        $overallDuration = microtime(true) - $overallStartTime;

        $io->newLine();
        $io->section('📊 Final Statistics');
        $io->writeln("⏱️  Total execution time: " . $this->formatDuration($overallDuration));
        $io->writeln("📄 Pages processed: $totalPagesProcessed");
        $io->writeln("🔢 Total comparisons: " . number_format($totalComparisons));
        if (!$dryRun) {
            $io->writeln("💾 Similarities saved: " . number_format($totalSimilaritiesSaved));
        }
        $io->writeln("💾 Peak memory usage: " . $this->formatBytes(memory_get_peak_usage(true)));

        $this->log("═══════════════════════════════════════════════════════════");
        $this->log("Analysis complete - Total time: " . round($overallDuration, 2) . "s");
        $this->log("Pages: $totalPagesProcessed | Comparisons: $totalComparisons | Saved: $totalSimilaritiesSaved");
        $this->log("═══════════════════════════════════════════════════════════");

        $this->closeLogFile();
    }

    protected function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds, 2) . 's';
        } elseif ($seconds < 3600) {
            $minutes = floor($seconds / 60);
            $secs = $seconds % 60;
            return $minutes . 'm ' . round($secs) . 's';
        } else {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            return $hours . 'h ' . $minutes . 'm';
        }
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }

    protected function initializeLogFile(SymfonyStyle $io): void
    {
        if (!self::ENABLE_DEBUG_LOGGING) {
            return;
        }

        try {
            $this->logFilePath = Environment::getVarPath() . '/' . self::LOG_FILE;
            $logDir = dirname($this->logFilePath);

            // Create log directory if it doesn't exist
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }

            $this->logFileHandle = fopen($this->logFilePath, 'a');

            if ($this->logFileHandle === false) {
                $io->warning("Could not open log file: {$this->logFilePath}");
                return;
            }

            $io->writeln("📝 Logging to: {$this->logFilePath}");
            $this->log(""); // Empty line
            $this->log("╔═══════════════════════════════════════════════════════════╗");
            $this->log("║  NEW EXECUTION - " . date('Y-m-d H:i:s') . "                    ║");
            $this->log("╚═══════════════════════════════════════════════════════════╝");
        } catch (\Exception $e) {
            $io->warning("Error initializing log file: " . $e->getMessage());
        }
    }

    protected function log(string $message): void
    {
        if (!self::ENABLE_DEBUG_LOGGING || $this->logFileHandle === null) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $memoryUsage = round(memory_get_usage(true) / 1024 / 1024, 2);
        $logLine = "[{$timestamp}] [{$memoryUsage}MB] {$message}\n";

        fwrite($this->logFileHandle, $logLine);
        fflush($this->logFileHandle);
    }

    protected function closeLogFile(): void
    {
        if ($this->logFileHandle !== null) {
            $this->log(""); // Empty line
            fclose($this->logFileHandle);
            $this->logFileHandle = null;
        }
    }

    protected function saveResults(array $analysisData, int $rootPageId, int $languageId, float $proximityThreshold): int
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $connection = $connectionPool->getConnectionForTable('tx_semanticsuggestion_similarities');

        $savedCount = 0;

        try {
            // Begin transaction
            $connection->beginTransaction();

            // Delete old entries for this site and language
            $queryBuilder = $connectionPool->getQueryBuilderForTable('tx_semanticsuggestion_similarities');

            $queryBuilder
                ->delete('tx_semanticsuggestion_similarities')
                ->where(
                    $queryBuilder->expr()->eq('root_page_id', $queryBuilder->createNamedParameter($rootPageId)),
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageId))
                )
                ->executeStatement();

            $this->log("Deleted old similarities for root_page_id=$rootPageId, language=$languageId");

            // Prepare bulk insertions
            $bulkInserts = [];
            $now = time();

            foreach ($analysisData['results'] as $pageId => $pageData) {
                if (!isset($pageData['similarities']) || !is_array($pageData['similarities'])) {
                    continue;
                }

                foreach ($pageData['similarities'] as $similarPageId => $similarity) {
                    // Only store similarities above threshold
                    if ($similarity['score'] >= $proximityThreshold) {
                        $bulkInserts[] = [
                            'page_id' => $pageId,
                            'similar_page_id' => $similarPageId,
                            'similarity_score' => $similarity['score'],
                            'root_page_id' => $rootPageId,
                            'sys_language_uid' => $languageId,
                            'crdate' => $now,
                            'tstamp' => $now
                        ];
                    }

                    // Insert in batches to optimize performance
                    if (count($bulkInserts) >= 100) {
                        $this->bulkInsert($bulkInserts);
                        $savedCount += count($bulkInserts);
                        $bulkInserts = [];
                    }
                }
            }

            // Insert remaining records
            if (!empty($bulkInserts)) {
                $this->bulkInsert($bulkInserts);
                $savedCount += count($bulkInserts);
            }

            // Commit transaction
            $connection->commit();

            $this->log("Successfully saved $savedCount similarities");

            // Clear cache for this site
            try {
                $cacheManager = GeneralUtility::makeInstance(CacheManager::class);
                $cache = $cacheManager->getCache('semantic_suggestion');
                $cache->flushByTag('site_' . $rootPageId);
                $this->log("Cache flushed for site_$rootPageId");
            } catch (\Exception $e) {
                $this->log("Warning: Could not flush cache: " . $e->getMessage());
            }

            return $savedCount;

        } catch (\Exception $e) {
            // Rollback transaction on error
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            $this->log("ERROR saving results: " . $e->getMessage());
            throw $e;
        }
    }

    protected function bulkInsert(array $records): void
    {
        if (empty($records)) {
            return;
        }

        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $connection = $connectionPool->getConnectionForTable('tx_semanticsuggestion_similarities');

        // Use bulkInsert for better performance
        $connection->bulkInsert(
            'tx_semanticsuggestion_similarities',
            $records,
            ['page_id', 'similar_page_id', 'similarity_score', 'root_page_id', 'sys_language_uid', 'crdate', 'tstamp']
        );
    }
}
