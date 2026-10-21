<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use App\Modules\Shared\Domain\Exception\BusinessRejection;
use Tests\Architecture\Support\ModuleTree;

/*
 * TODA EXCEPCION DEL PRODUCTO ESTA CLASIFICADA A PROPOSITO (V21-1-AR-1,
 * V21-1-RV-4, doc 02 §8).
 *
 * `UseCaseInstrumentation` cuenta `rejected` solo lo que implementa
 * `BusinessRejection`; todo lo demas es `error`, una averia. Esta prueba
 * recorre `app/` entero y exige que cada excepcion concreta este en uno de los
 * dos lados **por decision**: o lleva el marcador, o esta en la lista de
 * averias de abajo con su motivo. Una excepcion nueva sin ninguna de las dos
 * cosas hace fallar la prueba, y quien la escribe tiene que pensar que es.
 *
 * Y en los dos sentidos: una averia de la lista que lleve el marcador, o una
 * entrada de la lista que ya no exista, tambien fallan.
 */

/**
 * Las excepciones que son AVERIAS: algo del sistema o del programa, no una
 * respuesta de negocio. Clave: la clase sin `App\`. Valor: por que.
 */
const BUSINESS_REJECTION_FAILURES = [
    // --- Un estado imposible: si salta, lo que esta mal es el programa.
    'Modules\Attendance\Domain\Exception\InstantIsNotUtc' => 'un instante sin UTC es una conversion que alguien se salto (regla dura 3)',
    'Modules\Attendance\Domain\Exception\NegativeWorkedDuration' => 'dato imposible, lo impide el tipo',
    'Modules\Attendance\Domain\Exception\ScanIsNotOutOfOrder' => 'dato imposible, lo impide el tipo',
    'Modules\Attendance\Domain\Exception\ShiftEntryDoesNotBelongToWorkDay' => 'un tramo mal montado al reconstruir desde la base',
    'Modules\Compliance\Domain\Exception\AuditActionHasNoEvent' => 'accion del catalogo sin familia: defecto del codigo',
    'Modules\Compliance\Domain\Exception\AuditActorNotAllowedForAction' => 'asiento de sistema firmado por una persona: defecto del codigo',
    'Modules\Compliance\Domain\Exception\AuditInstantIsNotUtc' => 'asiento con instante sin UTC: defecto del codigo',
    'Modules\Compliance\Domain\Exception\AuditPayloadIsNotCanonical' => 'payload sin serializacion canonica: defecto de quien lo construye',
    'Modules\Compliance\Domain\Exception\InvalidSystemEventPayload' => 'payload de un script de sistema fuera de su lista cerrada',
    'Modules\Compliance\Domain\Exception\UnknownIncidentType' => 'hallazgo con un tipo que el catalogo no declara',
    'Modules\Compliance\Domain\Exception\InvalidIncident' => 'incidencia que la deteccion no deberia poder construir',
    'Modules\Reporting\Domain\Exception\MissingAdoptionIndicatorUnit' => 'indicador sin unidad declarada: defecto del codigo',
    'Modules\Reporting\Domain\Exception\InvalidAdoptionFacts' => 'recuentos imposibles: una consulta mal escrita',
    'Modules\Reporting\Domain\Exception\InvalidReportExportTransition' => 'transicion que ninguna peticion puede provocar',
    'Modules\Shared\Domain\Exception\InvalidElapsedTime' => 'duracion negativa medida sin pasar por los agregados',

    // --- Una configuracion de la instalacion que no puede ser.
    'Modules\Attendance\Domain\Exception\InvalidClockingPolicy' => 'umbrales de RN-07 y RN-08 incompatibles en la configuracion',
    'Modules\Attendance\Domain\Exception\InvalidDebounceWindow' => 'ventana anti-rebote negativa en la configuracion',
    'Modules\Attendance\Domain\Exception\InvalidFutureTolerance' => 'tolerancia mal configurada',
    'Modules\Attendance\Domain\Exception\InvalidSkewTolerance' => 'tolerancia mal configurada',
    'Modules\Compliance\Domain\Exception\InvalidRetentionPolicy' => 'plazo de retencion imposible en el perfil',
    'Modules\Identity\Domain\Exception\InvalidSigningKey' => 'clave HMAC configurada que no sirve',

    // --- Entrada y salida: disco, correo, base, capacidad.
    'Modules\Compliance\Application\Exception\AuditPartitionCreationUnavailable' => 'la base no deja crear la particion',
    'Modules\Kiosk\Application\Exception\PairingCapacityExhausted' => 'tope de capacidad del emparejamiento',
    'Modules\Kiosk\Application\Exception\PairingCodeSpaceExhausted' => 'espacio de codigos agotado',
    'Modules\Product\Application\Exception\LicenseUnavailable' => 'la licencia no se puede leer: la base no responde',
    'Modules\Product\Domain\Exception\DataExportWriteFailed' => 'disco lleno o sin permisos',
    'Modules\Product\Domain\Exception\DiagnosticsAuditNotDeferred' => 'no se pudo guardar el asiento pendiente en disco',
    'Modules\Reporting\Application\Exception\ReportRenderingBusy' => 'todos los huecos del embudo de PDF ocupados',
    'Modules\Reporting\Application\Exception\ReportRenderingUnavailable' => 'el embudo de PDF no responde',
    'Modules\Reporting\Domain\Exception\ReportExportWriteFailed' => 'disco lleno o sin permisos',
    'Modules\Reporting\Domain\Exception\WeeklySummaryNotDelivered' => 'el SMTP no envio el correo',
    'Modules\Workforce\Application\Exception\ImportFileUnavailable' => 'el temporal subido no se puede leer del disco: averia, no contenido',
    'Modules\Workforce\Domain\Exception\AbsenceWriteFailed' => 'la escritura fallo en el motor sin ser un caso de negocio',

    // --- El armazon, fuera de los modulos.
    'Support\Database\DatabaseUnavailable' => 'PostgreSQL inalcanzable',
    'Support\Redis\RedisCircuitOpen' => 'circuito de Redis abierto',
    'Support\Health\EndpointUnreachable' => 'una dependencia no responde',
    'Support\Environment\UnsafeProductionConfiguration' => 'configuracion de produccion insegura',
];

