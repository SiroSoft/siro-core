<?php

declare(strict_types=1);

return [
    'driver' => 'sqlite',
    'database' => \Siro\Core\Env::get('OAUTH2_DB_PATH', __DIR__ . '/../storage/oauth2.sqlite'),
];
