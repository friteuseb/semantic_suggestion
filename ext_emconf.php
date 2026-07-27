<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Semantic Suggestion',
    'description' => 'TYPO3 extension for suggesting semantically related pages using advanced NLP and TF-IDF analysis',
    'category' => 'plugin',
    'author' => 'Wolfangel Cyril',
    'author_email' => 'cyril.wolfangel@gmail.com',
    'state' => 'stable',
    'clearCacheOnLoad' => 0,
    'version' => '4.1.3',
    'constraints' => [
        'depends' => [
            'php' => '8.1.0-8.99.99',
            'typo3' => '12.4.0-14.99.99',
            'scheduler' => '12.4.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'nlp_tools' => '2.0.0-2.99.99',
        ],
    ],
];