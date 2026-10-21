<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\UseCase\NotifyPendingIncidents;
use App\Modules\Compliance\Application\UseCase\OpenIncident;
use App\Modules\Compliance\Application\UseCase\PlanRetention;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Identity\Application\UseCase\IssueDeviceToken;
use App\Modules\Identity\Application\UseCase\RevokeDeviceToken;
use App\Modules\Kiosk\Application\UseCase\BuildKioskRoster;
use App\Modules\Product\Application\UseCase\BuildTelemetryReportHandler;
use App\Modules\Product\Application\UseCase\RecordPlanUsageHandler;
use App\Modules\Product\Application\UseCase\RevokeSupportGrantsOfDeactivatedAccount;
use App\Modules\Reporting\Application\Query\GeneratePeriodReport;
use App\Modules\Reporting\Application\Query\ReadEmployeeWorkDays;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricCatalogue;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricDefinition;
use App\Modules\Shared\Infrastructure\Metrics\Exposition\MetricType;
use App\Modules\Shared\Infrastructure\Metrics\RedisUseCaseMetrics;
use App\Modules\Workforce\Application\UseCase\EmployeeWriteRetry;
use App\Modules\Workforce\Application\UseCase\IssueEmployeePinHandler;
use Tests\Architecture\Support\ModuleTree;

/*
 * TODO CASO DE USO Y TODA CONSULTA SE MIDEN CON EL MISMO AYUDANTE (doc 02 §8,
 * bloque 24 de la 2.2.1 y bloque 10 de la 2.3.0).
 *
 * ## Que se exige
 *
 * 1. Toda clase de `Modules/<Modulo>/Application/UseCase/` con un metodo publico
 *    `handle`, `__invoke`, `execute` o `run` es un caso de uso —Compliance y
 *    Kiosk no usan el sufijo `Handler`, asi que el sufijo no sirve para
 *    reconocerlos—, y tiene que llamar a
 *    `UseCaseInstrumentation::run('<modulo>.<accion_snake>', …)` con un literal.
 *    Las clases sin esos metodos —comandos, resultados, vistas— quedan fuera por
 *    construccion, salvo las que entran por otro nombre
 *    (`USE_CASE_INSTRUMENTATION_OTHER_ENTRIES`: el padron del quiosco entra por
 *    `BuildKioskRoster::forSite`, V21-4-BE-1).
 * 2. Toda clase de `Modules/<Modulo>/Application/Query/` que es un **servicio**
 *    —recibe sus dependencias por el constructor— mide **cada** metodo publico:
 *    la presencia en vivo, las cuentas de gestion o las `*Queries` de Workforce
 *    solo las veia `http_request_duration_seconds` (V21-4-BE-1). Las vistas y
 *    los criterios, que no tienen dependencias, quedan fuera.
 * 3. Nadie fuera del andamiaje abre un span con `SpanScope::start`: las 28
 *    telemetrias con span propio pasaron al ayudante (V1-RV-03, V4-BE-6). Solo
 *    lo abren `UseCaseInstrumentation` y el armazon (el span de servidor, el de
 *    cada consulta y el de cada tarea programada), que no son casos de uso.
 *
 * ## La lista de excepciones solo puede encoger, y cada motivo se comprueba
 *
 * - `sin_logica`: no es un caso de uso aunque viva aqui (un ayudante de
 *   reintento, un calculo sin entrada ni salida).
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

const USE_CASE_INSTRUMENTATION_MAX_EXCEPTIONS = 11;

const USE_CASE_INSTRUMENTATION_MAX_QUERY_EXCEPTIONS = 2;

const USE_CASE_INSTRUMENTATION_PATTERN = '/^[a-z]+\.[a-z0-9_]+$/';

const USE_CASE_INSTRUMENTATION_REASONS = ['sin_logica', 'interno_anidado'];

/** Casos de uso que entran por un metodo que no se llama `handle`. */
const USE_CASE_INSTRUMENTATION_OTHER_ENTRIES = [
    BuildKioskRoster::class => 'forSite',
];

/**
 * Los unicos ficheros de `app/` que abren un span con `SpanScope`, y por que.
 */
const USE_CASE_INSTRUMENTATION_SPAN_OPENERS = [
    'Modules/Shared/Application/Support/SpanScope.php' => 'el andamiaje',
    'Modules/Shared/Application/Support/UseCaseInstrumentation.php' => 'la medida comun de los casos de uso',
    'Http/Middleware/PropagateTraceContext.php' => 'el span de servidor de cada peticion',
    'Support/Observability/Tracing/DatabaseSpans.php' => 'el span de cada consulta',
    'Support/Observability/Tracing/ScheduledTaskSpans.php' => 'el span de cada tarea programada',
    'Support/Observability/Tracing/WorkUnitSpans.php' => 'el span de cada orden de Artisan y de cada trabajo de cola',
];

