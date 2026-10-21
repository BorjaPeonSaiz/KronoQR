<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\PendingDiagnosticsAudit;
use App\Support\Database\DatabaseCircuitBreaker;
use App\Support\Network\BoundedReachability;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Tests\Support\Health\CountingPostgresConnector;

/*
 * **El paquete de diagnostico se genera con PostgreSQL caido** (V4-PL-1;
 * RF-PD-09, RL-19, RS-08, RL-04, RNF-D-02; ADR-020, enmienda del 14-10-2026).
 *
 * El paquete se necesita sobre todo cuando algo va mal, y lo que mas a menudo
 * va mal es la base. Hasta la 2.2.0, sin base no se escribia ningun fichero: el
 * asiento fallaba y la excepcion subia por el comando.
 *
 * ## Montaje
 *
 * Como `ScanWithDatabaseCircuitOpenTest`: PostgreSQL de verdad en un puerto
 * cerrado (`127.0.0.1:1`) para `pgsql` y `error_events`, el cortacircuitos
 * ENCENDIDO a mano sobre un fichero propio y el conector que cuenta los intentos
 * reales. Redis, arriba. **Sin `RefreshDatabase`**: la base se tira de verdad.
 *
 * ## Lo que se planta para que NO salga
 *
 * Un nombre, un correo, un documento, una hora de fichaje y un UUID de empleado
 * en una linea del informe de actualizacion que no pasa la lista de permitidos,
 * en un `.detalle.log` y en una variable de entorno fuera de la lista. Y las
 * credenciales de la conexion —usuario, contraseña, host y base—, que son lo
 * que lleva el mensaje de un `PDOException`. Ninguno puede aparecer en el
 * paquete, en el pendiente, en la salida del comando ni en el log.
 */

const DIAGNOSTICS_DB_DOWN_CONNECTIONS = ['pgsql', 'error_events'];

const DIAGNOSTICS_DB_DOWN_PERSONAL = [
    'Eustaquio Villarejo',
    'eustaquio.villarejo@hotel-marcador.example',
    '48291736K',
    '2026-03-14 07:02:31',
    '0192f3a4-7b1c-7d2e-9f30-a1b2c3d4e5f6',
];

const DIAGNOSTICS_DB_DOWN_CREDENTIALS = [
    'username' => 'kq_usuario_marcador',
    'password' => 'contrasena-marcador-de-la-prueba',
    'host' => '127.0.0.1',
    'database' => 'kq_base_marcador',
];

const DIAGNOSTICS_DB_DOWN_ENV = 'KQ_DIAGNOSTICO_MARCADOR_LIBRE';

const DIAGNOSTICS_DB_DOWN_REPORT = 'update-20261001T030000Z.log';

beforeEach(function (): void {
    CountingPostgresConnector::reset();

    $real = [];

    foreach (DIAGNOSTICS_DB_DOWN_CONNECTIONS as $connection) {
        $real[$connection] = config()->array('database.connections.'.$connection);
    }

    diagnosticsDbDownRealConnections($real);

    $root = sys_get_temp_dir().'/kronoqr-diagnostics-db-down-'.bin2hex(random_bytes(6));
    diagnosticsDbDownRoot($root);

    config()->set('product.diagnostics_storage_path', $root.'/diagnostics');
    config()->set('product.diagnostics_pending_audit_path', $root.'/pending');
    config()->set('backup.path', $root.'/backups');

    diagnosticsDbDownPlantMarkers($root.'/backups/reports');

    $_ENV[DIAGNOSTICS_DB_DOWN_ENV] = implode(' ', DIAGNOSTICS_DB_DOWN_PERSONAL);
});

afterEach(function (): void {
    foreach (diagnosticsDbDownRealConnections() as $connection => $settings) {
        config()->set('database.connections.'.$connection, $settings);
        DB::purge($connection);
    }

    unset($_ENV[DIAGNOSTICS_DB_DOWN_ENV]);

    diagnosticsDbDownRemove(diagnosticsDbDownRoot());

    if (is_file(diagnosticsDbDownCircuitFile())) {
        unlink(diagnosticsDbDownCircuitFile());
    }
});

/**
 * @param  array<string, array<mixed>>|null  $save
 * @return array<string, array<mixed>>
 */
function diagnosticsDbDownRealConnections(?array $save = null): array
{
    static $real = [];

    if ($save !== null) {
        $real = $save;
    }

    return $real;
}

