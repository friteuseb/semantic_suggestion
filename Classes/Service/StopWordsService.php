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
        $stopWordsFile = GeneralUtility::getFileAbsFileName('EXT:semantic_suggestion/Resources/Private/StopWords/stop_words.php');
        if (file_exists($stopWordsFile)) {
            $this->stopWords = require $stopWordsFile;
        } else {
            throw new \RuntimeException('Stop words file not found', 1234567890);
        }
    }

    public function removeStopWords(string $text, string $language = 'français'): string
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
}
