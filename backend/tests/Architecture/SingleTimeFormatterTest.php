<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;

/*
 * **UN SOLO FORMATEADOR POR FORMA DE ESCRIBIR EL TIEMPO** (RN-04, RL-06; C2, C7
 * y R1-RV-05 de la verificacion, tarea 19.1 de la 2.3.0).
 *
 * Tres formas, tres casas:
 *
 * 1. **El instante del contrato** (`UtcTimestamp`, `…Z`):
 *    `Shared\Domain\ValueObject\UtcInstant`, con la precision en `UtcPrecision`.
 *    Habia ~50 copias de `format('Y-m-d\TH:i:s[.u|.v]\Z')` y la mitad no pasaba
 *    antes a UTC: la `Z` es literal, y un objeto en otra zona salia con su hora
 *    local y una `Z` detras, incluido el payload de `audit_log`.
 * 2. **La hora de pared del centro en Reporting**:
 *    `Reporting\Application\Support\SiteWallClock`. Estaba copiada cinco veces
 *    y la exportacion legal convierte en PostgreSQL; que las dos coincidan lo
 *    prueba `SiteWallClockMatchesPostgresTest`.
 * 3. **Una duracion `HH:MM`**: `Shared\Domain\ValueObject\HoursAndMinutes`.
 *    La exportacion legal y los informes tenian cada uno su `sprintf`.
 *
 * Se mira el TEXTO del codigo sin comentarios —los comentarios explican la
 * regla y la citan—, como `SingleMinuteRuleTest`.
 */

/** Las casas de cada forma, relativas a `app/Modules/`. */
const SINGLE_TIME_FORMATTER_UTC_HOMES = [
    'Shared/Domain/ValueObject/UtcInstant.php',
    'Shared/Domain/ValueObject/UtcPrecision.php',
];

const SINGLE_TIME_FORMATTER_WALL_CLOCK_HOME = 'Reporting/Application/Support/SiteWallClock.php';

const SINGLE_TIME_FORMATTER_DURATION_HOME = 'Shared/Domain/ValueObject/HoursAndMinutes.php';

function codigoSinComentariosDelTiempo(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= \is_array($token) ? $token[1] : $token;
    }

    return (string) preg_replace('/\s+/', ' ', $code);
}

/**
 * Si el codigo escribe a mano un instante ISO con `Z` literal, o usa el patron
 * de `UtcInstant` sin pasar por el.
 */
function escribeInstanteUtcAMano(string $source): bool
{
    foreach (token_get_all($source) as $token) {
        if (\is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING
            && str_contains($token[1], 'H:i:s') && str_contains($token[1], '\Z')) {
            return true;
        }
    }

    return preg_match('/->format\(\s*(?:UtcInstant::FORMAT|UtcPrecision::\w+->value)/', codigoSinComentariosDelTiempo($source)) === 1;
}

/**
 * Si el codigo pasa un instante a una zona que no es UTC y lo escribe con hora
 * y minuto en la misma sentencia.
 */
function convierteALaZonaDelCentro(string $code): bool
{
    return preg_match('/setTimezone\((?!\s*new \\\\?DateTimeZone\(\s*\'UTC\'\s*\)\s*\))[^;]*?->format\(\s*\'[^\']*H:i/', $code) === 1;
}

/** Si el codigo escribe una duracion `HH:MM` con su propio `sprintf`. */
function escribeHorasYMinutos(string $code): bool
{
    return preg_match('/%0?\d*d:%02d/', $code) === 1;
}

/**
 * El codigo de `app/`, leido una sola vez: las tres guardas recorren el mismo
 * arbol y sobre el *bind mount* cada lectura cuesta.
 *
 * @return array<string, string> ruta relativa a `app/Modules/` => fuente
 */
function fuentesDeApp(): array
{
    static $sources = null;

    if ($sources === null) {
        $root = ModuleTree::root();
        $sources = [];

        foreach (ModuleTree::phpFilesUnder(\dirname($root)) as $file) {
            $sources[ModuleTree::relative($file, $root)] = (string) file_get_contents($file);
        }
    }

    return $sources;
}

/**
 * Los ficheros que el detector señala, sin contar las casas.
 *
 * @param  Closure(string): bool  $detector  recibe la fuente completa
 * @param  list<string>  $homes
 * @return array{vigilados: int, copias: list<string>}
 */
function copiasDelTiempo(Closure $detector, array $homes, string $prefix = ''): array
{
    $vigilados = 0;
    $copias = [];

    foreach (fuentesDeApp() as $relative => $source) {
        if (\in_array($relative, $homes, true) || ! str_starts_with($relative, $prefix)) {
            continue;
        }

        $vigilados++;

        if ($detector($source)) {
            $copias[] = $relative;
        }
    }

    return ['vigilados' => $vigilados, 'copias' => $copias];
}

it('nadie fuera de UtcInstant escribe el instante del contrato a mano', function (): void {
    $found = copiasDelTiempo(escribeInstanteUtcAMano(...), SINGLE_TIME_FORMATTER_UTC_HOMES);

    expect($found['vigilados'])->toBeGreaterThan(500)
        ->and($found['copias'])->toBe([]);
})->group('RN-04', 'RQ-06');

