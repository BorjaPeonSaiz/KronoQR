<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\UseCase\AddShiftEntryHandler;
use App\Modules\Attendance\Application\UseCase\CorrectShiftHandler;
use App\Modules\Attendance\Application\UseCase\RegisterPinScanHandler;
use App\Modules\Attendance\Application\UseCase\RegisterScanBatchHandler;
use App\Modules\Attendance\Application\UseCase\RegisterScanHandler;
use App\Modules\Attendance\Application\UseCase\ReportDiscardedScans;
use App\Modules\Attendance\Application\UseCase\VoidShiftHandler;
use App\Modules\Compliance\Application\UseCase\ApplyRetention;
use App\Modules\Compliance\Application\UseCase\NotifyPendingIncidents;
use App\Modules\Compliance\Application\UseCase\OpenIncident;
use App\Modules\Compliance\Application\UseCase\PlanRetention;
use App\Modules\Compliance\Application\UseCase\ReadIncidentBoard;
use App\Modules\Compliance\Application\UseCase\ReconcileWorkRecordWithAudit;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Application\UseCase\ReviewOutOfBoundsPurge;
use App\Modules\Identity\Application\UseCase\AuthenticatePortalEmployeeHandler;
use App\Modules\Identity\Application\UseCase\ChangeOwnPasswordHandler;
use App\Modules\Identity\Application\UseCase\CreateManagementAccountHandler;
use App\Modules\Identity\Application\UseCase\DeactivateManagementAccountHandler;
use App\Modules\Identity\Application\UseCase\DeliverCredential;
use App\Modules\Identity\Application\UseCase\IssueDeviceToken;
use App\Modules\Identity\Application\UseCase\PrintCredential;
use App\Modules\Identity\Application\UseCase\PrintCredentialBatch;
use App\Modules\Identity\Application\UseCase\RenderInstructionsSheet;
use App\Modules\Identity\Application\UseCase\ResetManagementPasswordHandler;
use App\Modules\Identity\Application\UseCase\ResetTwoFactorHandler;
use App\Modules\Identity\Application\UseCase\RetireSigningKey;
use App\Modules\Identity\Application\UseCase\RevokeCredentialsOfOffboardedEmployee;
use App\Modules\Identity\Application\UseCase\RevokeDeviceToken;
use App\Modules\Identity\Application\UseCase\RotateDeviceTokenIfDue;
use App\Modules\Identity\Application\UseCase\RotateSigningKey;
use App\Modules\Kiosk\Application\UseCase\ListDevices;
use App\Modules\Kiosk\Application\UseCase\RecordHeartbeat;
use App\Modules\Product\Application\UseCase\BuildTelemetryReportHandler;
use App\Modules\Product\Application\UseCase\GetBrandingHandler;
use App\Modules\Product\Application\UseCase\GetComplianceProfileHandler;
use App\Modules\Product\Application\UseCase\GetKioskManifestHandler;
use App\Modules\Product\Application\UseCase\RecordPlanUsageHandler;
use App\Modules\Product\Application\UseCase\RevokeSupportGrantsOfDeactivatedAccount;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricCatalogue;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricDefinition;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricType;
use App\Modules\Shared\Infrastructure\Metrics\RedisUseCaseMetrics;
use App\Modules\Workforce\Application\UseCase\EmployeeWriteRetry;
use App\Modules\Workforce\Application\UseCase\IssueEmployeePinHandler;
use Tests\Architecture\Support\ModuleTree;

/*
 * TODO CASO DE USO SE MIDE (doc 02 §8, bloque 24 de la 2.2.1).
 *
 * ## Que se exige
 *
 * Toda clase de `Modules/<Modulo>/Application/UseCase/` con un metodo publico
 * `handle`, `__invoke`, `execute` o `run` es un caso de uso —Compliance y Kiosk
 * no usan el sufijo `Handler`, asi que el sufijo no sirve para reconocerlos—, y
 * tiene que llamar a `UseCaseInstrumentation::run('<modulo>.<accion_snake>', …)`
 * con un literal. Las clases sin esos metodos —comandos, resultados, vistas— y
 * los enumerados e interfaces quedan fuera por construccion.
 *
 * ## La lista de excepciones solo puede encoger, y cada motivo se comprueba
 *
 * - `telemetria_propia`: el caso de uso ya abre su span y mide su duracion
 *   (`SpanScope::start` o un `*Telemetry` con `measure`). Un contador suelto no
 *   basta: sin duracion ni span, se envuelve con el ayudante.
 * - `telemetria_http`: un controlador lo envuelve con un `*Telemetry` que mide
 *   (`->measure…`), y no tiene entrada por consola ni por cola, donde ese
 *   controlador no lo veria.
 * - `sin_logica`: no es un caso de uso aunque viva aqui (un ayudante de
 *   reintento).
 * - `interno_anidado`: no tiene entrada propia —ningun controlador, comando,
 *   trabajo en cola, middleware, ruta ni migracion lo nombra—: solo lo invoca
 *   otro caso de uso ya medido, un oyente o un adaptador. Medirlo duplicaria la
 *   medida del acto de fuera; en el caso de `RecordAuditEntry`, una vez por
 *   asiento.
 *
 * La prueba falla en los dos sentidos: por un caso de uso sin medir que no esta
 * en la lista, y por una entrada de la lista que ya usa el ayudante, que ya no
 * existe o cuyo motivo ya no se sostiene. Y
 * `USE_CASE_INSTRUMENTATION_MAX_EXCEPTIONS` solo baja.
 */

