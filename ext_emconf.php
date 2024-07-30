<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Semantic Suggestion',
    'description' => 'TYPO3 extension for suggesting semantically related pages',
    'category' => 'plugin',
    'author' => 'Wolfangel Cyril',
    'author_email' => 'cyril.wolfangel@gmail.com',
    'state' => 'alpha',
    'clearCacheOnLoad' => 0,
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-12.4.99',
            'dashboard' => '12.4.0-12.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];