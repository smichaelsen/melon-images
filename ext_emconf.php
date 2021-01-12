<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Melon Images',
    'description' => 'Responsive Images Management for TYPO3 8.7',
    'category' => 'plugin',
    'author' => 'Sebastian Michaelsen',
    'author_email' => 'sebastian@michaelsen.io',
    'state' => 'stable',
    'version' => '3.0.0-dev',
    'constraints' => [
        'depends' => [
            'typo3' => '8.7.0-8.99.99',
            'php' => '7.0.0-7.2.99',
        ],
    ],
];