/**
 * Las excepciones del arbol `app/`, `clase sin App\ => ReflectionClass`.
 *
 * @return array<string, ReflectionClass<object>>
 */
function businessRejectionExceptions(): array
{
    $app = \dirname(ModuleTree::root());
    $found = [];

    foreach (ModuleTree::phpFilesUnder($app) as $file) {
        $source = (string) file_get_contents($file);

        if (preg_match('/^namespace (App\\\\[^;]+);/m', $source, $namespace) !== 1
            || preg_match('/^(?:(?:final|readonly|abstract)\s+)*class\s+(\w+)/m', $source, $class) !== 1) {
            continue;
        }

        $name = $namespace[1].'\\'.$class[1];

        if (! class_exists($name)) {
            continue;
        }

        $reflection = new ReflectionClass($name);

        if ($reflection->implementsInterface(Throwable::class)) {
            $found[substr($name, 4)] = $reflection;
        }
    }

    ksort($found);

    return $found;
}

it('encuentra las excepciones del producto (red de seguridad)', function (): void {
    expect(\count(businessRejectionExceptions()))->toBeGreaterThan(130);
})->group('RQ-06', 'RNF-D-01');

it('clasifica cada excepcion concreta: rechazo marcado o averia con motivo, nunca las dos ni ninguna', function (): void {
    $sinClasificar = [];
    $ambas = [];

    foreach (businessRejectionExceptions() as $class => $reflection) {
        if ($reflection->isAbstract()) {
            continue;
        }

        $rejection = $reflection->implementsInterface(BusinessRejection::class);
        $failure = \array_key_exists($class, BUSINESS_REJECTION_FAILURES);

        if ($rejection && $failure) {
            $ambas[] = $class;
        } elseif (! $rejection && ! $failure) {
            $sinClasificar[] = $class;
        }
    }

    expect($sinClasificar)->toBe([], "Excepciones sin clasificar. Si es un rechazo de negocio, implementa BusinessRejection;\n"
        .'si es una averia, anadela a BUSINESS_REJECTION_FAILURES con su motivo.')
        ->and($ambas)->toBe([], 'Una averia no puede llevar el marcador de rechazo.');
})->group('RQ-06', 'RNF-D-01');

it('no deja en la lista de averias clases que ya no existen', function (): void {
    $todas = businessRejectionExceptions();

    $sobran = array_values(array_filter(
        array_keys(BUSINESS_REJECTION_FAILURES),
        static fn (string $class): bool => ! \array_key_exists($class, $todas),
    ));

    expect($sobran)->toBe([]);
})->group('RQ-06', 'RNF-D-01');

it('el marcador va en las hojas y no en las bases de modulo, donde conviven rechazos y averias', function (): void {
    $bases = [];

    foreach (businessRejectionExceptions() as $class => $reflection) {
        if ($reflection->isAbstract() && $reflection->implementsInterface(BusinessRejection::class)) {
            $bases[] = $class;
        }
    }

    expect($bases)->toBe([]);
})->group('RQ-06', 'RNF-D-01');

it('UseCaseInstrumentation clasifica igual que esta prueba', function (): void {
    $distintas = [];

    foreach (businessRejectionExceptions() as $class => $reflection) {
        if ($reflection->isAbstract()) {
            continue;
        }

        $instance = $reflection->newInstanceWithoutConstructor();

        if (! $instance instanceof Throwable) {
            continue;
        }
        $esperado = ! \array_key_exists($class, BUSINESS_REJECTION_FAILURES);

        if (UseCaseInstrumentation::isRejection($instance) !== $esperado) {
            $distintas[] = $class;
        }
    }

    expect($distintas)->toBe([]);
})->group('RQ-06', 'RNF-D-01');