const USE_CASE_INSTRUMENTATION_MAX_EXCEPTIONS = 41;

const USE_CASE_INSTRUMENTATION_PATTERN = '/^[a-z]+\.[a-z0-9_]+$/';

const USE_CASE_INSTRUMENTATION_REASONS = ['telemetria_propia', 'telemetria_http', 'sin_logica', 'interno_anidado'];

/** @var array<class-string, string> $excepciones */
$excepciones = [
    // --- Abren su span y miden su duracion.
    ApplyRetention::class => 'telemetria_propia',
    ReconcileWorkRecordWithAudit::class => 'telemetria_propia',
    ReviewOutOfBoundsPurge::class => 'telemetria_propia',
    AuthenticatePortalEmployeeHandler::class => 'telemetria_propia',
    ChangeOwnPasswordHandler::class => 'telemetria_propia',
    CreateManagementAccountHandler::class => 'telemetria_propia',
    DeactivateManagementAccountHandler::class => 'telemetria_propia',
    DeliverCredential::class => 'telemetria_propia',
    PrintCredential::class => 'telemetria_propia',
    PrintCredentialBatch::class => 'telemetria_propia',
    RenderInstructionsSheet::class => 'telemetria_propia',
    ResetManagementPasswordHandler::class => 'telemetria_propia',
    ResetTwoFactorHandler::class => 'telemetria_propia',
    RetireSigningKey::class => 'telemetria_propia',
    RevokeCredentialsOfOffboardedEmployee::class => 'telemetria_propia',
    RotateSigningKey::class => 'telemetria_propia',

    // --- Medidos desde el controlador con un `*Telemetry` de `Http/Support`.
    // Los cinco primeros son el camino del fichaje y el latido.
    RegisterScanHandler::class => 'telemetria_http',
    RegisterPinScanHandler::class => 'telemetria_http',
    RegisterScanBatchHandler::class => 'telemetria_http',
    ReportDiscardedScans::class => 'telemetria_http',
    RecordHeartbeat::class => 'telemetria_http',
    AddShiftEntryHandler::class => 'telemetria_http',
    CorrectShiftHandler::class => 'telemetria_http',
    VoidShiftHandler::class => 'telemetria_http',
    ReadIncidentBoard::class => 'telemetria_http',
    ListDevices::class => 'telemetria_http',
    GetBrandingHandler::class => 'telemetria_http',
    GetKioskManifestHandler::class => 'telemetria_http',
    GetComplianceProfileHandler::class => 'telemetria_http',

    // --- No es un caso de uso: el reintento de la transaccion de plantilla.
    EmployeeWriteRetry::class => 'sin_logica',

    // --- Sin entrada propia: solo los invoca otro acto ya medido.
    RecordAuditEntry::class => 'interno_anidado',
    OpenIncident::class => 'interno_anidado',
    NotifyPendingIncidents::class => 'interno_anidado',
    PlanRetention::class => 'interno_anidado',
    BuildTelemetryReportHandler::class => 'interno_anidado',
    RecordPlanUsageHandler::class => 'interno_anidado',
    RevokeSupportGrantsOfDeactivatedAccount::class => 'interno_anidado',
    IssueDeviceToken::class => 'interno_anidado',
    RotateDeviceTokenIfDue::class => 'interno_anidado',
    RevokeDeviceToken::class => 'interno_anidado',
    IssueEmployeePinHandler::class => 'interno_anidado',
];

/*
 * Los casos de uso del arbol, `clase => codigo fuente`. Se leen como texto y se
 * recorren con `scandir` (ver `ModuleTree`), no por reflexion.
 */
