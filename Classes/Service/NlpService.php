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
            $this->languageClient = new LanguageClient([
                'keyFilePath' => $config['googleCloudKeyPath'] ?? null,
            ]);
        }
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
            $this->logger->info('Starting NLP analysis with Google Cloud');
            
            $annotation = $this->languageClient->annotateText($content);

            $sentiment = $annotation->sentiment();
            $entities = $annotation->entities();
            $syntax = $annotation->tokens();

            $result = [
                'sentiment' => $this->getSentimentLabel($sentiment['score']),
                'keyphrases' => $this->extractKeyphrases($entities),
                'category' => $this->determineCategory($entities),
                'named_entities' => $this->extractNamedEntities($entities),
                'readability_score' => $this->calculateReadabilityScore($syntax),
            ];
    
            $this->logger->info('NLP analysis completed', ['result' => $result]);
    
            return $result;
        } catch (\Exception $e) {
            $this->logger->error('NLP analysis failed', ['exception' => $e]);
            return $this->getDefaultAnalysis();
        }
    }

    protected function getSentimentLabel(float $score): string
    {
        if ($score > 0.25) return 'positive';
        if ($score < -0.25) return 'negative';
        return 'neutral';
    }

    protected function extractKeyphrases(array $entities): array
    {
        return array_slice(array_map(function($entity) {
            return $entity['name'];
        }, array_filter($entities, function($entity) {
            return $entity['type'] === 'OTHER' && $entity['salience'] > 0.02;
        })), 0, 5);
    }

    protected function determineCategory(array $entities): string
    {
        $categories = array_column(array_filter($entities, function($entity) {
            return isset($entity['metadata']['mid']);
        }), 'type');
        return !empty($categories) ? $categories[0] : 'uncategorized';
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

    protected function calculateReadabilityScore(array $syntax): float
    {
        // Implement a readability score calculation based on syntax
        // This is a simplified example and might need to be adjusted
        $wordCount = count($syntax);
        $sentenceCount = count(array_filter($syntax, function($token) {
            return in_array($token['partOfSpeech']['tag'], ['PUNCT', 'X']);
        }));
        
        if ($sentenceCount == 0) return 0;
        
        return (206.835 - 1.015 * ($wordCount / $sentenceCount) - 84.6 * ($wordCount / $sentenceCount)) / 100;
    }

    protected function getDefaultAnalysis(): array
    {
        return [
            'sentiment' => 'neutral',
            'keyphrases' => [],
            'category' => 'uncategorized',
            'named_entities' => [],
            'readability_score' => 0.0
        ];
    }
}