/** @var array<class-string, string> $excepciones */
$excepciones = [
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
    RevokeDeviceToken::class => 'interno_anidado',
    IssueEmployeePinHandler::class => 'interno_anidado',
];

/**
 * Metodos de consulta que no se miden, `Clase::metodo => motivo`.
 *
 * @var array<string, string> $excepcionesDeConsulta
 */
$excepcionesDeConsulta = [
    // Lo invocan `handle()` de la propia clase y `SendWeeklySummaries`, los dos
    // medidos: es la mitad sin asiento del mismo acto.
    GeneratePeriodReport::class.'::compose' => 'interno_anidado',
    // La fecha de hoy en una zona: un calculo con el reloj, sin entrada ni salida.
    ReadEmployeeWorkDays::class.'::todayIn' => 'sin_logica',
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

        $class = 'App\\Modules\\'.str_replace(['/', '.php'], ['\\', ''], $relative);
        $entry = USE_CASE_INSTRUMENTATION_OTHER_ENTRIES[$class] ?? '(?:handle|__invoke|execute|run)';

        if (preg_match('/^\s+public\s+function\s+'.$entry.'\s*\(/m', $source) !== 1) {
            continue;
        }

        $found[$class] = $source;
    }

    ksort($found);

    return $found;
};

/*
 * Las consultas que son un servicio, `clase => codigo fuente`: en
 * `Application/Query/` y con dependencias en el constructor (`private`). Las
 * vistas y los criterios las declaran `public readonly`.
 */
