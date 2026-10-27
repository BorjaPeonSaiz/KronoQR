<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Support\SiteWallClock;
use Illuminate\Support\Facades\DB;
use Tests\Architecture\Support\Repo;

/*
 * **PostgreSQL y PHP dicen la misma hora del centro** (RN-04, RF-IN-05, C7).
 *
 * La exportacion legal convierte en PostgreSQL —`to_char(… AT TIME ZONE
 * site_timezone, 'YYYY-MM-DD HH24:MI')`, con la base tz del servidor de base de
 * datos— y el historico del portal, los informes y sus PDF convierten en PHP
 * con {@see SiteWallClock}, con la base tz de PHP (timezonedb). Son dos copias
 * de la misma base que se actualizan por separado. Si se desfasan, el mismo
 * tramo sale a una hora en el fichero de la Inspeccion y a otra en el del
 * empleado, y nada mas lo detecta.
 *
 * Se comparan **todas las zonas que acepta el producto**
 * (`DateTimeZone::listIdentifiers()`, contra la que se valida la zona del
 * centro) en los instantes donde las bases tz difieren cuando difieren: los
 * cambios de hora de marzo y de octubre (el minuto antes, el del salto y el
 * siguiente), la medianoche de fin de año y un dia corriente de cada estacion.
 *
 * Integracion porque lo que se prueba es PostgreSQL de verdad; sin
 * `RefreshDatabase` porque no lee ni escribe ninguna tabla.
 */

/** La expresion que usa la exportacion legal, y que esta prueba vigila. */
const SITE_WALL_CLOCK_PG_PATTERN = "'YYYY-MM-DD HH24:MI'";

/**
 * Desfases conocidos entre las dos bases tz, zona por zona, con su causa.
 *
 * **No es una lista de excepciones comoda: es un hallazgo abierto.** Cada zona
 * que aparece aqui da en el registro legal una hora distinta de la del portal.
 * Se admite solo una zona que ningun centro del producto usa hoy, y se tolera
 * —no se exige— porque la base tz de la imagen de PostgreSQL depende del indice
 * de Alpine en el momento de construirla. Nunca una zona de España ni de
 * Portugal: esas tienen su propia afirmacion abajo.
 *
 * - `America/Inuvik`: tzdata 2026d (la de Alpine en la imagen de PostgreSQL)
 *   cambia su regla desde finales de 2026; PHP 8.4 trae compilada la 2026.3
 *   (`timezone_version_get()`) y todavia no. Se cierra cuando PHP traiga la
 *   2026.4 o la imagen instale `timezonedb` de PECL.
 * - `America/Winnipeg`: al reves que Inuvik. En la CI, PHP lee la base tz del
 *   sistema del runner (`timezone_version_get()` = `0.system`), mas nueva que
 *   la de la imagen de PostgreSQL, y ya aplica la regla nueva de Manitoba a
 *   finales de 2026 (UTC-5 el 31-12 frente a UTC-6). Aparecio el 27-10-2026
 *   sin cambios en el codigo. Se cierra cuando las dos bases coincidan.
 */
const SITE_WALL_CLOCK_KNOWN_SKEW = ['America/Inuvik', 'America/Winnipeg'];

/** @return list<string> */
function instantesDelRelojDelCentro(): array
{
    return [
        // Un dia corriente de invierno y otro de verano.
        '2026-01-15T07:00:00Z',
        '2026-07-15T21:45:00Z',
        // Marzo: Europa salta a las 01:00 UTC; America del Norte, el 8 de marzo.
        '2026-03-29T00:59:00Z',
        '2026-03-29T01:00:00Z',
        '2026-03-29T01:01:00Z',
        '2026-03-29T02:30:00Z',
        '2026-03-08T06:59:00Z',
        '2026-03-08T07:00:00Z',
        '2026-03-08T10:00:00Z',
        // Octubre: Europa vuelve a las 01:00 UTC; noviembre en America del Norte.
        '2026-10-25T00:30:00Z',
        '2026-10-25T00:59:00Z',
        '2026-10-25T01:00:00Z',
        '2026-10-25T01:30:00Z',
        '2026-11-01T05:30:00Z',
        '2026-11-01T06:30:00Z',
        // Hemisferio sur: abril y octubre (Sidney, Santiago).
        '2026-04-04T15:30:00Z',
        '2026-10-03T16:30:00Z',
        // Fin de año.
        '2026-12-31T23:30:00Z',
    ];
}

