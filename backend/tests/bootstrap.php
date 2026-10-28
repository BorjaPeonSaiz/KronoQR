<?php

declare(strict_types=1);
use Tests\Support\Shared\SuiteTemporaryDirectory;

/*
 * Arranque de PHPUnit/Pest para todas las suites.
 *
 * Lo unico que añade al autoload de Composer es el directorio temporal propio
 * de la pasada (`SuiteTemporaryDirectory`, Q10): tiene que instalarse antes de
 * que nadie llame a `sys_get_temp_dir()`, porque PHP recuerda el primer
 * resultado. Por eso vive aqui y no en `tests/Pest.php`, que se carga despues.
 */

require __DIR__.'/../vendor/autoload.php';

SuiteTemporaryDirectory::install();
