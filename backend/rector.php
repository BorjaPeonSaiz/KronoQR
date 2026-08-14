<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelSetList;

/*
 * Modernizacion del codigo — doc 02 §3.5, fila «Modernizacion»: sets de
 * PHP 8.4, Laravel, code quality y dead code.
 *
 * Umbral del §9.2: **informativo**. Rector se ejecuta en dry-run y su salida no
 * hace fallar `make quality` ni la CI. La razon es deliberada: sus sugerencias
 * son de estilo y de idioma, no de correccion, y una herramienta que reescribe
 * codigo no puede ser la que decide si una entrega sale. Lo que si bloquea
 * —tipos, fronteras, estilo— tiene su propia herramienta con umbral cero.
 *
 * Para aplicar los cambios en local, sin --dry-run:
 *   docker compose --env-file .env -f infra/compose.dev.yaml exec app \
 *     vendor/bin/rector process
 * y despues `make quality`, porque Rector no formatea: eso es de Pint.
 */

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
        __DIR__.'/tools',
    ])
    ->withSkip([
        __DIR__.'/bootstrap/cache',
        __DIR__.'/storage',
        __DIR__.'/vendor',

        // Sustituye `null === $x` por `!$x instanceof Tipo`. Obliga a conocer el
        // tipo para leer una comprobacion de nulo, y el codigo de este proyecto
        // pregunta por el nulo explicitamente. Es preferencia de estilo, no
        // correccion: por eso se desactiva aqui y no se discute en revision.
        FlipTypeControlToUseExclusiveTypeRector::class,
    ])
    // El cache dentro del proyecto: el contenedor se recrea y /tmp se va con el.
    ->withCache(__DIR__.'/storage/framework/cache/rector')
    // Una sola llamada y una sola version: incluye todos los sets anteriores.
    ->withPhpSets(php84: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
    )
    ->withSets([
        // Idiomas de la version que ejecutamos, no de la siguiente: sugerir
        // API de Laravel 13 sobre un Laravel 12 seria ruido, no modernizacion.
        LaravelSetList::LARAVEL_120,
        LaravelSetList::LARAVEL_CODE_QUALITY,
    ]);
