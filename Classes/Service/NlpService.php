<?php
namespace TalanHdf\SemanticSuggestion\Service;

use TYPO3\CMS\Core\SingletonInterface;
use Symfony\Component\Process\Process;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class NlpService implements SingletonInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected $pythonPath;
    protected $scriptPath;
    protected $logPath;
    protected $enabled;

    public function __construct()
    {
        $this->pythonPath = '/usr/bin/python3'; // Ajustez selon votre environnement
        $this->scriptPath = GeneralUtility::getFileAbsFileName('EXT:semantic_suggestion/Resources/Private/Python/camembert_analysis.py');
        $this->logPath = GeneralUtility::getFileAbsFileName('EXT:semantic_suggestion/Resources/Private/Python/nlp_analysis.log');
        
        $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
        $config = $extensionConfiguration->get('semantic_suggestion');
        $this->enabled = (bool)($config['enableNlpAnalysis'] ?? false);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function analyzeContent(string $content): array
    {
        if (!$this->isEnabled()) {
            return $this->getDefaultAnalysis();
        }

        try {
            $this->logger->info('Starting NLP analysis');
            
            $process = new Process([$this->pythonPath, $this->scriptPath, $content]);
            $process->setTimeout(300); // Augmenté à 5 minutes pour les longs textes
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException($process->getErrorOutput());
            }

            $result = json_decode($process->getOutput(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('Invalid JSON output from Python script');
            }
    
            $this->logger->info('NLP analysis completed', ['result' => $result]);
    
            return [
                'sentiment' => $result['sentiment'] ?? 'neutral',
                'keyphrases' => $result['keyphrases'] ?? [],
                'category' => $result['category'] ?? 'uncategorized',
                'named_entities' => $result['named_entities'] ?? [],
                'readability_score' => $result['readability_score'] ?? 0.0,
            ];
        } catch (\RuntimeException $e) { // Catch RuntimeException (or a more specific exception if you know what to expect)
        $this->logger->error('NLP analysis failed', ['exception' => $e]);
        // Optionally, log the Python output for debugging
        $this->logPythonOutput();

        // Return default analysis or handle the error in another way
        return $this->getDefaultAnalysis(); 
    }
    }

    protected function logPythonOutput()
    {
        if (file_exists($this->logPath)) {
            $logContent = file_get_contents($this->logPath);
            $this->logger->info('Python script log:', ['log' => $logContent]);
        }
    }

    protected function calculateReadabilityScore(string $content): float
    {
        $sentences = preg_split('/[.!?]+/', $content);
        $wordCount = str_word_count($content, 0, 'àáâãäçèéêëìíîïñòóôõöùúûüýÿ');
        $syllableCount = $this->countFrenchSyllables($content);
        
        if (empty($sentences) || $wordCount === 0) {
            return 0.0;
        }
        
        $averageWordsPerSentence = $wordCount / count($sentences);
        $averageSyllablesPerWord = $syllableCount / $wordCount;
        
        // Formule adaptée de Flesch-Kincaid pour le français
        $score = 207 - (1.015 * $averageWordsPerSentence) - (73.6 * $averageSyllablesPerWord);
        
        // Assurez-vous que le score est dans une plage raisonnable
        return max(0, min(100, $score));
    }
    
    protected function countFrenchSyllables(string $text): int
    {
        $text = mb_strtolower($text, 'UTF-8');
        $syllableCount = 0;
        $vowels = ['a', 'e', 'i', 'o', 'u', 'y', 'à', 'â', 'é', 'è', 'ê', 'ë', 'î', 'ï', 'ô', 'û', 'ü'];
        
        $words = preg_split('/\s+/', $text);
        foreach ($words as $word) {
            $wordSyllables = 0;
            $prevChar = '';
            foreach (mb_str_split($word) as $char) {
                if (in_array($char, $vowels) && !in_array($prevChar, $vowels)) {
                    $wordSyllables++;
                }
                $prevChar = $char;
            }
            $syllableCount += max(1, $wordSyllables);
        }
        
        return $syllableCount;
    }

    public function calculateNlpSimilarity(array $nlpData1, array $nlpData2): float
    {
        if (!$this->isEnabled()) {
            return 0.0;
        }

        $keywordSimilarity = $this->calculateJaccardSimilarity($nlpData1['keywords'], $nlpData2['keywords']);
        $sentimentSimilarity = $nlpData1['sentiment'] === $nlpData2['sentiment'] ? 1.0 : 0.0;

        return ($keywordSimilarity + $sentimentSimilarity) / 2;
    }

    protected function calculateJaccardSimilarity(array $set1, array $set2): float
    {
        $intersection = array_intersect($set1, $set2);
        $union = array_unique(array_merge($set1, $set2));
        return count($union) > 0 ? count($intersection) / count($union) : 0.0;
    }


    protected function getDefaultAnalysis(): array
    {
        return [
            'sentiment' => 'neutral',
            'keywords' => [],
            'category' => 'uncategorized',
            'named_entities' => [],
            'readabilityScore' => 0.0
        ];
    }
}