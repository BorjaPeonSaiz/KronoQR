<?php

declare(strict_types=1);

use Tests\Architecture\Support\Repo;

/*
 * PGOPTIONS (`lock_timeout`, `idle_in_transaction_session_timeout`) llega
 * SOLO al servicio `app` en los DOS compose, nunca a `horizon`, `reverb` ni
 * `scheduler` (tarea 3.6, decision 14 enmendada por la revision de
 * seguridad: I-1). `app` es el unico que sirve peticiones -fichaje, panel,
 * portal- y el que ejecuta las migraciones (`compose run app php artisan
 * migrate ...`); `horizon` y `scheduler` ejecutan trabajos LARGOS por diseno
 * -reconciliacion, `backup:run` (que hace `pg_dump`), retencion- y los dos
 * limites, pensados para el camino de fichaje, los abortarian a mitad
 * (`idle_in_transaction_session_timeout=60s` frente a un `pg_dump` con
 * destino de red lento; `lock_timeout=5s` frente a un `VACUUM` en curso).
 * `reverb` no hace consultas de escritura en el camino critico.
 *
 * Verificado de forma empirica con `docker compose ... config` antes de
 * escribir esta prueba (ver HANDOFF, tarea 3.6); esto es la GUARDA que evita
 * que alguien lo rompa despues sin notarlo -en cualquier direccion: que
 * desaparezca de `app`, o que se cuele en otro de los tres-.
 *
 * POR QUE EL BLOQUE DE CADA SERVICIO, Y NO SOLO LA PLANTILLA COMPARTIDA. En
 * compose.dev.yaml un `environment:` PROPIO del servicio SUSTITUYE al de la
 * plantilla `x-php-image` entera, no lo amplia (documentado en el propio
 * fichero, en el comentario del servicio `app`): `app`, `horizon` y
 * `scheduler` redefinen `environment:` ahi; solo `reverb` hereda de la
 * plantilla sin tocarla. En compose.prod.yaml es al reves: solo `app` lo
 * redefine, los otros tres heredan de `x-app-image` sin tocarla. Ninguna de
 * las dos plantillas compartidas lleva PGOPTIONS -si lo llevara, los cuatro
 * servicios de compose.prod.yaml lo heredarian, y ese es justo el error que
 * esta prueba tiene que impedir-, asi que la unica forma fiable de
 * verificarlo es mirar el bloque YA COMPUESTO de cada servicio, uno a uno.
 */

/**
 * El bloque de un servicio con nombre, hasta el siguiente servicio de primer
 * nivel o el final del fichero. Mismo patron que `servicioAlertmanager()` en
 * AlertmanagerConfigTest.php.
 */
function bloqueDeServicio(string $compose, string $servicio): string
{
    // El lookahead que cierra el bloque exige una CLAVE YAML real a dos
    // espacios (letra u otro caracter de nombre seguido de `:`), no `^  [a-z#]`
    // a secas: con eso bastaba un comentario indentado a dos espacios dentro
    // del propio servicio -y los hay, "# Repite BACKUP_SCRIPT_PATH..." en
    // `app`- para que el bloque se cortara antes de llegar a `environment:`.
    $encontrado = preg_match(
        '/^  '.preg_quote($servicio, '/').':\n(?:.*\n)*?(?=^  [a-zA-Z0-9_-]+:|\z)/m',
        str_replace("\r\n", "\n", Repo::contents($compose)),
        $partes,
    );

    expect($encontrado)->toBe(1, "No se encuentra el servicio {$servicio} en {$compose}.");

    return $partes[0] ?? '';
}

/**
 * El bloque de la plantilla YAML compartida (`x-php-image`/`x-app-image`),
 * desde su ancla hasta el siguiente marcador de primer nivel (`services:`).
 */
function bloqueDePlantilla(string $compose, string $clave): string
{
    $encontrado = preg_match(
        '/^'.preg_quote($clave, '/').':.*\n(?:.*\n)*?(?=^\S)/m',
        str_replace("\r\n", "\n", Repo::contents($compose)),
        $partes,
    );

    expect($encontrado)->toBe(1, "No se encuentra la plantilla {$clave} en {$compose}.");

    return $partes[0] ?? '';
}

it('ninguna plantilla compartida lleva PGOPTIONS (si lo llevara, se colaria en horizon/reverb/scheduler)', function (string $compose, string $clave): void {
    $plantilla = bloqueDePlantilla($compose, $clave);

    expect($plantilla)->not->toContain('PGOPTIONS:');
})->with([
    ['infra/compose.dev.yaml', 'x-php-image'],
    ['infra/compose.prod.yaml', 'x-app-image'],
])->group('RNF-P-06', 'RNF-D-01');

it('app lleva PGOPTIONS con lock_timeout e idle_in_transaction_session_timeout', function (string $compose): void {
    $bloque = bloqueDeServicio($compose, 'app');

    expect($bloque)->toContain('environment:')
        ->toContain('PGOPTIONS:')
        ->toContain('lock_timeout=${DB_LOCK_TIMEOUT:-5s}')
        ->toContain('idle_in_transaction_session_timeout=${DB_IDLE_IN_TRANSACTION_TIMEOUT:-60s}');
})->with(['infra/compose.dev.yaml', 'infra/compose.prod.yaml'])->group('RNF-P-06', 'RNF-D-01');

it('horizon, reverb y scheduler NO llevan PGOPTIONS, en ninguno de los dos compose', function (string $compose, string $servicio): void {
    $bloque = bloqueDeServicio($compose, $servicio);

    expect($bloque)->not->toContain('PGOPTIONS:');
})->with([
    ['infra/compose.dev.yaml', 'horizon'],
    ['infra/compose.dev.yaml', 'reverb'],
    ['infra/compose.dev.yaml', 'scheduler'],
    ['infra/compose.prod.yaml', 'horizon'],
    ['infra/compose.prod.yaml', 'reverb'],
    ['infra/compose.prod.yaml', 'scheduler'],
])->group('RNF-P-06', 'RNF-D-01');

it('PGOPTIONS aparece EXACTAMENTE una vez por compose (solo en app, nunca duplicado)', function (string $compose): void {
    $veces = substr_count(Repo::contents($compose), 'PGOPTIONS:');

    expect($veces)->toBe(1);
})->with(['infra/compose.dev.yaml', 'infra/compose.prod.yaml'])->group('RNF-P-06', 'RNF-D-01');