it('Reporting convierte a la zona del centro solo en SiteWallClock', function (): void {
    $found = copiasDelTiempo(
        static fn (string $source): bool => convierteALaZonaDelCentro(codigoSinComentariosDelTiempo($source)),
        [SINGLE_TIME_FORMATTER_WALL_CLOCK_HOME],
        'Reporting/',
    );

    expect($found['vigilados'])->toBeGreaterThan(100)
        ->and($found['copias'])->toBe([]);
})->group('RN-04');

it('nadie fuera de HoursAndMinutes escribe una duracion HH:MM', function (): void {
    $found = copiasDelTiempo(
        static fn (string $source): bool => escribeHorasYMinutos(codigoSinComentariosDelTiempo($source)),
        [SINGLE_TIME_FORMATTER_DURATION_HOME],
    );

    expect($found['vigilados'])->toBeGreaterThan(500)
        ->and($found['copias'])->toBe([]);
})->group('RL-06', 'RF-IN-04');

it('cada forma vive donde dice la guarda', function (): void {
    // Si una casa se mueve, su exencion dejaria de apuntar a nada y la guarda
    // seguiria verde sin proteger nada.
    $root = ModuleTree::root();
    $read = static fn (string $relative): string => (string) file_get_contents($root.'/'.$relative);

    expect(escribeInstanteUtcAMano($read(SINGLE_TIME_FORMATTER_UTC_HOMES[1])))->toBeTrue()
        ->and(convierteALaZonaDelCentro(codigoSinComentariosDelTiempo(
            str_replace('$this->zone', 'new DateTimeZone($timeZone)', $read(SINGLE_TIME_FORMATTER_WALL_CLOCK_HOME)),
        )))->toBeTrue()
        ->and(escribeHorasYMinutos(codigoSinComentariosDelTiempo($read(SINGLE_TIME_FORMATTER_DURATION_HOME))))->toBeTrue();
})->group('RN-04', 'RL-06');

it('el detector del instante ve las copias y deja pasar lo que no lo es', function (string $fragmento, bool $copia): void {
    expect(escribeInstanteUtcAMano('<?php '.$fragmento))->toBe($copia);
})->with([
    'microsegundos' => ["return \$i->format('Y-m-d\\TH:i:s.u\\Z');", true],
    'milisegundos con UTC' => ["return \$i->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z');", true],
    'segundos al parsear' => ["DateTimeImmutable::createFromFormat('Y-m-d\\TH:i:s\\Z', \$raw);", true],
    'comillas dobles' => ['return $i->format("Y-m-d\\\\TH:i:s\\\\Z");', true],
    'el patron sin pasar a UTC' => ['return $i->format(UtcInstant::FORMAT);', true],
    'la precision sin pasar a UTC' => ['return $i->format(UtcPrecision::Seconds->value);', true],
    'UtcInstant' => ['return UtcInstant::of($i, UtcPrecision::Seconds);', false],
    'parsear con el patron' => ["DateTimeImmutable::createFromFormat('!'.UtcInstant::FORMAT, \$raw);", false],
    'ATOM con desplazamiento' => ['return $i->format(DateTimeInterface::ATOM);', false],
    'SQL con la Z entre comillas dobles' => ["\$sql = 'to_char(x AT TIME ZONE \\'UTC\\', \"YYYY-MM-DD\")';", false],
    'en un comentario' => ["// \$i->format('Y-m-d\\TH:i:s\\Z')\n\$x = 1;", false],
])->group('RN-04');

it('el detector de la zona del centro ve las copias y deja pasar lo que no lo es', function (string $fragmento, bool $copia): void {
    expect(convierteALaZonaDelCentro(codigoSinComentariosDelTiempo('<?php '.$fragmento)))->toBe($copia);
})->with([
    'minuto de pared' => ["return \$i->setTimezone(new DateTimeZone(\$tz))->format('Y-m-d H:i');", true],
    'con desplazamiento' => ["return \$i->setTimezone(new DateTimeZone(\$tz))->format('Y-m-d\\TH:i:s.uP');", true],
    'en varias lineas' => ["return \$i\n    ->setTimezone(\$zone)\n    ->format('H:i');", true],
    'solo la fecha de hoy' => ["return \$now->setTimezone(new DateTimeZone(\$tz))->format('Y-m-d');", false],
    'a UTC' => ["return \$i->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');", false],
    'SiteWallClock' => ['return new SiteWallClock($tz)->minute($i);', false],
])->group('RN-04');

it('el detector de HH:MM ve las copias y deja pasar lo que no lo es', function (string $fragmento, bool $copia): void {
    expect(escribeHorasYMinutos(codigoSinComentariosDelTiempo('<?php '.$fragmento)))->toBe($copia);
})->with([
    'con ceros' => ["return sprintf('%02d:%02d', intdiv(\$m, 60), \$m % 60);", true],
    'sin cero en las horas' => ["return sprintf('%d:%02d', intdiv(\$m, 60), \$m % 60);", true],
    'HoursAndMinutes' => ['return HoursAndMinutes::ofMinutes($m)->toClockText();', false],
    'una fecha' => ["return sprintf('%04d-%02d', \$y, \$m);", false],
    'en un comentario' => ["// sprintf('%02d:%02d', \$h, \$m)\n\$x = 1;", false],
])->group('RL-06');
