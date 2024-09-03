<?php

namespace TalanHdf\SemanticSuggestion\Service;

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

class StopWordsService
{
    protected $stopWords = [];
    protected $stopWordsFilePath;

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
            return $text; // Retourne le texte original si la langue n'est pas supportée
        }

        $words = preg_split('/\s+/', $text);
        $filteredWords = array_filter($words, function($word) use ($language) {
            return !in_array(mb_strtolower($word), $this->stopWords[$language]);
        });

        return implode(' ', $filteredWords);
    }

    public function getAvailableLanguages(): array
    {
        return array_keys($this->stopWords);
    }
}