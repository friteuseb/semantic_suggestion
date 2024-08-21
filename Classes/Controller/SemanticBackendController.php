<?php
namespace TalanHdf\SemanticSuggestion\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TalanHdf\SemanticSuggestion\Service\PageAnalysisService;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Imaging\GraphicalFunctions;


class SemanticBackendController extends ActionController
{
    protected $moduleTemplateFactory;
    protected $pageAnalysisService;
    protected $configurationManager;

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory, 
        PageAnalysisService $pageAnalysisService,
        ConfigurationManagerInterface $configurationManager
    ) {
        $this->moduleTemplateFactory = $moduleTemplateFactory;
        $this->pageAnalysisService = $pageAnalysisService;
        $this->configurationManager = $configurationManager;
    }

    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
    
        $fullTypoScript = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
        );
    
        $extensionConfig = $fullTypoScript['plugin.']['tx_semanticsuggestion_suggestions.']['settings.'] ?? [];
    
        $parentPageId = (int)($extensionConfig['parentPageId'] ?? 0);
        $depth = (int)($extensionConfig['recursive'] ?? 1);
        $proximityThreshold = (float)($extensionConfig['proximityThreshold'] ?? 0.5);
        $maxSuggestions = (int)($extensionConfig['maxSuggestions'] ?? 5);
        $excludePages = GeneralUtility::intExplode(',', $extensionConfig['excludePages'] ?? '', true);
    
        $analysisData = $this->pageAnalysisService->analyzePages($parentPageId, $depth);
    
        $analysisResults = [];
        $performanceMetrics = [];
        $statistics = [];
        $languageDistributionChart = '';
        $topNGramsTable = '';
        $recencyBoostChart = '';
    
        if (is_array($analysisData) && isset($analysisData['results']) && is_array($analysisData['results'])) {
            $analysisResults = $analysisData['results'];
            
            if (!empty($excludePages)) {
                $analysisResults = array_diff_key($analysisResults, array_flip($excludePages));
            }
    
            $statistics = $this->calculateStatistics($analysisResults, $proximityThreshold);
            $languageDistributionChart = $this->createLanguageDistributionChart($analysisResults);
            $topNGramsTable = $this->getTopNGrams($analysisResults);
            $recencyBoostChart = $this->createRecencyBoostChart($analysisResults);
        } else {
            $this->addFlashMessage(
                'The analysis did not return valid results. Please check your configuration and try again.',
                'Analysis Error',
                \TYPO3\CMS\Core\Messaging\AbstractMessage::ERROR
            );
        }
    
        if (isset($analysisData['metrics']) && is_array($analysisData['metrics'])) {
            $performanceMetrics = [
                'executionTime' => $analysisData['metrics']['executionTime'] ?? 0,
                'totalPages' => $analysisData['metrics']['totalPages'] ?? 0,
                'similarityCalculations' => $analysisData['metrics']['similarityCalculations'] ?? 0,
                'fromCache' => isset($analysisData['metrics']['fromCache']) ? ($analysisData['metrics']['fromCache'] ? 'Yes' : 'No') : 'Unknown',
            ];
        } else {
            $this->addFlashMessage(
                'Performance metrics are not available.',
                'Metrics Unavailable',
                \TYPO3\CMS\Core\Messaging\AbstractMessage::WARNING
            );
        }    
    
        $moduleTemplate->assignMultiple([
            'parentPageId' => $parentPageId,
            'depth' => $depth,
            'proximityThreshold' => $proximityThreshold,
            'maxSuggestions' => $maxSuggestions,
            'excludePages' => implode(', ', $excludePages),
            'statistics' => $statistics,
            'analysisResults' => $analysisResults,
            'performanceMetrics' => $performanceMetrics,
            'languageDistributionChart' => $languageDistributionChart,
            'topNGramsTable' => $topNGramsTable,
            'recencyBoostChart' => $recencyBoostChart,
        ]);
    
        $moduleTemplate->setContent($this->view->render());
        return $moduleTemplate->renderResponse();
    }
    
    private function calculateStatistics(array $analysisResults, float $proximityThreshold): array
{
    $totalPages = count($analysisResults);
    $totalSimilarityScore = 0;
    $similarityPairs = [];
    $distributionScores = [
        '0.0-0.2' => 0, '0.2-0.4' => 0, '0.4-0.6' => 0, '0.6-0.8' => 0, '0.8-1.0' => 0
    ];
    $pagesSimilarityCount = [];

    foreach ($analysisResults as $pageId => $pageData) {
        $pagesSimilarityCount[$pageId] = 0;
        foreach ($pageData['similarities'] as $similarPageId => $similarity) {
            if ($pageId < $similarPageId) { // Évite les doublons
                $totalSimilarityScore += $similarity['score'];
                $similarityPairs[] = [
                    'page1' => $pageId,
                    'page2' => $similarPageId,
                    'score' => $similarity['score']
                ];
                
                if ($similarity['score'] >= $proximityThreshold) {
                    $pagesSimilarityCount[$pageId]++;
                    $pagesSimilarityCount[$similarPageId] = ($pagesSimilarityCount[$similarPageId] ?? 0) + 1;
                }

                // Mettre à jour la distribution des scores
                if ($similarity['score'] < 0.2) $distributionScores['0.0-0.2']++;
                elseif ($similarity['score'] < 0.4) $distributionScores['0.2-0.4']++;
                elseif ($similarity['score'] < 0.6) $distributionScores['0.4-0.6']++;
                elseif ($similarity['score'] < 0.8) $distributionScores['0.6-0.8']++;
                else $distributionScores['0.8-1.0']++;
            }
        }
    }

    // Trier les paires par score de similarité décroissant
    usort($similarityPairs, function($a, $b) {
        return $b['score'] <=> $a['score'];
    });

    return [
        'totalPages' => $totalPages,
        'averageSimilarity' => $totalPages > 1 ? $totalSimilarityScore / (($totalPages * ($totalPages - 1)) / 2) : 0,
        'topSimilarPairs' => array_slice($similarityPairs, 0, 5),
        'distributionScores' => $distributionScores,
        'topSimilarPages' => arsort($pagesSimilarityCount) ? array_slice($pagesSimilarityCount, 0, 5, true) : [],
    ];
}
private function createLanguageDistributionChart(array $analysisResults): string
{
    $languageData = [];
    foreach ($analysisResults as $pageData) {
        $lang = $pageData['language'] ?? 'unknown';
        $languageData[$lang] = ($languageData[$lang] ?? 0) + 1;
    }

    $graphicalFunctions = GeneralUtility::makeInstance(GraphicalFunctions::class);
    $width = 400;
    $height = 300;
    $image = imagecreatetruecolor($width, $height);
    
    $colors = [
        'en' => imagecolorallocate($image, 255, 0, 0),
        'de' => imagecolorallocate($image, 0, 255, 0),
        'fr' => imagecolorallocate($image, 0, 0, 255),
        'unknown' => imagecolorallocate($image, 128, 128, 128),
    ];
    
    $total = array_sum($languageData);
    $startAngle = 0;
    
    foreach ($languageData as $lang => $count) {
        $endAngle = $startAngle + ($count / $total) * 360;
        imagefilledarc($image, $width/2, $height/2, $width-10, $height-10, $startAngle, $endAngle, $colors[$lang] ?? $colors['unknown'], IMG_ARC_PIE);
        $startAngle = $endAngle;
    }
    
    $filename = 'typo3temp/assets/images/language_distribution.png';
    imagepng($image, GeneralUtility::getFileAbsFileName($filename));
    imagedestroy($image);
    
    return $filename;
}

