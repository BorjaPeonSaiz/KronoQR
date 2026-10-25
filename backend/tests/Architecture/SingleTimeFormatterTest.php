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
 *    `Shared\Domain\Support\UtcInstant`, con la precision en `UtcPrecision`.
 *    Habia ~50 copias de `format('Y-m-d\TH:i:s[.u|.v]\Z')` y la mitad no pasaba
 *    antes a UTC: la `Z` es literal, y un objeto en otra zona salia con su hora
 *    local y una `Z` detras, incluido el payload de `audit_log`.
 * 2. **La hora de pared del centro**:
 *    `Shared\Domain\Support\SiteWallClock`. Estaba copiada cinco veces en
 *    Reporting y la exportacion legal convierte en PostgreSQL; que las dos
 *    coincidan lo prueba `SiteWallClockMatchesPostgresTest`. Subio a `Shared`
 *    cuando `kiosk:health` resulto ser la sexta copia, con el patron sacado de
 *    una traduccion y sin desfase: por eso el detector tambien ve un `format()`
 *    cuyo patron no es un literal, y vigila todos los modulos.
 * 3. **Una duracion `HH:MM`**: `Shared\Domain\ValueObject\HoursAndMinutes`.
 *    La exportacion legal y los informes tenian cada uno su `sprintf`.
 * 4. **Una hora rotulada «UTC» en un texto** (correo, consola, `detail` de un
 *    error, informe de texto): `UtcInstant::wallTime()`, con la forma en
 *    `UtcWallTime`. Habia diez `format('Y-m-d H:i')` o `format('H:i')` seguidos
 *    de « UTC» sin pasar antes a UTC. Fuera de `SiteWallClock`, que escribe la
 *    hora del centro, **ningun** `->format()` con hora puede ir sin zona: o
 *    lleva desfase (`P`, `O`, `e`, `T`) o pasa por uno de los dos.
 *
 * Se mira el TEXTO del codigo sin comentarios —los comentarios explican la
 * regla y la citan—, como `SingleMinuteRuleTest`.
 */

/** Las casas de cada forma, relativas a `app/Modules/`. */
const SINGLE_TIME_FORMATTER_UTC_HOMES = [
    'Shared/Domain/Support/UtcInstant.php',
    'Shared/Domain/ValueObject/UtcPrecision.php',
    'Shared/Domain/ValueObject/UtcWallTime.php',
];

const SINGLE_TIME_FORMATTER_WALL_CLOCK_HOME = 'Shared/Domain/Support/SiteWallClock.php';

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

    return preg_match('/->format\(\s*(?:UtcInstant::FORMAT|(?:UtcPrecision|UtcWallTime)::\w+->value)/', codigoSinComentariosDelTiempo($source)) === 1;
}

/**
 * Si el codigo escribe una hora con un patron que no dice su zona: ni `P`, ni
 * `O`, ni `e`, ni `T` sin escapar. Es la hora que luego se rotula «UTC» sin
 * serlo, o que se lee como local sin que nadie lo diga.
 */
function escribeHoraSinZona(string $code): bool
{
    preg_match_all('/->format\(\s*\'([^\']*H:?i[^\']*)\'\s*\)/', $code, $matches);

    return array_any(
        $matches[1],
        static fn (string $pattern): bool => preg_match('/[POeT]/', (string) preg_replace('/\\\\./', '', $pattern)) !== 1,
    );
}

/**
 * Si el codigo pasa un instante a una zona que no es UTC y lo escribe en la
 * misma sentencia con hora y minuto, o con un patron que no es un literal (una
 * traduccion, una variable): ese patron puede llevar la hora y no se ve.
 */
