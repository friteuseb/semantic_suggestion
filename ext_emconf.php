<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Semantic Suggestion',
    'description' => 'TYPO3 extension for suggesting semantically related pages using advanced NLP and TF-IDF analysis',
    'category' => 'plugin',
    'author' => 'Wolfangel Cyril',
    'author_email' => 'cyril.wolfangel@gmail.com',
    'state' => 'stable',
    'clearCacheOnLoad' => 0,
    'version' => '4.1.4',
    'constraints' => [
        'depends' => [
            'php' => '8.1.0-8.99.99',
            'typo3' => '12.4.0-14.99.99',
            'scheduler' => '12.4.0-14.99.99',
            // Hard requirement, not a suggestion: all text processing and the whole
            // TF-IDF vectorisation live in nlp_tools. Without it no vector is built,
            // every score stays at 0.0 and nothing is ever stored.
            'nlp_tools' => '2.0.0-2.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];