<?php
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || die('Access denied.');

ExtensionManagementUtility::addStaticFile(
    'semantic_suggestion',
    'Configuration/TypoScript/',
    'Semantic Suggestion'
);