private function getTopNGrams(array $analysisResults, int $limit = 10): string
{
    $allNGrams = [];
    foreach ($analysisResults as $pageData) {
        $content = $pageData['content']['content'] ?? '';
        $words = $this->tokenizeAndFilterStopWords($content);
        $nGrams = $this->generateNGrams($words, 2);
        $allNGrams = array_merge($allNGrams, $nGrams);
    }
    
    $nGramCounts = array_count_values($allNGrams);
    arsort($nGramCounts);
    $topNGrams = array_slice($nGramCounts, 0, $limit, true);
    
    $table = '<table class="table table-striped"><tr><th>N-gram</th><th>Count</th></tr>';
    foreach ($topNGrams as $nGram => $count) {
        $table .= "<tr><td>" . htmlspecialchars($nGram) . "</td><td>$count</td></tr>";
    }
    $table .= '</table>';
    
    return $table;
}

private function tokenizeAndFilterStopWords(string $text): array
{
    $words = preg_split('/\s+/', strtolower($text));
    return array_filter($words, function($word) {
        return !in_array($word, $this->stopWords) && strlen($word) > 1;
    });
}

private function generateNGrams(array $words, int $n = 2): array
{
    $ngrams = [];
    $count = count($words);
    for ($i = 0; $i < $count - $n + 1; $i++) {
        $ngram = array_slice($words, $i, $n);
        if ($this->isValidNGram($ngram)) {
            $ngrams[] = implode(' ', $ngram);
        }
    }
    return $ngrams;
}

private function isValidNGram(array $ngram): bool
{
    // Un n-gram est valide si au moins un mot n'est pas un mot vide
    foreach ($ngram as $word) {
        if (!in_array($word, $this->stopWords)) {
            return true;
        }
    }
    return false;
}

private function createRecencyBoostChart(array $analysisResults): string
{
    $graphicalFunctions = GeneralUtility::makeInstance(GraphicalFunctions::class);
    $width = 500;
    $height = 300;
    $image = imagecreatetruecolor($width, $height);
    
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    $red = imagecolorallocate($image, 255, 0, 0);
    
    imagefill($image, 0, 0, $white);
    
    $maxAge = 30 * 24 * 3600; // 30 jours en secondes
    $now = time();
    
    foreach ($analysisResults as $pageData) {
        $age = $now - ($pageData['content_modified_at'] ?? $now);
        $boost = $this->pageAnalysisService->calculateRecencyBoost(
            ['content_modified_at' => $pageData['content_modified_at'] ?? $now],
            ['content_modified_at' => $now]
        );
        
        $x = (int)(($age / $maxAge) * $width);  // Conversion explicite en int
        $y = (int)($height - ($boost * $height));  // Conversion explicite en int
        
        imagesetpixel($image, $x, $y, $red);
    }
    
    // Ajouter des axes
    imageline($image, 0, $height-1, $width-1, $height-1, $black);
    imageline($image, 0, 0, 0, $height-1, $black);
    
    $filename = 'typo3temp/assets/images/recency_boost_impact.png';
    imagepng($image, GeneralUtility::getFileAbsFileName($filename));
    imagedestroy($image);
    
    return $filename;
}




}