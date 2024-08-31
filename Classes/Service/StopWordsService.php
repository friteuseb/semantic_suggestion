<?php
namespace TalanHdf\SemanticSuggestion\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;

class StopWordsService
{
    protected $stopWords = [];

    public function __construct()
    {
        $this->loadStopWords();
    }

    protected function loadStopWords(): void
    {
        $stopWordsFile = GeneralUtility::getFileAbsFileName('EXT:semantic_suggestion/Resources/Private/StopWords/stopwords.json');
        if (!file_exists($stopWordsFile)) {
            throw new \RuntimeException('Stop words file not found: ' . $stopWordsFile, 1234567890);
        }
        $content = file_get_contents($stopWordsFile);
        $this->stopWords = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Error decoding stop words file: ' . json_last_error_msg(), 1234567891);
        }
    }

    public function removeStopWords(string $text, string $language): string
    {
        if (!isset($this->stopWords[$language])) {
            return $text; // Return original text if language is not supported
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