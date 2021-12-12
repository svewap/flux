<?php
$EM_CONF[$_EXTKEY] = [
    'title' => 'Flux: Fluid Integration',
    'description' => 'Drop-in page and content templates with nested content feature. Provides multiple condensed integration APIs to use advanced TYPO3 features with little effort.',
    'category' => 'misc',
    'shy' => 0,
    'version' => '9.5.0',
    'dependencies' => 'cms',
    'conflicts' => '',
    'priority' => 'top',
    'loadOrder' => '',
    'module' => '',
    'state' => 'beta',
    'uploadfolder' => 0,
    'createDirs' => '',
    'modify_tables' => '',
    'clearcacheonload' => 1,
    'lockType' => '',
    'author' => 'FluidTYPO3 Team',
    'author_email' => 'claus@namelesscoder.net',
    'author_company' => '',
    'CGLcompliance' => '',
    'CGLcompliance_note' => '',
    'constraints' => [
        'depends' => [
            'typo3' => '8.7.0-11.5.99',
            'php' => '7.1.0-7.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
    'suggests' => [],
    'autoload' => [
        'psr-4' => [
            'FluidTYPO3\\Flux\\' => 'Classes/',
        ],
    ],
    'autoload-dev' => [
        'psr-4' => [
            'FluidTYPO3\\Flux\\Tests\\' => 'Tests/',
        ],
    ],
];