function diagnosticsDbDownRoot(?string $save = null): string
{
    static $root = '';

    if ($save !== null) {
        $root = $save;
    }

    return $root;
}

function diagnosticsDbDownCircuitFile(): string
{
    return sys_get_temp_dir().'/kronoqr-database-circuit-diagnostics-'.getmypid();
}

function diagnosticsDbDownRemove(string $path): void
{
    if ($path === '' || ! file_exists($path)) {
        return;
    }

    if (is_dir($path) && ! is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                diagnosticsDbDownRemove($path.'/'.$entry);
            }
        }

        rmdir($path);

        return;
    }

    unlink($path);
}

/** Los marcadores, en el informe de actualizacion y en su detalle. */
function diagnosticsDbDownPlantMarkers(string $reports): void
{
    mkdir($reports, 0o755, true);

    $markers = implode(' ', DIAGNOSTICS_DB_DOWN_PERSONAL);

    file_put_contents($reports.'/'.DIAGNOSTICS_DB_DOWN_REPORT, implode("\n", [
        'INFORME DE ACTUALIZACION DE KRONOQR',
        'Inicio (UTC): 2026-10-01T03:00:00Z',
        'Version de origen: 2.2.0',
        // Fuera de la lista de permitidos: no puede salir.
        'Empleado afectado: '.$markers,
        'Estado final: correcto',
    ])."\n");

    file_put_contents($reports.'/update-20261001T030000Z.detalle.20261001.log', 'Detalle: '.$markers."\n");
}

/** PostgreSQL caido, con las credenciales marcadas. */
function diagnosticsDbDownBreakDatabase(): void
{
    foreach (DIAGNOSTICS_DB_DOWN_CONNECTIONS as $connection) {
        foreach (DIAGNOSTICS_DB_DOWN_CREDENTIALS as $key => $value) {
            config()->set('database.connections.'.$connection.'.'.$key, $value);
        }

        config()->set('database.connections.'.$connection.'.port', 1);
        DB::purge($connection);
    }

    $breaker = new DatabaseCircuitBreaker(diagnosticsDbDownCircuitFile(), 10.0, new MockClock, resolve(LoggerInterface::class));

    app()->instance(DatabaseCircuitBreaker::class, $breaker);
    app()->bind('db.connector.pgsql', static fn (): CountingPostgresConnector => new CountingPostgresConnector($breaker, new BoundedReachability));
}

