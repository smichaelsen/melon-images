<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Melon Images',
    'description' => 'Responsive Image Management',
    'category' => 'plugin',
    'author' => 'Sebastian Michaelsen',
    'author_email' => 'sebastian@michaelsen.io',
    'state' => 'stable',
    // Set from the git tag by .github/workflows/publish.yaml before an upload to TER
    'version' => '5.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.0.0-14.99.99',
            'php' => '8.2.0-8.99.99',
        ],
    ],
];
