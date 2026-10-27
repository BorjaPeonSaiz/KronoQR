<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;
use Tests\Architecture\Support\Repo;

/*
 * **LA LISTA CERRADA DE REGLAS DE `Shared/Domain`** (ADR-055 §3 y su enmienda
 * del 27-10-2026; V21-3-AR-2, V21-1-AR-2, V3-AR-3).
 *
 * ADR-021 deja en `Shared` los puertos y los tipos que comparten los modulos, no
 * sus reglas de negocio. ADR-055 abre una excepcion cerrada: una regla puede
 * vivir aqui si esta en la lista del ADR. La lista se quedo corta tres veces
 * sin que nada lo notara (`canClock`, `LegalRetentionFloor::admits`,
 * `SignOffReason`, `isReadyToReload`), porque la guarda que el ADR prometia no
 * existia. Es esta.
 *
 * ## Que se considera una señal de regla
 *
 * Sobre el TEXTO del codigo y sin comentarios (como `SingleMinuteRuleTest`):
 *
 * 1. cada clase de `Shared/Domain/Policy/`;
 * 2. cada metodo publico que devuelve `bool` —un predicado decide algo—;
 * 3. cada constante entera —un umbral con nombre—.
 *
 * Cada señal esta en la lista del ADR (regla admitida) o en
 * {@see SHARED_DOMAIN_RULES_LIST_QUERIES} (consulta que no es regla, con su motivo).
 * Lo que no esta en ninguna rompe la prueba: o es una consulta y se dice aqui,
 * o es una regla y necesita una enmienda de ADR-055.
 *
 * **Limite, escrito en el ADR:** no ve una regla escrita como validacion del
 * constructor sin constante con nombre ni como metodo que no devuelve `bool`.
 */

/** Donde vive la lista admitida: la prueba la lee del ADR, no la copia. */
const SHARED_DOMAIN_RULES_LIST_ADR = 'docs/adr/ADR-055-el-veredicto-de-cumplimiento-lo-da-la-vista-y-la-bandeja-no-lo-contradice.md';

/** El encabezado de la tabla que manda. */
const SHARED_DOMAIN_RULES_LIST_HEADING = '### La lista cerrada, a 27-10-2026';

/**
 * Señales de `Shared/Domain` que NO son reglas de negocio, con el motivo.
 * Clave: `Ruta\Clase::miembro`, relativa a `Shared\Domain`.
 */
const SHARED_DOMAIN_RULES_LIST_QUERIES = [
    'Support\ClientErrorCode::isKnown' => 'pertenencia a un catalogo tecnico de codigos de error',
    'Support\PathOverlap::between' => 'geometria de rutas del sistema de ficheros',
    'Support\PathOverlap::contains' => 'geometria de rutas del sistema de ficheros',
    'ValueObject\CredentialResolution::isResolved' => 'estado del resultado que ya decidio el verificador',
    'ValueObject\ErrorSource::isClient' => 'clasificacion del propio valor',
    'ValueObject\Feature::isImplemented' => 'catalogo de funcionalidades del producto, no una decision sobre datos',
    'ValueObject\GeneratedFileArea::admits' => 'forma del nombre de un fichero generado (tecnico)',
    'ValueObject\GeneratedFileArea::holdsDirectories' => 'forma del area de ficheros generados (tecnico)',
    'ValueObject\GeneratedFileEntry::isOlderThan' => 'edad de un fichero con el minimo que da quien llama (tecnico)',
    'ValueObject\HexColor::meetsContrast' => 'medida WCAG con el minimo que da quien llama',
    'ValueObject\HexColor::ADJUSTMENT_STEPS' => 'pasos del ajuste de color (tecnico)',
    'ValueObject\HolidayCalendar::isClean' => 'estado del propio calendario',
    'ValueObject\Jurisdiction::equals' => 'igualdad de valor',
    'ValueObject\KioskAppVersionSurvey::isEnforced' => 'si el sondeo tiene version minima con la que comparar',
    'ValueObject\PayrollColumn::isDuration' => 'formato del fichero de nomina',
    'ValueObject\PayrollColumn::isDate' => 'formato del fichero de nomina',
    'ValueObject\PayrollEncoding::hasByteOrderMark' => 'formato del fichero de nomina',
    'ValueObject\PayrollEncoding::isLatin1' => 'formato del fichero de nomina',
    'ValueObject\PayrollHoursFormat::isDecimal' => 'formato del fichero de nomina',
    'ValueObject\PayrollLayout::MAXIMUM_LABEL_LENGTH' => 'formato del fichero de nomina',
    'ValueObject\PinAttemptReservation::isLocked' => 'resultado que ya decidio PinLockoutPolicy',
    'ValueObject\PinAttemptReservation::opensLockout' => 'resultado que ya decidio PinLockoutPolicy',
    'ValueObject\PinLength::fits' => 'forma de un valor: cifras y longitud',
    'ValueObject\PinVerification::isVerified' => 'resultado que ya decidio el verificador',
    'ValueObject\PinVerification::isLocked' => 'resultado que ya decidio PinLockoutPolicy',
    'ValueObject\QueueStorage::isDurable' => 'clasificacion del propio valor',
    'ValueObject\ResponsibleReference::isUuid' => 'forma de un valor',
    'ValueObject\ResponsibleReference::EMAIL_MAX_LENGTH' => 'limite de la columna del esquema',
    'ValueObject\UserRole::isManagementRole' => 'definicion del propio tipo de rol',
];

