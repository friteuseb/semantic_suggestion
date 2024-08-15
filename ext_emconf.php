<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Semantic Suggestion with NLP',
    'description' => 'TYPO3 extension for suggesting semantically related pages with NLP capabilities',
    'category' => 'plugin',
    'author' => 'Wolfangel Cyril',
    'author_email' => 'cyril.wolfangel@gmail.com',
    'state' => 'beta',
    'clearCacheOnLoad' => 0,
    'version' => '1.2.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.0.0-13.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
    // Permet de créer la table sql
    'update' => [
        'numbered' => [
            '1' => [ 
                'type' => 'sql',
                'tables' => [
                    'tx_semanticsuggestion_nlp_results' => 'ext_tables.sql', 
                ],
            ],
        ],
    ],
];