<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// Warm the test container up-front so functional (WebTestCase) tests don't hit the cold
// kernel-boot recompile crash (the FormBuilder prototype-loader quirk). cache:warmup is
// idempotent — it recompiles only when sources changed (ConfigCache) and compiles cleanly —
// so this stays cheap on a warm cache and removes the previously-manual warmup step.
if ('test' === ($_SERVER['APP_ENV'] ?? 'dev')) {
    exec(sprintf(
        '%s %s cache:warmup --env=test --no-interaction 2>&1',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(dirname(__DIR__).'/bin/console'),
    ));
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
