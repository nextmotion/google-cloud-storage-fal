<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Google Cloud Storage FAL Driver',
    'description' => 'Google Cloud Storage FAL driver for TYPO3',
    'category' => 'be',
    'version' => '1.0',
    'state' => 'beta',
    'clearcacheonload' => 1,
    'author' => 'Pierre Geyer',
    'author_email' => 'pg@next-motion.de',
    'author_company' => 'next.motion',
    'constraints' => [
        'depends' => [
            'typo3' => '10.0.0-10.9.99',
        ],
    ],
];