it('la exportacion legal convierte con la expresion que aqui se compara', function (): void {
    // Si la exportacion cambiara de patron, esta prueba compararia otra cosa.
    $source = Repo::contents('backend/app/Modules/Compliance/Infrastructure/Persistence/DatabaseLegalExportSource.php');

    expect($source)->toContain('AT TIME ZONE en.site_timezone, '.SITE_WALL_CLOCK_PG_PATTERN);
})->group('RN-04', 'RF-IN-05');

it('PostgreSQL y PHP dan la misma hora del centro en todas las zonas y en los cambios de hora', function (): void {
    $zones = DateTimeZone::listIdentifiers();
    $instants = instantesDelRelojDelCentro();

    /** @var list<object{zone: string, instant: string, local: string|null}> $rows */
    $rows = DB::select(
        'SELECT z.zone, i.instant,
                to_char(i.instant::timestamptz AT TIME ZONE z.zone, '.SITE_WALL_CLOCK_PG_PATTERN.') AS local
           FROM unnest(?::text[]) AS z(zone)
          CROSS JOIN unnest(?::text[]) AS i(instant)
          WHERE z.zone IN (SELECT name FROM pg_timezone_names)',
        ['{'.implode(',', $zones).'}', '{'.implode(',', $instants).'}'],
    );

    $known = array_values(array_unique(array_map(static fn (object $row): string => $row->zone, $rows)));
    $divergences = [];

    foreach ($rows as $row) {
        $php = new SiteWallClock($row->zone)->minute(new DateTimeImmutable($row->instant));

        if ($php !== $row->local && ! in_array($row->zone, SITE_WALL_CLOCK_KNOWN_SKEW, true)) {
            $divergences[] = "{$row->zone} {$row->instant}: PostgreSQL {$row->local}, PHP {$php}"
                .' (PHP timezonedb '.timezone_version_get().')';
        }
    }

    // Que la comparacion haya cubierto algo: las zonas del producto, casi todas.
    expect(count($known))->toBeGreaterThan((int) (count($zones) * 0.95))
        ->and($known)->toContain('Europe/Madrid', 'Atlantic/Canary', 'Europe/Lisbon')
        ->and($divergences)->toBe([]);
})->group('RN-04', 'RF-IN-05');

it('ningun desfase tolerado es de una zona de España ni de Portugal', function (): void {
    expect(SITE_WALL_CLOCK_KNOWN_SKEW)->each->not->toStartWith('Europe/')
        ->and(SITE_WALL_CLOCK_KNOWN_SKEW)->not->toContain('Atlantic/Canary', 'Atlantic/Madeira', 'Atlantic/Azores', 'Africa/Ceuta');
})->group('RN-04', 'RF-IN-05');

it('PostgreSQL conoce las zonas de los centros que vende el producto', function (): void {
    // Una zona que PHP acepta y PostgreSQL no conoce haria fallar la
    // exportacion legal de ese centro; la cobertura de arriba la toleraria.
    /** @var list<object{name: string}> $known */
    $known = DB::select('SELECT name FROM pg_timezone_names');

    $missing = array_values(array_diff(
        DateTimeZone::listIdentifiers(),
        array_map(static fn (object $row): string => $row->name, $known),
    ));

    expect($missing)->not->toContain('Europe/Madrid', 'Atlantic/Canary', 'Europe/Lisbon', 'UTC');
})->group('RN-04', 'RF-IN-05');
