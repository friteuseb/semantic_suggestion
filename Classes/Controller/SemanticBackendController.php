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
            $topNGramsTable = $this->getTopNGrams($analysisResults, 10, 'fr'); 
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

private function tokenizeAndFilterStopWords(string $text, string $language = 'fr'): array
{
    $words = preg_split('/\s+/', strtolower($text));
    $stopWords = $this->getStopWords($language);
    return array_filter($words, function($word) use ($stopWords) {
        return !in_array($word, $stopWords) && strlen($word) > 1;
    });
}

private function getStopWords(string $language): array
{
    $stopWords = [
        'en' => ['the', 'is', 'at', 'which', 'on', 'and', 'a', 'an', 'of', 'to', 'in', 'that', 'it', 'with', 'as', 'for', 'was', 'were', 'be', 'by', 'this', 'are', 'from', 'or', 'but', 'not', 'they', 'can', 'we', 'there', 'so', 'no', 'up', 'if', 'out', 'about', 'into', 'when', 'who', 'what', 'where', 'how', 'why', 'will', 'would', 'should', 'could', 'their', 'my', 'your', 'his', 'her', 'its', 'our', 'have', 'has', 'had', 'do', 'does', 'did', 'than', 'then', 'too', 'more', 'over', 'only', 'just', 'like', 'also'],
        'fr' => ['le', 'la', 'les', 'est', 'à', 'de', 'des', 'et', 'un', 'une', 'du', 'en', 'dans', 'que', 'qui', 'où', 'par', 'pour', 'avec', 'sur', 'se', 'ce', 'sa', 'son', 'ses', 'au', 'aux', 'lui', 'elle', 'il', 'ils', 'elles', 'nous', 'vous', 'ne', 'pas', 'ni', 'plus', 'ou', 'mais', 'donc', 'car', 'si', 'tout', 'comme', 'cela', 'ont', 'été', 'était', 'être', 'sont', 'étant', 'ayant', 'avait', 'avaient'],
    ];

    return $stopWords[$language] ?? [];
}

private function getTopNGrams(array $analysisResults, int $limit = 10, string $language = 'fr'): string
{
    $allNGrams = [];
    foreach ($analysisResults as $pageData) {
        $content = $pageData['content']['content'] ?? '';
        $words = $this->tokenizeAndFilterStopWords($content, $language);
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

private function generateNGrams(array $words, int $n = 2): array
{
    $ngrams = [];
    $count = count($words);
    for ($i = 0; $i < $count - $n + 1; $i++) {
        $ngram = array_slice($words, $i, $n);
        $ngrams[] = implode(' ', $ngram);
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
    $width = 500;
    $height = 300;
    $image = imagecreatetruecolor($width, $height);
    
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    $red = imagecolorallocate($image, 255, 0, 0);
    
    imagefill($image, 0, 0, $white);
    
    $maxAge = 30 * 24 * 3600; // 30 jours en secondes
    $now = time();
    
    // Dessiner les axes
    imageline($image, 50, $height-50, $width-50, $height-50, $black); // axe X
    imageline($image, 50, 50, 50, $height-50, $black); // axe Y
    
    foreach ($analysisResults as $pageData) {
        $age = $now - ($pageData['content_modified_at'] ?? $now);
        $boost = $this->pageAnalysisService->calculateRecencyBoost(
            ['content_modified_at' => $pageData['content_modified_at'] ?? $now],
            ['content_modified_at' => $now]
        );
        
        $x = 50 + (($age / $maxAge) * ($width - 100));
        $y = ($height - 50) - ($boost * ($height - 100));
        
        imagefilledellipse($image, (int)$x, (int)$y, 5, 5, $red);
    }
    
    // Ajouter des étiquettes
    imagestring($image, 3, $width-100, $height-40, 'Age (jours)', $black);
    imagestringup($image, 3, 10, $height-100, 'Recency Boost', $black);
    
    $filename = 'typo3temp/assets/images/recency_boost_impact.png';
    imagepng($image, GeneralUtility::getFileAbsFileName($filename));
    imagedestroy($image);
    
    return $filename;
}

private function createWordCloud(array $analysisResults): string
{
    $words = [];
    foreach ($analysisResults as $pageData) {
        $content = $pageData['content']['content'] ?? '';
        $pageWords = $this->tokenizeAndFilterStopWords($content);
        foreach ($pageWords as $word) {
            $words[$word] = ($words[$word] ?? 0) + 1;
        }
    }

    arsort($words);
    $topWords = array_slice($words, 0, 50, true);

    $width = 800;
    $height = 400;
    $image = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($image, 255, 255, 255);
    imagefill($image, 0, 0, $white);

    $colors = [
        imagecolorallocate($image, 0, 0, 255),
        imagecolorallocate($image, 255, 0, 0),
        imagecolorallocate($image, 0, 255, 0),
        imagecolorallocate($image, 128, 0, 128),
    ];

    $maxSize = 40;
    $minSize = 10;
    $maxCount = max($topWords);

    foreach ($topWords as $word => $count) {
        $size = $minSize + ($count / $maxCount) * ($maxSize - $minSize);
        $x = rand($size, $width - $size);
        $y = rand($size, $height - $size);
        $color = $colors[array_rand($colors)];
        imagettftext($image, $size, rand(-30, 30), $x, $y, $color, '/path/to/font.ttf', $word);
    }

    $filename = 'typo3temp/assets/images/word_cloud.png';
    imagepng($image, GeneralUtility::getFileAbsFileName($filename));
    imagedestroy($image);

    return $filename;
}

private function createPageLengthDistribution(array $analysisResults): string
{
    $lengths = [];
    foreach ($analysisResults as $pageData) {
        $content = $pageData['content']['content'] ?? '';
        $length = str_word_count($content);
        $lengths[] = $length;
    }

    $width = 500;
    $height = 300;
    $image = imagecreatetruecolor($width, $height);
    
    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    $blue = imagecolorallocate($image, 0, 0, 255);
    
    imagefill($image, 0, 0, $white);
    
    $maxLength = max($lengths);
    $binSize = $maxLength / 10;
    $bins = array_fill(0, 10, 0);
    
    foreach ($lengths as $length) {
        $binIndex = min(floor($length / $binSize), 9);
        $bins[$binIndex]++;
    }
    
    $maxBinCount = max($bins);
    
    // Dessiner les axes
    imageline($image, 50, $height-50, $width-50, $height-50, $black); // axe X
    imageline($image, 50, 50, 50, $height-50, $black); // axe Y
    
    $barWidth = ($width - 100) / count($bins);
    
    for ($i = 0; $i < count($bins); $i++) {
        $barHeight = ($bins[$i] / $maxBinCount) * ($height - 100);
        $x1 = 50 + $i * $barWidth;
        $y1 = $height - 50 - $barHeight;
        $x2 = $x1 + $barWidth - 2;
        $y2 = $height - 50;
        
        imagefilledrectangle($image, (int)$x1, (int)$y1, (int)$x2, (int)$y2, $blue);
    }
    
    // Ajouter des étiquettes
    imagestring($image, 3, $width-100, $height-40, 'Page Length', $black);
    imagestringup($image, 3, 10, $height-100, 'Number of Pages', $black);
    
    $filename = 'typo3temp/assets/images/page_length_distribution.png';
    imagepng($image, GeneralUtility::getFileAbsFileName($filename));
    imagedestroy($image);
    
    return $filename;
}

}