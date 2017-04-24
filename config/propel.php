<?php
return [
    'propel' => [
        'paths' => [
            'schemaDir' => __DIR__.'/../src/database/',
            'phpDir' => __DIR__.'/../src/models/',
            'sqlDir' => __DIR__.'/../src/sql/',
            'phpConfDir' => __DIR__.'/../tmp',
        ],
        'database' => [
            'className' => '\\Propel\\Runtime\\Connection\\DebugPDO',
            'connections' => [
                'default' => [
                    'adapter' => 'sqlite',
                    'dsn' => 'sqlite:../app.sq3',
                    'settings' => [
                        'charset' => 'utf8',
                    ],
                ],
            ],
        ],
        'runtime' => [
            'log' => [
                'defaultLogger' => [
                    'type' => 'stream',
                    'path' => '../logs/propel.log',
                    'level' => 100,
                ],
            ],
        ],
        'generator' => [
            'dateTime' => [
                'useDateTimeClass' => true,
                'dateTimeClass' => 'DateTimeImmutable',
            ],
            'namespaceAutoPackage' => false,
            'objectModel' => [
                'addClassLevelComment' => false,
            ],
        ],
    ],
];
