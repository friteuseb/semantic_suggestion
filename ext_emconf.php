<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Semantic Suggestion',
    'description' => 'TYPO3 extension for suggesting semantically related pages',
    'category' => 'plugin',
    'author' => 'Wolfangel Cyril',
    'author_email' => 'cyril.wolfangel@gmail.com',
    'state' => 'beta',
    'clearCacheOnLoad' => 0,
    'version' => '1.0.9',
    'constraints' => [
        'depends' => [
            'typo3' => '12.0.0-13.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];