/** @return ArrayObject<int, string> */
function diagnosticsDbDownLog(): ArrayObject
{
    /** @var ArrayObject<int, string> $lines */
    $lines = new ArrayObject;

    Event::listen(MessageLogged::class, static function (MessageLogged $logged) use ($lines): void {
        $lines->append($logged->message.' '.json_encode($logged->context, JSON_PARTIAL_OUTPUT_ON_ERROR));
    });

    return $lines;
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{code: int, output: string, seconds: float}
 */
function diagnosticsDbDownRun(array $parameters = []): array
{
    $start = microtime(true);
    $code = Artisan::call('product:diagnostics', [...$parameters, '--no-interaction' => true]);

    return ['code' => $code, 'output' => Artisan::output(), 'seconds' => microtime(true) - $start];
}

/** @return list<string> */
function diagnosticsDbDownFiles(string $directory): array
{
    /** @var list<string> */
    return glob(diagnosticsDbDownRoot().'/'.$directory.'/*') ?: [];
}

/**
 * @return array<string, mixed>
 */
function diagnosticsDbDownDecode(string $path): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Todos los valores de `reason`, `error` y `exception` del documento.
 *
 * @param  array<array-key, mixed>  $document
 * @return list<mixed>
 */
function diagnosticsDbDownReasons(array $document): array
{
    $found = [];

    foreach ($document as $key => $value) {
        if (in_array($key, ['reason', 'error', 'exception'], true)) {
            $found[] = $value;
        }

        if (is_array($value)) {
            $found = [...$found, ...diagnosticsDbDownReasons($value)];
        }
    }

    return $found;
}

it('con PostgreSQL caido genera el paquete anonimizado, completo, con su asiento pendiente y sin datos personales ni credenciales (V4-PL-1)', function (): void {
    diagnosticsDbDownBreakDatabase();
    $log = diagnosticsDbDownLog();

    $result = diagnosticsDbDownRun();

    // 1. Sale bien, un solo fichero, y verifica.
    expect($result['code'])->toBe(0, $result['output']);

    $bundles = diagnosticsDbDownFiles('diagnostics');
    expect($bundles)->toHaveCount(1);
    expect(Artisan::call('product:diagnostics', ['--verify' => $bundles[0]]))->toBe(0);
    $verifyOutput = Artisan::output();
    expect($verifyOutput)->toContain('PENDIENTE');

    $bundle = diagnosticsDbDownDecode($bundles[0]);

    // 2. Las once secciones, anonimizado y por consola.
    expect(array_keys($bundle))->toBe([
        'manifest', 'installation', 'configuration', 'services', 'doctor', 'license',
        'kiosks', 'error_events', 'metrics', 'updates', 'audit',
    ]);

    /** @var array<string, mixed> $manifest */
    $manifest = $bundle['manifest'];
    expect($manifest['anonymized'])->toBeTrue()
        ->and($manifest['generated_by'])->toBe('console');

    // 3. Lo que depende de la base dice que no esta.
    /** @var array<string, array<string, mixed>> $services */
    $services = $bundle['services'];
    expect($bundle['kiosks'])->toMatchArray(['status' => 'unavailable'])
        ->and($bundle['error_events'])->toMatchArray(['status' => 'unavailable'])
        ->and($bundle['audit'])->toMatchArray(['status' => 'unavailable'])
        ->and($services['database']['reachable'])->toBeFalse()
        ->and($services['audit_log']['reachable'])->toBeFalse();

    // 4. Lo que no depende de la base sale entero.
    /** @var array<string, mixed> $installation */
    $installation = $bundle['installation'];
    /** @var array<string, mixed> $configuration */
    $configuration = $bundle['configuration'];
    /** @var array{checks: list<array{id: string, status: string, summary: string}>} $doctor */
    $doctor = $bundle['doctor'];
    /** @var array{latest: array{file: string}} $updates */
    $updates = $bundle['updates'];

    $databaseConnection = array_values(array_filter(
        $doctor['checks'],
        static fn (array $check): bool => $check['id'] === 'database.connection',
    ));
    // Las familias que revientan sin base lo atribuyen a la base (variante
    // `database`), no a un fallo del producto.
    $probes = array_values(array_filter(
        $doctor['checks'],
        static fn (array $check): bool => str_ends_with($check['id'], '.probe'),
    ));

    expect($installation['product_version'])->toBe(config('app.version'))
        ->and($installation['php_version'])->toBe(PHP_VERSION)
        ->and($configuration['env'])->not->toBeEmpty()
        ->and($doctor['checks'])->not->toBeEmpty()
        ->and($databaseConnection)->toHaveCount(1)
        ->and($databaseConnection[0]['status'])->toBe('failure')
        ->and($updates['latest']['file'])->toBe(DIAGNOSTICS_DB_DOWN_REPORT)
        ->and($services['redis']['reachable'])->toBeTrue();

    expect($probes)->not->toBeEmpty();

    foreach ($probes as $probe) {
        expect($probe['summary'])->toContain('la base de datos no responde');
    }

    // 5. Ningun motivo lleva texto de excepcion.
    foreach (diagnosticsDbDownReasons($bundle) as $reason) {
        expect($reason)->toBeString()->toMatch('/^[A-Za-z0-9_\\\\]+$/');
    }

    // 6. La constancia, dentro de la huella.
    /** @var array<string, mixed> $audit */
    $audit = $bundle['audit'];
    expect($audit['generation_entry'])->toBe(['status' => 'pending', 'reason' => 'database_unavailable']);

    // 7. Un pendiente, cerrado, con la huella del paquete.
    $pending = diagnosticsDbDownFiles('pending');
    expect($pending)->toHaveCount(1)
        ->and(substr(sprintf('%o', (int) fileperms($pending[0])), -3))->toBe('600');

    $entry = diagnosticsDbDownDecode($pending[0]);
    expect(array_keys($entry))->toBe(PendingDiagnosticsAudit::KEYS)
        ->and($entry['sha256'])->toBe($manifest['sha256'])
        ->and($entry['anonymized'])->toBeTrue();

    // 8. Ningun marcador ni credencial en ninguna parte.
    $haystacks = [
        'paquete' => (string) file_get_contents($bundles[0]),
        'pendiente' => (string) file_get_contents($pending[0]),
        'salida' => $result['output'].$verifyOutput,
        'log' => implode("\n", $log->getArrayCopy()),
    ];

    foreach ($haystacks as $where => $haystack) {
        foreach ([...DIAGNOSTICS_DB_DOWN_PERSONAL, ...array_values(DIAGNOSTICS_DB_DOWN_CREDENTIALS)] as $marker) {
            expect(str_contains($haystack, $marker))->toBeFalse('«'.$marker.'» aparece en '.$where);
        }
    }

    // 9. La salida dice que el asiento queda pendiente y se escribe solo.
    expect($result['output'])->toContain('queda')
        ->and($result['output'])->toContain('PENDIENTE')
        ->and($result['output'])->toContain('se escribira solo');

    // 11. En tiempo acotado: el cortacircuitos abre al primer intento.
    expect($result['seconds'])->toBeLessThan(15.0)
        ->and(CountingPostgresConnector::$attempts)->toBe(1);
})->group('RF-PD-09', 'RL-19', 'RS-08', 'RL-04', 'RNF-D-02');

it('con PostgreSQL caido, --with-personal-data no genera nada porque no se puede auditar (RL-19, V4-PL-1)', function (): void {
    diagnosticsDbDownBreakDatabase();
    $log = diagnosticsDbDownLog();

    // Con la cuenta que firma: sin base ni siquiera se puede leer, y el paquete
    // con datos personales no se genera (2.3.0 bloque 3).
    $result = diagnosticsDbDownRun([
        '--with-personal-data' => true,
        '--responsible' => 'admin@hotel.test',
        '--responsible-confirm' => 'admin@hotel.test',
    ]);

    expect($result['code'])->toBe(1)
        ->and(diagnosticsDbDownFiles('diagnostics'))->toBe([])
        ->and(diagnosticsDbDownFiles('pending'))->toBe([])
        ->and($result['output'])->toContain('no se genera porque no se puede auditar');

    foreach ([...DIAGNOSTICS_DB_DOWN_PERSONAL, ...array_values(DIAGNOSTICS_DB_DOWN_CREDENTIALS)] as $marker) {
        expect(str_contains($result['output'].implode("\n", $log->getArrayCopy()), $marker))->toBeFalse($marker);
    }
})->group('RF-PD-09', 'RL-19', 'RL-04');

it('con PostgreSQL caido y sin poder guardar el pendiente, no sale ningun paquete (V4-PL-1)', function (): void {
    // El padre del directorio de pendientes es un fichero: no se puede crear ni
    // siendo root, que es como corre la suite en el contenedor.
    file_put_contents(diagnosticsDbDownRoot().'/bloqueo', 'no es un directorio');
    config()->set('product.diagnostics_pending_audit_path', diagnosticsDbDownRoot().'/bloqueo/pending');

    diagnosticsDbDownBreakDatabase();

    $result = diagnosticsDbDownRun();

    expect($result['code'])->toBe(1)
        ->and(diagnosticsDbDownFiles('diagnostics'))->toBe([])
        ->and(glob(diagnosticsDbDownRoot().'/bloqueo/pending/*') ?: [])->toBe([])
        ->and($result['output'])->toContain('no se ha podido')
        ->and($result['output'])->toContain('guardar la constancia');
})->group('RF-PD-09', 'RL-04', 'RS-08');

it('con PostgreSQL caido la licencia sale como unknown, sin degradar nada ni aconsejar reactivarla (V21-4-PL-1)', function (): void {
    diagnosticsDbDownBreakDatabase();

    $result = diagnosticsDbDownRun();
    expect($result['code'])->toBe(0, $result['output']);

    $bundle = diagnosticsDbDownDecode(diagnosticsDbDownFiles('diagnostics')[0]);

    /** @var array<string, mixed> $license */
    $license = $bundle['license'];
    /** @var array{checks: list<array<string, mixed>>} $doctor */
    $doctor = $bundle['doctor'];

    $state = array_values(array_filter(
        $doctor['checks'],
        static fn (array $check): bool => $check['id'] === 'license.state',
    ));

    expect($license['state'])->toBe('unknown')
        // No se sabe que esta degradado: no se dice que lo este.
        ->and($license['degraded_features'])->toBe([])
        ->and($state)->toHaveCount(1)
        // Un `warning`, como cualquier estado de licencia (regla dura 15).
        ->and($state[0]['status'])->toBe('warning')
        ->and(json_encode($state[0]))->not->toContain('license:activate')
        ->and(json_encode($state[0]))->not->toContain('No hay ninguna licencia')
        ->and(json_encode($bundle))->not->toContain('"absent"');
})->group('RF-PD-09', 'RF-PD-04', 'RNF-D-03');