function codigoSinComentariosDeShared(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= \is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/**
 * Las señales de regla de un fichero: `bool` publicos y constantes enteras.
 *
 * @return list<string> nombres de miembro
 */
function senalesDeRegla(string $code): array
{
    preg_match_all('/public\s+(?:static\s+)?function\s+(\w+)\s*\([^{;]*?\)\s*:\s*\??bool\b/s', $code, $methods);
    preg_match_all('/\bconst\s+(?:int\s+)?([A-Z][A-Z0-9_]*)\s*=\s*-?\d/', $code, $constants);

    return [...$methods[1], ...$constants[1]];
}

/**
 * Todas las señales de `Shared/Domain`, como `Ruta\Clase::miembro` o
 * `Ruta\Clase` para las clases de `Policy/`.
 *
 * @return list<string>
 */
function senalesDeSharedDomain(): array
{
    $root = ModuleTree::root().'/Shared/Domain';
    $signals = [];

    foreach (ModuleTree::phpFilesUnder($root) as $file) {
        $class = str_replace('/', '\\', substr(ModuleTree::relative($file, $root), 0, -4));

        if (str_starts_with($class, 'Policy\\')) {
            $signals[] = $class;
        }

        foreach (senalesDeRegla(codigoSinComentariosDeShared((string) file_get_contents($file))) as $member) {
            $signals[] = $class.'::'.$member;
        }
    }

    return $signals;
}

/**
 * La lista admitida, leida de la tabla del ADR: `Ruta\Clase` => miembros
 * nombrados en su fila ([] = la clase entera).
 *
 * @return array<string, list<string>>
 */
function reglasAdmitidasPorElAdr(): array
{
    $adr = (string) file_get_contents(Repo::file(SHARED_DOMAIN_RULES_LIST_ADR));
    $start = strpos($adr, SHARED_DOMAIN_RULES_LIST_HEADING);

    expect($start)->not->toBeFalse('ADR-055 ya no tiene el encabezado «'.SHARED_DOMAIN_RULES_LIST_HEADING.'».');

    $section = substr($adr, (int) $start + \strlen(SHARED_DOMAIN_RULES_LIST_HEADING));
    $section = preg_split('/^#{2,3} /m', $section)[0] ?? '';
    $admitted = [];

    foreach (preg_split('/\R/', $section) ?: [] as $line) {
        if (! str_starts_with($line, '|')) {
            continue;
        }

        $cells = array_map(trim(...), explode('|', $line));
        $classCell = $cells[2] ?? '';

        // `Shared\Domain\Ruta\Clase`: miembro, miembro · `Shared\…\Otra`: miembro
        foreach (preg_split('/\s·\s/u', $classCell) ?: [] as $part) {
            if (preg_match('/`Shared\\\\Domain\\\\([\w\\\\]+)`(.*)$/u', $part, $m) !== 1) {
                continue;
            }

            preg_match_all('/`(\w+)`/', $m[2], $members);
            $admitted[$m[1]] = $members[1];
        }
    }

    return $admitted;
}

it('cada señal de regla de Shared/Domain esta admitida en ADR-055 o declarada como consulta', function (): void {
    $admitted = reglasAdmitidasPorElAdr();
    $signals = senalesDeSharedDomain();
    $sinClasificar = [];

    foreach ($signals as $signal) {
        [$class] = explode('::', $signal);
        $member = str_contains($signal, '::') ? explode('::', $signal)[1] : null;

        $esRegla = \array_key_exists($class, $admitted)
            && ($admitted[$class] === [] || ($member !== null && \in_array($member, $admitted[$class], true)));

        if (! $esRegla && ! \array_key_exists($signal, SHARED_DOMAIN_RULES_LIST_QUERIES)) {
            $sinClasificar[] = $signal;
        }
    }

    // Que «nada sin clasificar» signifique algo: se ha mirado de verdad.
    expect(\count($signals))->toBeGreaterThan(40)
        ->and($sinClasificar)->toBe([], 'Señales de regla en Shared/Domain sin clasificar: '
            .implode(', ', $sinClasificar).'. Si es una consulta, añadela a SHARED_DOMAIN_RULES_LIST_QUERIES '
            .'con su motivo; si es una regla, necesita una enmienda de ADR-055 que la añada a la lista.');
})->group('RNF-M-03', 'RNF-M-04');

it('la lista de ADR-055 y la de consultas solo nombran lo que existe', function (): void {
    $root = ModuleTree::root().'/Shared/Domain';
    $signals = senalesDeSharedDomain();
    $obsoletas = [];

    foreach (reglasAdmitidasPorElAdr() as $class => $members) {
        if (! is_file($root.'/'.str_replace('\\', '/', $class).'.php')) {
            $obsoletas[] = $class;

            continue;
        }

        foreach ($members as $member) {
            // Un miembro admitido tiene que ser una señal: si deja de serlo, la
            // fila del ADR describe algo que ya no es asi.
            if (! \in_array($class.'::'.$member, $signals, true)) {
                $obsoletas[] = $class.'::'.$member;
            }
        }
    }

    foreach (array_keys(SHARED_DOMAIN_RULES_LIST_QUERIES) as $query) {
        if (! \in_array($query, $signals, true)) {
            $obsoletas[] = $query;
        }
    }

    expect($obsoletas)->toBe([], 'Entradas que ya no existen en Shared/Domain: '.implode(', ', $obsoletas));
})->group('RNF-M-03', 'RNF-M-04');

it('la tabla del ADR se lee entera, con las reglas que se habian colado', function (): void {
    // Si la tabla cambia de forma y el lector deja de verla, la primera prueba
    // daria por consultas lo que son reglas, o al reves. Una fila con miembros
    // admite esos miembros y no un `canWork` nuevo de la misma clase.
    expect(reglasAdmitidasPorElAdr())
        ->toHaveKey('ValueObject\EmploymentStatus', ['canClock'])
        ->toHaveKey('ValueObject\EmployeeSnapshot', ['canClock'])
        ->toHaveKey('ValueObject\LegalRetentionFloor', ['admits'])
        ->toHaveKey('ValueObject\SignOffReason', ['MIN_LENGTH', 'MAX_LENGTH'])
        ->toHaveKey('ValueObject\KioskAppVersionEntry', ['isReadyToReload'])
        ->toHaveKey('Policy\PinLockoutPolicy', [])
        ->toHaveKey('ValueObject\ElapsedMinutes', []);
})->group('RNF-M-03', 'RNF-M-04');

it('el detector ve predicados y umbrales y deja pasar lo que no lo es', function (string $fragmento, array $esperado): void {
    expect(senalesDeRegla(codigoSinComentariosDeShared('<?php '.$fragmento)))->toBe($esperado);
})->with([
    'predicado' => ['public function canClock(): bool { return true; }', ['canClock']],
    'predicado estatico y en varias lineas' => ["public static function admits(\n    int \$years,\n): bool {}", ['admits']],
    'nullable' => ['public function isX(): ?bool {}', ['isX']],
    'constante tipada' => ['public const int MIN_LENGTH = 10;', ['MIN_LENGTH']],
    'constante privada sin tipo' => ['private const LIMIT = 3;', ['LIMIT']],
    'privado' => ['private function isY(): bool {}', []],
    'no bool' => ['public function minutes(): int {}', []],
    'constante de texto' => ["public const string NAME = 'x';", []],
    'en un comentario' => ["// public function canZ(): bool {}\n\$a = 1;", []],
])->group('RNF-M-03');
