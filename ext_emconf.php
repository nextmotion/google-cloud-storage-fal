<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Google Cloud Storage FAL Driver',
    'description' => 'Google Cloud Storage FAL driver for TYPO3. Files can be stored in the GCS buckets.',
    'category' => 'be',
    'version' => '2.0.1',
    'state' => 'stable',
    'clearcacheonload' => 1,
    'author' => 'Pierre Geyer',
    'author_email' => 'pg@next-motion.de',
    'author_company' => 'next.motion',
    'constraints' => [
        'depends' => [
            'typo3' => '12.0.0-12.9.99',
        ],
    ],
];