$casosDeUso = static function (): array {
    $found = [];

    foreach (ModuleTree::filesIn('') as $file) {
        $relative = ModuleTree::relative($file);

        if (preg_match('#^[A-Za-z]+/Application/UseCase/.+\.php$#', $relative) !== 1) {
            continue;
        }

        $source = (string) file_get_contents($file);

        if (preg_match('/^(?:(?:final|readonly|abstract)\s+)*class\s+\w+/m', $source) !== 1) {
            continue;
        }

        if (preg_match('/^\s+public\s+function\s+(?:handle|__invoke|execute|run)\s*\(/m', $source) !== 1) {
            continue;
        }

        $found['App\\Modules\\'.str_replace(['/', '.php'], ['\\', ''], $relative)] = $source;
    }

    ksort($found);

    return $found;
};

/*
 * Los ficheros que son una ENTRADA al producto, `ruta => codigo fuente`:
 * controladores y middleware, comandos de consola, trabajos en cola, rutas y
 * migraciones. Es donde un caso de uso empieza un acto propio.
 */
$entradas = static function (): array {
    $backend = \dirname(ModuleTree::root(), 2);
    $found = [];

    foreach (ModuleTree::filesIn('') as $file) {
        if (preg_match('#/(?:Http/Controller|Http/Middleware|Console|Job|Jobs|Middleware)/#', ModuleTree::relative($file)) === 1) {
            $found[$file] = (string) file_get_contents($file);
        }
    }

    foreach ([...ModuleTree::phpFilesUnder($backend.'/routes'), ...ModuleTree::phpFilesUnder($backend.'/database/migrations')] as $file) {
        $found[$file] = (string) file_get_contents($file);
    }

    return $found;
};

/**
 * Los literales que cada caso de uso pasa al ayudante.
 *
 * @return list<string>
 */
function useCaseInstrumentationLiterals(string $source): array
{
    preg_match_all("/UseCaseInstrumentation::run\\(\\s*'([^']*)'/", $source, $matches);

    return $matches[1];
}

/**
 * Si un fichero nombra la clase: importandola o por su nombre completo.
 */
function useCaseInstrumentationMentions(string $source, string $class): bool
{
    return str_contains($source, 'use '.$class.';') || str_contains($source, '\\'.$class);
}

it('reconoce los casos de uso del arbol (red de seguridad de la prueba)', function () use ($casosDeUso, $entradas): void {
    // Si el recorrido o el reconocimiento se rompen, las comprobaciones de abajo
    // pasarian sobre un conjunto vacio.
    $todos = $casosDeUso();
    $medidos = array_filter($todos, static fn (string $source): bool => useCaseInstrumentationLiterals($source) !== []);

    expect(\count($todos))->toBeGreaterThan(100)
        ->and(\count($medidos))->toBeGreaterThanOrEqual(85)
        ->and(\count($entradas()))->toBeGreaterThan(100);
})->group('RQ-06', 'RNF-D-01');

it('mide todo caso de uso con el ayudante, salvo la lista de excepciones', function () use ($casosDeUso, $excepciones): void {
    $sinMedir = [];

    foreach ($casosDeUso() as $class => $source) {
        if (useCaseInstrumentationLiterals($source) === [] && ! \array_key_exists($class, $excepciones)) {
            $sinMedir[] = $class;
        }
    }

    expect($sinMedir)->toBe([], 'Casos de uso sin UseCaseInstrumentation::run(...): instrumentalos (doc 02 §8).');
})->group('RQ-06', 'RNF-D-01');

it('no deja en la lista excepciones que ya miden o que ya no existen', function () use ($casosDeUso, $excepciones): void {
    $todos = $casosDeUso();
    $sobran = [];

    foreach (array_keys($excepciones) as $class) {
        if (! \array_key_exists($class, $todos)) {
            $sobran[] = $class.' (ya no existe o ya no es un caso de uso)';
        } elseif (useCaseInstrumentationLiterals($todos[$class]) !== []) {
            $sobran[] = $class.' (ya usa el ayudante)';
        }
    }

    expect($sobran)->toBe([]);
})->group('RQ-06', 'RNF-D-01');

it('solo admite motivos del catalogo cerrado y la lista no crece', function () use ($excepciones): void {
    $motivosDesconocidos = array_values(array_unique(array_diff($excepciones, USE_CASE_INSTRUMENTATION_REASONS)));

    expect($motivosDesconocidos)->toBe([])
        ->and(\count($excepciones))->toBeLessThanOrEqual(USE_CASE_INSTRUMENTATION_MAX_EXCEPTIONS);
})->group('RQ-06', 'RNF-D-01');

