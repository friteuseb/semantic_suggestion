<?php

namespace TalanHdf\SemanticSuggestion\Service;

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

class StopWordsService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected $stopWords = [];
    protected $stopWordsFilePath;
    protected bool $debugMode = false;

    public function __construct(string $stopWordsFilePath = null)
    {
        $this->stopWordsFilePath = $stopWordsFilePath ?? $this->getDefaultStopWordsFilePath();
        $this->loadStopWords();
    }

    protected function getDefaultStopWordsFilePath(): string
    {
        return ExtensionManagementUtility::extPath('semantic_suggestion') . 'Resources/Private/StopWords/stopwords.json';
    }

    protected function loadStopWords(): void
    {
        if (file_exists($this->stopWordsFilePath)) {
            $this->stopWords = json_decode(file_get_contents($this->stopWordsFilePath), true);
            if ($this->stopWords === null) {
                throw new \RuntimeException('Failed to parse stopwords.json file', 1234567891);
            }
        } else {
            throw new \RuntimeException('Stop words file not found: ' . $this->stopWordsFilePath, 1234567890);
        }
    }

    public function removeStopWords(string $text, string $language): string
    {
        if (!isset($this->stopWords[$language])) {
            return $text;
        }

        if ($this->debugMode && $this->logger instanceof LoggerInterface) {
            $this->logger->debug('Text before stop words removal', ['text' => $text, 'language' => $language]);
        }

        $words = preg_split('/\s+/', $text);
        $filteredWords = array_filter($words, function($word) use ($language) {
            return !in_array(mb_strtolower($word), $this->stopWords[$language]);
        });

        $result = implode(' ', $filteredWords);

        if ($this->debugMode && $this->logger instanceof LoggerInterface) {
            $this->logger->debug('Text after stop words removal', ['text' => $result, 'language' => $language]);
        }

        return $result;
    }

    public function getStopWordsForLanguage(string $language): array
    {
        return $this->stopWords[$language] ?? [];
    }

    public function setDebugMode(bool $debugMode): void
    {
        $this->debugMode = $debugMode;
    }

    public function getAvailableLanguages(): array
    {
        return array_keys($this->stopWords);
    }
}