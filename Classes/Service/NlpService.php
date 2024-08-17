<?php
namespace TalanHdf\SemanticSuggestion\Service;

use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Google\Cloud\Language\LanguageClient;

class NlpService implements SingletonInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected $languageClient;
    protected $enabled;

    public function __construct()
    {
        $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
        $config = $extensionConfiguration->get('semantic_suggestion');
        
        $this->enabled = (bool)($config['enableNlpAnalysis'] ?? false);

        if ($this->enabled) {
            $keyFilePath = $config['googleCloudKeyPath'] ?? '';
            if (!file_exists($keyFilePath)) {
                throw new \RuntimeException('Google Cloud key file not found: ' . $keyFilePath);
            }
            $this->languageClient = new LanguageClient([
                'keyFilePath' => $keyFilePath,
            ]);
        }
    }


    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function analyzeContent(string $content): array
    {
        if (!$this->isEnabled() || strlen(trim($content)) < 20) {
            $this->logger->info('Content too short or NLP disabled, returning default analysis');
            return $this->getDefaultAnalysis();
        }
    
        try {
            $this->logger->info('Starting NLP analysis', ['content_length' => strlen($content)]);
            
            $annotation = $this->languageClient->annotateText($content);
    
            $tokens = $annotation->tokens();
            $wordCount = count($tokens);
    
            if ($wordCount == 0) {
                $this->logger->warning('No words found in content, returning default analysis');
                return $this->getDefaultAnalysis();
            }
    
            $result = [
                'sentiment' => $this->getSentimentLabel($annotation->sentiment()),
                'keyphrases' => $this->extractKeyphrases($annotation->entities()),
                'category' => $this->determineCategory($annotation->categories()),
                'named_entities' => $this->extractNamedEntities($annotation->entities()),
                'readability_score' => $this->calculateReadabilityScore($annotation->sentences(), $tokens),
                'word_count' => $wordCount,
                'unique_word_count' => count(array_unique(array_column($tokens, 'text'))),
                'complexity' => $this->calculateComplexity($tokens),
            ];
    
            $this->logger->info('NLP analysis completed', ['result' => $result]);
    
            return $result;
        } catch (\Exception $e) {
            $this->logger->error('NLP analysis failed', ['exception' => $e->getMessage()]);
            return $this->getDefaultAnalysis();
        }
    }

    protected function getSentimentLabel(array $sentiment): string
    {
        $score = $sentiment['score'];
        if ($score > 0.25) return 'positive';
        if ($score < -0.25) return 'negative';
        return 'neutral';
    }

    protected function extractKeyphrases(array $entities): array
    {
        return array_slice(array_map(function($entity) {
            return $entity['name'];
        }, array_filter($entities, function($entity) {
            return $entity['type'] === 'OTHER' && ($entity['salience'] ?? 0) > 0.02;
        })), 0, 5);
    }

    protected function determineCategory(array $categories): string
    {
        return !empty($categories) ? $categories[0]['name'] : 'uncategorized';
    }

    protected function extractNamedEntities(array $entities): array
    {
        return array_map(function($entity) {
            return [
                'name' => $entity['name'],
                'type' => $entity['type'],
            ];
        }, array_filter($entities, function($entity) {
            return in_array($entity['type'], ['PERSON', 'LOCATION', 'ORGANIZATION']);
        }));
    }

    protected function calculateReadabilityScore(array $sentences, array $tokens): float
    {
        $wordCount = count($tokens);
        $sentenceCount = count($sentences);
        
        if ($sentenceCount == 0 || $wordCount == 0) {
            return 0.0;
        }
        
        $averageWordsPerSentence = $wordCount / $sentenceCount;
        return (206.835 - (1.015 * $averageWordsPerSentence)) / 100;
    }

    protected function calculateComplexity(array $tokens): float
    {
        $totalTokens = count($tokens);
        if ($totalTokens == 0) {
            return 0.0;
        }
        
        $complexWords = array_filter($tokens, function($token) {
            return strlen($token['text']) > 6;
        });
        
        return count($complexWords) / $totalTokens;
    }

    protected function getDefaultAnalysis(): array
    {
        return [
            'sentiment' => 'neutral',
            'keyphrases' => [],
            'category' => 'uncategorized',
            'named_entities' => [],
            'readability_score' => 0.0,
            'word_count' => 0,
            'unique_word_count' => 0,
            'complexity' => 0.0,
        ];
    }
}