function convierteALaZonaDelCentro(string $code): bool
{
    return preg_match('/setTimezone\((?!\s*new \\\\?DateTimeZone\(\s*\'UTC\'\s*\)\s*\))[^;]*?->format\(\s*(?:\'[^\']*H:i|(?![\'"]))/', $code) === 1;
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

it('nadie convierte a la zona del centro fuera de SiteWallClock', function (): void {
    $found = copiasDelTiempo(
        static fn (string $source): bool => convierteALaZonaDelCentro(codigoSinComentariosDelTiempo($source)),
        [SINGLE_TIME_FORMATTER_WALL_CLOCK_HOME],
    );

    expect($found['vigilados'])->toBeGreaterThan(500)
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

it('nadie escribe una hora sin zona fuera de UtcInstant y de SiteWallClock', function (): void {
    $found = copiasDelTiempo(
        static fn (string $source): bool => escribeHoraSinZona(codigoSinComentariosDelTiempo($source)),
        [...SINGLE_TIME_FORMATTER_UTC_HOMES, SINGLE_TIME_FORMATTER_WALL_CLOCK_HOME],
    );

    expect($found['vigilados'])->toBeGreaterThan(500)
        ->and($found['copias'])->toBe([]);
})->group('RN-04');

it('cada forma vive donde dice la guarda', function (): void {
    // Si una casa se mueve, su exencion dejaria de apuntar a nada y la guarda
    // seguiria verde sin proteger nada.
    $root = ModuleTree::root();
    $read = static fn (string $relative): string => (string) file_get_contents($root.'/'.$relative);

    expect(escribeInstanteUtcAMano($read(SINGLE_TIME_FORMATTER_UTC_HOMES[1])))->toBeTrue()
        ->and(convierteALaZonaDelCentro(codigoSinComentariosDelTiempo(
            str_replace('$this->zone', 'new DateTimeZone($timeZone)', $read(SINGLE_TIME_FORMATTER_WALL_CLOCK_HOME)),
        )))->toBeTrue()
        ->and(escribeHorasYMinutos(codigoSinComentariosDelTiempo($read(SINGLE_TIME_FORMATTER_DURATION_HOME))))->toBeTrue()
        ->and(escribeHoraSinZona(codigoSinComentariosDelTiempo($read(SINGLE_TIME_FORMATTER_WALL_CLOCK_HOME))))->toBeTrue()
        ->and($read('Shared/Domain/Support/UtcInstant.php'))->toContain('function wallTime(');
})->group('RN-04', 'RL-06');

it('el detector de la hora sin zona ve las copias y deja pasar lo que no lo es', function (string $fragmento, bool $copia): void {
    expect(escribeHoraSinZona(codigoSinComentariosDelTiempo('<?php '.$fragmento)))->toBe($copia);
})->with([
    'minuto rotulado UTC' => ["return \$i->format('Y-m-d H:i').' UTC';", true],
    'solo la hora' => ["return \$i?->format('H:i');", true],
    'con segundos' => ["return \$i->format('Y-m-d H:i:s');", true],
    'ISO sin zona' => ["return \$i->format('Y-m-d\\TH:i:s');", true],
    'sello de fichero sin zona' => ["return 'informe-'.\$i->format('Ymd-His').'.txt';", true],
    'sello de fichero con Z escapada' => ["return \$i->format('Ymd\\THis\\Z');", true],
    'con desplazamiento para SQL' => ["return \$i->format('Y-m-d H:i:s.uP');", false],
    'con desplazamiento sin dos puntos' => ["return \$i->format('Y-m-d H:i O');", false],
    'con el nombre de la zona' => ["return \$i->format('Y-m-d H:i e');", false],
    'solo la fecha' => ["return \$i->format('Y-m-d');", false],
    'UtcInstant::wallTime' => ['return UtcInstant::wallTime($i, UtcWallTime::TimeOfDay);', false],
    'en un comentario' => ["// \$i->format('H:i')\n\$x = 1;", false],
])->group('RN-04');

it('el detector del instante ve las copias y deja pasar lo que no lo es', function (string $fragmento, bool $copia): void {
    expect(escribeInstanteUtcAMano('<?php '.$fragmento))->toBe($copia);
})->with([
    'microsegundos' => ["return \$i->format('Y-m-d\\TH:i:s.u\\Z');", true],
    'milisegundos con UTC' => ["return \$i->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s.v\\Z');", true],
    'segundos al parsear' => ["DateTimeImmutable::createFromFormat('Y-m-d\\TH:i:s\\Z', \$raw);", true],
    'comillas dobles' => ['return $i->format("Y-m-d\\\\TH:i:s\\\\Z");', true],
    'el patron sin pasar a UTC' => ['return $i->format(UtcInstant::FORMAT);', true],
    'la precision sin pasar a UTC' => ['return $i->format(UtcPrecision::Seconds->value);', true],
    'la hora rotulada sin pasar a UTC' => ['return $i->format(UtcWallTime::Minute->value);', true],
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
    'con el patron de una traduccion' => ["return \$i->setTimezone(\$zone)->format(\$this->say(\$t, 'absolute_format'));", true],
    'con el patron en una variable' => ['return $i->setTimezone(new DateTimeZone($tz))->format($pattern);', true],
    'con un patron en una constante, a UTC' => ["return \$i->setTimezone(new DateTimeZone('UTC'))->format(self::PATTERN);", false],
    'SiteWallClock con patron' => ['return $clock->stamp($i, $pattern);', false],
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