it('telemetria_propia: el caso de uso abre su span o mide con su Telemetry', function () use ($casosDeUso, $excepciones): void {
    $todos = $casosDeUso();
    $sinMedida = [];

    foreach ($excepciones as $class => $motivo) {
        if ($motivo !== 'telemetria_propia' || ! \array_key_exists($class, $todos)) {
            continue;
        }

        if (preg_match('/SpanScope::start|->telemetry->measure\w*\(/', $todos[$class]) !== 1) {
            $sinMedida[] = $class;
        }
    }

    expect($sinMedida)->toBe([], 'Sin span ni duracion propios: envuelvelos con UseCaseInstrumentation.');
})->group('RQ-06', 'RNF-D-01');

it('telemetria_http: lo mide un controlador y no entra por consola ni por cola', function () use ($excepciones, $entradas): void {
    $todasLasEntradas = $entradas();
    $malClasificados = [];

    foreach ($excepciones as $class => $motivo) {
        if ($motivo !== 'telemetria_http') {
            continue;
        }

        $medidoPorUnControlador = false;

        foreach ($todasLasEntradas as $file => $source) {
            if (! useCaseInstrumentationMentions($source, $class)) {
                continue;
            }

            if (str_contains($file, '/Http/Controller/')) {
                $medidoPorUnControlador = $medidoPorUnControlador
                    || preg_match('/\$telemetry->measure\w*\(/', $source) === 1;
            } else {
                $malClasificados[] = $class.' (tambien entra por '.ModuleTree::relative($file, \dirname(ModuleTree::root(), 2)).')';
            }
        }

        if (! $medidoPorUnControlador) {
            $malClasificados[] = $class.' (ningun controlador lo mide)';
        }
    }

    expect($malClasificados)->toBe([]);
})->group('RQ-06', 'RNF-D-01');

it('interno_anidado: ninguna entrada al producto lo nombra', function () use ($excepciones, $entradas): void {
    $todasLasEntradas = $entradas();
    $conEntradaPropia = [];

    foreach ($excepciones as $class => $motivo) {
        if ($motivo !== 'interno_anidado') {
            continue;
        }

        foreach ($todasLasEntradas as $file => $source) {
            if (useCaseInstrumentationMentions($source, $class)) {
                $conEntradaPropia[] = $class.' <- '.ModuleTree::relative($file, \dirname(ModuleTree::root(), 2));
            }
        }
    }

    expect($conEntradaPropia)->toBe([], 'Tiene entrada propia: instrumentalo.');
})->group('RQ-06', 'RNF-D-01');

it('nombra cada caso de uso con un literal unico de forma modulo.accion', function () use ($casosDeUso): void {
    $vistos = [];
    $malFormados = [];
    $repetidos = [];

    foreach ($casosDeUso() as $class => $source) {
        $module = strtolower(explode('\\', $class)[2]);

        foreach (useCaseInstrumentationLiterals($source) as $useCase) {
            if (preg_match(USE_CASE_INSTRUMENTATION_PATTERN, $useCase) !== 1 || ! str_starts_with($useCase, $module.'.')) {
                $malFormados[] = $class.': '.$useCase;
            }

            if (\array_key_exists($useCase, $vistos)) {
                $repetidos[] = $useCase.' ('.$vistos[$useCase].' y '.$class.')';
            }

            $vistos[$useCase] = $class;
        }
    }

    expect($malFormados)->toBe([])
        ->and($repetidos)->toBe([]);
})->group('RQ-06', 'RNF-D-01');

it('el adaptador valida con la misma forma y los mismos desenlaces que exige esta prueba', function (): void {
    expect(RedisUseCaseMetrics::USE_CASE_PATTERN)->toBe(USE_CASE_INSTRUMENTATION_PATTERN)
        ->and(RedisUseCaseMetrics::OUTCOMES)->toBe(['ok', 'rejected', 'error']);
})->group('RQ-06', 'RNF-D-01');

it('el catalogo declara la serie con exactamente use_case y outcome, sin datos de personas', function (): void {
    $definitions = array_values(array_filter(
        MetricCatalogue::all(),
        static fn (MetricDefinition $definition): bool => $definition->name === 'use_case_duration_seconds',
    ));

    expect($definitions)->toHaveCount(1)
        ->and($definitions[0]->type)->toBe(MetricType::Histogram)
        ->and($definitions[0]->labels)->toBe(['use_case', 'outcome'])
        ->and($definitions[0]->help)->toContain('rejected')
        ->and($definitions[0]->help)->toContain('Nunca lleva datos de personas');
})->group('RQ-06', 'RNF-D-01');