$consultas = static function (): array {
    $found = [];

    foreach (ModuleTree::filesIn('') as $file) {
        $relative = ModuleTree::relative($file);

        if (preg_match('#^[A-Za-z]+/Application/Query/.+\.php$#', $relative) !== 1) {
            continue;
        }

        $source = (string) file_get_contents($file);

        if (preg_match('/public function __construct\((?:[^()]|\([^()]*\))*\bprivate\b/s', $source) !== 1) {
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
 * Los literales que un fichero pasa al ayudante.
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

/**
 * El codigo de un fichero sin comentarios: el docblock del ayudante pone un
 * ejemplo de uso que no es un acto medido.
 */
function useCaseInstrumentationCode(string $file): string
{
    $code = '';

    foreach (token_get_all((string) file_get_contents($file)) as $token) {
        if (! \is_array($token) || ! \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $code .= \is_array($token) ? $token[1] : $token;
        }
    }

    return $code;
}

/**
 * Los metodos publicos no estaticos de una clase con el cuerpo de cada uno,
 * `metodo => cuerpo`. El cuerpo llega hasta el siguiente metodo: basta para ver
 * si llama al ayudante.
 *
 * @return array<string, string>
 */
function useCaseInstrumentationPublicMethods(string $source): array
{
    preg_match_all('/^    public function (\w+)\(/m', $source, $matches, PREG_OFFSET_CAPTURE);

    $methods = [];

    foreach ($matches[1] as $index => [$name, $offset]) {
        if ($name === '__construct') {
            continue;
        }

        $next = preg_match('/^    (?:public|private|protected) (?:static )?function /m', $source, $following, PREG_OFFSET_CAPTURE, $offset + \strlen($name));
        $end = $next === 1 ? $following[0][1] : \strlen($source);
        $methods[$name] = substr($source, $offset, $end - $offset);
    }

    return $methods;
}

it('reconoce los casos de uso y las consultas del arbol (red de seguridad de la prueba)', function () use ($casosDeUso, $consultas, $entradas): void {
    // Si el recorrido o el reconocimiento se rompen, las comprobaciones de abajo
    // pasarian sobre un conjunto vacio.
    $todos = $casosDeUso();
    $medidos = array_filter($todos, static fn (string $source): bool => useCaseInstrumentationLiterals($source) !== []);

    expect(\count($todos))->toBeGreaterThan(100)
        ->and(\count($medidos))->toBeGreaterThanOrEqual(115)
        ->and($todos)->toHaveKey(BuildKioskRoster::class)
        ->and(\count($consultas()))->toBeGreaterThanOrEqual(12)
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

it('mide el padron del quiosco en su metodo de entrada, forSite', function () use ($casosDeUso): void {
    foreach (USE_CASE_INSTRUMENTATION_OTHER_ENTRIES as $class => $method) {
        $methods = useCaseInstrumentationPublicMethods($casosDeUso()[$class] ?? '');

        expect($methods)->toHaveKey($method)
            ->and(useCaseInstrumentationLiterals($methods[$method] ?? ''))->not->toBe([], $class.'::'.$method.' sin medir.');
    }
})->group('RQ-06', 'RNF-D-01');

it('mide cada metodo publico de cada consulta, salvo la lista de excepciones', function () use ($consultas, $excepcionesDeConsulta): void {
    $sinMedir = [];
    $sobran = [];

    foreach ($consultas() as $class => $source) {
        foreach (useCaseInstrumentationPublicMethods($source) as $method => $body) {
            $key = $class.'::'.$method;
            $measured = useCaseInstrumentationLiterals($body) !== [];

            if (! $measured && ! \array_key_exists($key, $excepcionesDeConsulta)) {
                $sinMedir[] = $key;
            }

            if ($measured && \array_key_exists($key, $excepcionesDeConsulta)) {
                $sobran[] = $key.' (ya usa el ayudante)';
            }
        }
    }

    foreach (array_keys($excepcionesDeConsulta) as $key) {
        [$class, $method] = explode('::', $key);

        if (! \array_key_exists($method, useCaseInstrumentationPublicMethods($consultas()[$class] ?? ''))) {
            $sobran[] = $key.' (ya no existe)';
        }
    }

    expect($sinMedir)->toBe([], 'Consultas sin UseCaseInstrumentation::run(...): instrumentalas (doc 02 §8).')
        ->and($sobran)->toBe([])
        ->and(array_values(array_unique(array_diff($excepcionesDeConsulta, USE_CASE_INSTRUMENTATION_REASONS))))->toBe([])
        ->and(\count($excepcionesDeConsulta))->toBeLessThanOrEqual(USE_CASE_INSTRUMENTATION_MAX_QUERY_EXCEPTIONS);
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

it('nadie fuera del andamiaje abre un span propio con SpanScope', function (): void {
    // V1-RV-03: el envoltorio de telemetria estaba copiado 27 veces, cada copia
    // con su nivel de log y su regla de privacidad en un comentario. Ahora el
    // span del acto lo abre `UseCaseInstrumentation`, y las telemetrias solo
    // añaden atributos al span en curso (`SpanScope::annotateCurrent`).
    $app = \dirname(ModuleTree::root());
    $abren = [];

    foreach (ModuleTree::phpFilesUnder($app) as $file) {
        $relative = ModuleTree::relative($file, $app);

        if (\array_key_exists($relative, USE_CASE_INSTRUMENTATION_SPAN_OPENERS)) {
            continue;
        }

        if (preg_match('/SpanScope::start(?:Active)?\(/', (string) file_get_contents($file)) === 1) {
            $abren[] = $relative;
        }
    }

    expect($abren)->toBe([], 'Mide con UseCaseInstrumentation::run(...) en lugar de abrir un span propio.');
})->group('RQ-06', 'RNF-D-01');

it('nombra cada acto medido con un literal unico de forma modulo.accion, en todo app/', function (): void {
    // Todo literal del ayudante, este donde este —casos de uso, consultas, el
    // PDF del registro propio, la limpieza de ficheros—: dos actos con el mismo
    // nombre serian una sola serie con dos poblaciones.
    $app = \dirname(ModuleTree::root());
    $vistos = [];
    $malFormados = [];
    $repetidos = [];

    foreach (ModuleTree::phpFilesUnder($app) as $file) {
        $relative = ModuleTree::relative($file, $app);
        $module = preg_match('#^Modules/([A-Za-z]+)/#', $relative, $m) === 1 ? strtolower($m[1]) : null;

        foreach (useCaseInstrumentationLiterals(useCaseInstrumentationCode($file)) as $useCase) {
            if (preg_match(USE_CASE_INSTRUMENTATION_PATTERN, $useCase) !== 1
                || ($module !== null && ! str_starts_with($useCase, $module.'.'))) {
                $malFormados[] = $relative.': '.$useCase;
            }

            if (\array_key_exists($useCase, $vistos)) {
                $repetidos[] = $useCase.' ('.$vistos[$useCase].' y '.$relative.')';
            }

            $vistos[$useCase] = $relative;
        }
    }

    expect(\count($vistos))->toBeGreaterThan(130)
        ->and($malFormados)->toBe([])
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
