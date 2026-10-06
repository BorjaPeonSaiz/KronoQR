<?php

declare(strict_types=1);

use App\Modules\Product\Application\Port\DoctorTranslator;
use App\Modules\Product\Application\Port\LogoInspector;
use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Application\UseCase\RunDoctorHandler;
use App\Modules\Product\Domain\ValueObject\DoctorCheck;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Product\Infrastructure\Diagnostics\Probe\GeneratedFilesProbe;
use App\Modules\Product\Infrastructure\Diagnostics\Probe\PermissionsProbe;
use App\Modules\Product\Infrastructure\Diagnostics\RuntimeService;
use App\Modules\Shared\Application\GeneratedFiles\GeneratedFileHousekeeping;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\GeneratedFileStore;
use App\Modules\Shared\Infrastructure\GeneratedFiles\GeneratedFileAreas;
use Tests\Support\Shared\GeneratedFilesSandbox;

/*
 * `product:doctor` frente al reparto de montajes de `BACKUP_PATH` de la 2.2.0
 * (bloque 20, A3-R2; RF-PD-13, RL-12, RNF-D-02, RS-08).
 *
 * Hasta la 2.1.0 la sonda exigia la raiz de las copias ESCRIBIBLE, porque `app`,
 * `horizon` y `scheduler` la montaban entera en escritura: quien ejecutara codigo
 * en cualquiera de ellos podia borrar copias o plantar una. Desde la 2.2.0 la
 * raiz va en solo lectura y cada servicio escribe solo lo suyo:
 *
 *   app        raiz ro · metrics rw · reports/retention rw · daily/base ro
 *   horizon    raiz ro · metrics rw · reports/retention ro · daily/base ro
 *   scheduler  raiz ro · metrics rw · reports/retention rw · daily/base rw
 *
 * La sonda vieja habria dado un FALLO falso en cada instalacion nueva. Aqui se
 * prueba cada combinacion sobre un arbol temporal con permisos reales (el
 * contenedor de pruebas corre como uid 1000, no como root: un 0500 es de verdad
 * de solo lectura).
 */

/**
 * Los directorios a los que una prueba quito permisos, para devolverselos antes
 * de limpiar: sin eso el arbol temporal no se puede borrar.
 *
 * @return list<string>
 */
function permisosDeCopiasBloqueados(?string $nuevo = null, bool $vaciar = false): array
{
    /** @var list<string> $rutas */
    static $rutas = [];

    if ($nuevo !== null) {
        $rutas[] = $nuevo;
    }

    $actuales = $rutas;

    if ($vaciar) {
        $rutas = [];
    }

    return $actuales;
}

afterEach(function (): void {
    foreach (array_reverse(permisosDeCopiasBloqueados(vaciar: true)) as $ruta) {
        if (is_dir($ruta)) {
            chmod($ruta, 0o700);
        }
    }

    GeneratedFilesSandbox::cleanUp();
});

function permisosDeCopiasBloquear(string $ruta, int $modo): void
{
    if (! is_dir($ruta)) {
        return;
    }

    permisosDeCopiasBloqueados($ruta);
    chmod($ruta, $modo);
}

/**
 * Un `BACKUP_PATH` temporal con el arbol que crean `install.sh`/`update.sh`.
 *
 * @param  list<string>  $sinCrear  Subdirectorios que NO se crean.
 */
function arbolDeCopias(array $sinCrear = []): string
{
    $raiz = GeneratedFilesSandbox::directory('backup-path');

    foreach (['metrics', 'daily', 'base', 'reports', 'reports/retention'] as $sub) {
        if (! in_array($sub, $sinCrear, true)) {
            mkdir($raiz.'/'.$sub, 0o750, true);
        }
    }

    return $raiz;
}

/**
 * El montaje de la 2.2.0 para un servicio: raiz en solo lectura y solo lo suyo
 * en escritura. Se quitan los permisos de los hijos antes que los del padre.
 */
function montarComo(string $raiz, RuntimeService $servicio): void
{
    if (! $servicio->writesBackups()) {
        permisosDeCopiasBloquear($raiz.'/daily', 0o500);
        permisosDeCopiasBloquear($raiz.'/base', 0o500);
    }

    if (! $servicio->writesRetentionReports()) {
        permisosDeCopiasBloquear($raiz.'/reports/retention', 0o500);
    }

    permisosDeCopiasBloquear($raiz.'/reports', 0o500);
    permisosDeCopiasBloquear($raiz, 0o500);
}

function sondaDePermisos(
    string $raiz,
    RuntimeService $servicio = RuntimeService::App,
    string $entorno = 'production',
    ?string $metricas = null,
): PermissionsProbe {
    return new PermissionsProbe(
        writablePaths: [GeneratedFilesSandbox::directory('storage')],
        backupPath: $raiz,
        brandingLogoRoot: null,
        settings: app(GetSettingsHandler::class),
        logos: app(LogoInspector::class),
        metricsPath: $metricas ?? $raiz.'/metrics',
        environment: $entorno,
        service: $servicio,
    );
}

function sondaDeInformesDeRetencion(string $raiz, RuntimeService $servicio, string $entorno = 'production'): GeneratedFilesProbe
{
    $storageApp = GeneratedFilesSandbox::directory('storage-app');

    return new GeneratedFilesProbe(
        storageAppPath: $storageApp,
        applicationPath: \dirname($storageApp),
        environment: $entorno,
        retentionReportPath: $raiz.'/reports/retention',
        backupPath: $raiz,
        classRoots: ['PRODUCT_DATA_EXPORT_PATH' => $storageApp.'/exports'],
        consoleExports: GeneratedFileAreas::legalExportConsole($storageApp.'/legal-exports'),
        consoleWarningDays: 30,
        files: app(GeneratedFileHousekeeping::class),
        clock: app(Clock::class),
        store: app(GeneratedFileStore::class),
        service: $servicio,
    );
}

/**
 * @param  PermissionsProbe|GeneratedFilesProbe  $sonda
 */
function hallazgoDeCopias(object $sonda, string $id): DoctorFinding
{
    foreach ($sonda->run() as $hallazgo) {
        if ($hallazgo->id === $id) {
            return $hallazgo;
        }
    }

    throw new RuntimeException('La sonda no devuelve «'.$id.'».');
}

/** @return array{0: DoctorStatus, 1: ?string} */
function estadoDe(DoctorFinding $hallazgo): array
{
    return [$hallazgo->status, $hallazgo->variant];
}

// ---------------------------------------------------------------------------
// El montaje correcto de cada servicio no da ni un aviso
// ---------------------------------------------------------------------------

it('con el montaje de la 2.2.0 todo sale en verde, sea cual sea el servicio', function (RuntimeService $servicio): void {
    $raiz = arbolDeCopias();
    montarComo($raiz, $servicio);

    $sonda = sondaDePermisos($raiz, $servicio);

    expect(estadoDe(hallazgoDeCopias($sonda, 'permissions.backup_path')))->toBe([DoctorStatus::Ok, null])
        ->and(estadoDe(hallazgoDeCopias($sonda, 'permissions.backup_metrics')))->toBe([DoctorStatus::Ok, null])
        ->and(estadoDe(hallazgoDeCopias($sonda, 'permissions.backup_copies')))->toBe([DoctorStatus::Ok, null]);

    $informes = hallazgoDeCopias(sondaDeInformesDeRetencion($raiz, $servicio), 'files.retention_reports');

    expect($informes->status)->toBe(DoctorStatus::Ok)
        ->and($informes->variant)->toBe($servicio === RuntimeService::Horizon ? 'read_only' : null);
})->with([
    'app' => RuntimeService::App,
    'horizon' => RuntimeService::Horizon,
    'scheduler' => RuntimeService::Scheduler,
])->group('RF-PD-13', 'RL-12', 'RS-08');

// ---------------------------------------------------------------------------
// La raiz: legible y NO escribible
// ---------------------------------------------------------------------------

it('ya no falla porque la raiz de las copias sea de solo lectura: es lo correcto', function (): void {
    $raiz = arbolDeCopias();
    permisosDeCopiasBloquear($raiz, 0o500);

    $hallazgo = hallazgoDeCopias(sondaDePermisos($raiz), 'permissions.backup_path');

    expect($hallazgo->status)->toBe(DoctorStatus::Ok)
        ->and($hallazgo->details['writable'])->toBeFalse()
        ->and($hallazgo->params)->toBe(['path' => $raiz]);
})->group('RF-PD-13', 'RL-12');

it('avisa en produccion si la raiz se puede escribir: es el docker-compose.yml de la 2.1.0', function (RuntimeService $servicio): void {
    $raiz = arbolDeCopias();

    $hallazgo = hallazgoDeCopias(sondaDePermisos($raiz, $servicio), 'permissions.backup_path');

    expect(estadoDe($hallazgo))->toBe([DoctorStatus::Warning, 'writable'])
        ->and($hallazgo->params)->toBe(['path' => $raiz, 'service' => $servicio->value]);
})->with([
    'app' => RuntimeService::App,
    'horizon' => RuntimeService::Horizon,
    'scheduler' => RuntimeService::Scheduler,
])->group('RF-PD-13', 'RL-12', 'RS-08');

it('fuera de produccion no avisa de la raiz escribible, pero lo dice', function (): void {
    // El entorno de desarrollo monta un volumen con nombre en escritura: un
    // aviso permanente ahi entrena a ignorar avisos.
    $hallazgo = hallazgoDeCopias(sondaDePermisos(arbolDeCopias(), entorno: 'local'), 'permissions.backup_path');

    expect(estadoDe($hallazgo))->toBe([DoctorStatus::Ok, 'not_checked'])
        ->and($hallazgo->details['writable'])->toBeTrue();
})->group('RF-PD-13');

it('falla si la raiz no existe o no se puede leer: no hay copias', function (): void {
    $noExiste = hallazgoDeCopias(sondaDePermisos('/no/existe/'.bin2hex(random_bytes(4))), 'permissions.backup_path');

    $raiz = arbolDeCopias();
    permisosDeCopiasBloquear($raiz, 0o000);
    $ilegible = hallazgoDeCopias(sondaDePermisos($raiz), 'permissions.backup_path');

    expect(estadoDe($noExiste))->toBe([DoctorStatus::Failure, 'missing'])
        ->and(estadoDe($ilegible))->toBe([DoctorStatus::Failure, 'unreadable']);
})->group('RF-PD-13', 'RL-12', 'RNF-D-02');

// ---------------------------------------------------------------------------
// metrics/: escribible en los tres servicios
// ---------------------------------------------------------------------------

it('falla si metrics no existe o no se puede escribir: las alertas de copias se quedan ciegas', function (RuntimeService $servicio): void {
    $sinMetricas = arbolDeCopias(['metrics']);
    montarComo($sinMetricas, $servicio);

    $soloLectura = arbolDeCopias();
    permisosDeCopiasBloquear($soloLectura.'/metrics', 0o500);
    montarComo($soloLectura, $servicio);

    expect(estadoDe(hallazgoDeCopias(sondaDePermisos($sinMetricas, $servicio), 'permissions.backup_metrics')))
        ->toBe([DoctorStatus::Failure, 'missing'])
        ->and(estadoDe(hallazgoDeCopias(sondaDePermisos($soloLectura, $servicio), 'permissions.backup_metrics')))
        ->toBe([DoctorStatus::Failure, null]);
})->with([
    'app' => RuntimeService::App,
    'horizon' => RuntimeService::Horizon,
    'scheduler' => RuntimeService::Scheduler,
])->group('RF-PD-13', 'RNF-D-02');

it('comprueba el directorio de metricas configurado, no uno deducido', function (): void {
    $raiz = arbolDeCopias(['metrics']);
    $metricas = GeneratedFilesSandbox::directory('metrics-propio');

    $hallazgo = hallazgoDeCopias(sondaDePermisos($raiz, metricas: $metricas), 'permissions.backup_metrics');

    expect($hallazgo->status)->toBe(DoctorStatus::Ok)
        ->and($hallazgo->params)->toBe(['path' => $metricas]);
})->group('RF-PD-13');

// ---------------------------------------------------------------------------
// daily/ y base/: solo el planificador
// ---------------------------------------------------------------------------

it('falla si faltan los directorios de las copias, y dice cuales', function (): void {
    $raiz = arbolDeCopias(['base']);
    montarComo($raiz, RuntimeService::App);

    $hallazgo = hallazgoDeCopias(sondaDePermisos($raiz), 'permissions.backup_copies');

    expect(estadoDe($hallazgo))->toBe([DoctorStatus::Failure, 'missing'])
        ->and($hallazgo->params)->toBe(['paths' => $raiz.'/base']);
})->group('RF-PD-13', 'RNF-D-02');

it('avisa en produccion si app u horizon pueden escribir en las copias', function (RuntimeService $servicio): void {
    $raiz = arbolDeCopias();
    permisosDeCopiasBloquear($raiz.'/base', 0o500);

    $hallazgo = hallazgoDeCopias(sondaDePermisos($raiz, $servicio), 'permissions.backup_copies');
    $enDesarrollo = hallazgoDeCopias(sondaDePermisos($raiz, $servicio, 'local'), 'permissions.backup_copies');

    expect(estadoDe($hallazgo))->toBe([DoctorStatus::Warning, 'writable'])
        ->and($hallazgo->params)->toBe(['paths' => $raiz.'/daily', 'service' => $servicio->value])
        ->and($enDesarrollo->status)->toBe(DoctorStatus::Ok);
})->with([
    'app' => RuntimeService::App,
    'horizon' => RuntimeService::Horizon,
])->group('RF-PD-13', 'RL-12', 'RS-08');

it('falla si el planificador no puede escribir en las copias: no se estan haciendo', function (): void {
    $raiz = arbolDeCopias();
    permisosDeCopiasBloquear($raiz.'/base', 0o500);

    $hallazgo = hallazgoDeCopias(sondaDePermisos($raiz, RuntimeService::Scheduler), 'permissions.backup_copies');

    expect(estadoDe($hallazgo))->toBe([DoctorStatus::Failure, null])
        ->and($hallazgo->params)->toBe(['paths' => $raiz.'/base']);
})->group('RF-PD-13', 'RNF-D-02');

// ---------------------------------------------------------------------------
// reports/retention: app y scheduler si, horizon no
// ---------------------------------------------------------------------------

it('avisa en produccion si horizon puede escribir los informes de retencion', function (): void {
    $raiz = arbolDeCopias();

    $produccion = hallazgoDeCopias(sondaDeInformesDeRetencion($raiz, RuntimeService::Horizon), 'files.retention_reports');
    $desarrollo = hallazgoDeCopias(sondaDeInformesDeRetencion($raiz, RuntimeService::Horizon, 'local'), 'files.retention_reports');

    expect(estadoDe($produccion))->toBe([DoctorStatus::Warning, 'horizon_writable'])
        ->and($produccion->params)->toBe(['path' => $raiz.'/reports/retention'])
        ->and(estadoDe($desarrollo))->toBe([DoctorStatus::Ok, 'read_only'])
        ->and($desarrollo->details['writable'])->toBeTrue();
})->group('RF-PD-13', 'RS-08', 'RL-11');

it('avisa si app o scheduler NO pueden escribir los informes de retencion', function (RuntimeService $servicio): void {
    $raiz = arbolDeCopias();
    permisosDeCopiasBloquear($raiz.'/reports/retention', 0o500);

    expect(estadoDe(hallazgoDeCopias(sondaDeInformesDeRetencion($raiz, $servicio), 'files.retention_reports')))
        ->toBe([DoctorStatus::Warning, null]);
})->with([
    'app' => RuntimeService::App,
    'scheduler' => RuntimeService::Scheduler,
])->group('RF-PD-13', 'RL-11');

it('desde horizon, un directorio de informes que no existe sigue siendo un aviso', function (): void {
    $raiz = arbolDeCopias(['reports/retention']);

    expect(estadoDe(hallazgoDeCopias(sondaDeInformesDeRetencion($raiz, RuntimeService::Horizon), 'files.retention_reports')))
        ->toBe([DoctorStatus::Warning, 'missing']);
})->group('RF-PD-13');

// ---------------------------------------------------------------------------
// El servicio
// ---------------------------------------------------------------------------

it('lee el servicio de KRONOQR_SERVICE y, si no lo entiende, asume app', function (mixed $valor, RuntimeService $esperado): void {
    expect(RuntimeService::fromConfig($valor))->toBe($esperado);
})->with([
    'app' => ['app', RuntimeService::App],
    'horizon' => ['horizon', RuntimeService::Horizon],
    'scheduler con espacios y mayusculas' => [' Scheduler ', RuntimeService::Scheduler],
    // Un servicio que no ejecuta product:doctor, una errata o nada: app, que es
    // donde lo lanzan install.sh, update.sh y doctor.sh.
    'reverb' => ['reverb', RuntimeService::App],
    'vacio' => ['', RuntimeService::App],
    'sin definir' => [null, RuntimeService::App],
])->group('RF-PD-13');

it('reparte la escritura como el compose de produccion', function (): void {
    expect(RuntimeService::App->writesRetentionReports())->toBeTrue()
        ->and(RuntimeService::Scheduler->writesRetentionReports())->toBeTrue()
        ->and(RuntimeService::Horizon->writesRetentionReports())->toBeFalse()
        ->and(RuntimeService::Scheduler->writesBackups())->toBeTrue()
        ->and(RuntimeService::App->writesBackups())->toBeFalse()
        ->and(RuntimeService::Horizon->writesBackups())->toBeFalse();
})->group('RF-PD-13', 'RS-08');

// ---------------------------------------------------------------------------
// Textos
// ---------------------------------------------------------------------------

it('cada resultado de las sondas de copias tiene texto y que hacer en los dos idiomas', function (): void {
    $variantes = [
        ['permissions.backup_path', 'ok', null], ['permissions.backup_path', 'ok', 'not_checked'],
        ['permissions.backup_path', 'failure', 'missing'], ['permissions.backup_path', 'failure', 'unreadable'],
        ['permissions.backup_path', 'warning', 'writable'],
        ['permissions.backup_metrics', 'ok', null], ['permissions.backup_metrics', 'failure', null],
        ['permissions.backup_metrics', 'failure', 'missing'],
        ['permissions.backup_copies', 'ok', null], ['permissions.backup_copies', 'failure', null],
        ['permissions.backup_copies', 'failure', 'missing'], ['permissions.backup_copies', 'warning', 'writable'],
        ['files.retention_reports', 'ok', 'read_only'], ['files.retention_reports', 'warning', 'horizon_writable'],
    ];

    foreach (['es', 'en'] as $idioma) {
        foreach ($variantes as [$id, $estado, $variante]) {
            $hallazgo = new DoctorFinding($id, DoctorStatus::from($estado), variant: $variante);

            expect(trans($hallazgo->messageKey(), [], $idioma))->not->toBe($hallazgo->messageKey(), $idioma.' '.$hallazgo->messageKey());

            if ($hallazgo->fixKey() !== null) {
                expect(trans($hallazgo->fixKey(), [], $idioma))->not->toBe($hallazgo->fixKey(), $idioma.' '.$hallazgo->fixKey());
            }
        }
    }
})->group('RF-PD-13');

it('ningun resultado posible de las sondas de copias deja un marcador sin sustituir', function (): void {
    $sondas = [];

    foreach (RuntimeService::cases() as $servicio) {
        $correcto = arbolDeCopias();
        montarComo($correcto, $servicio);
        $sondas[] = sondaDePermisos($correcto, $servicio);
        $sondas[] = sondaDeInformesDeRetencion($correcto, $servicio);

        $abierto = arbolDeCopias();
        permisosDeCopiasBloquear($abierto.'/base', 0o500);
        $sondas[] = sondaDePermisos($abierto, $servicio);
        $sondas[] = sondaDePermisos($abierto, $servicio, 'local');
        $sondas[] = sondaDeInformesDeRetencion($abierto, $servicio);
    }

    $vacio = arbolDeCopias(['metrics', 'daily', 'base', 'reports', 'reports/retention']);
    $sondas[] = sondaDePermisos($vacio);
    $sondas[] = sondaDeInformesDeRetencion($vacio, RuntimeService::Horizon);
    $sondas[] = sondaDePermisos('/no/existe/'.bin2hex(random_bytes(4)));

    $traductor = app(DoctorTranslator::class);

    foreach ($sondas as $sonda) {
        foreach ($sonda->run() as $hallazgo) {
            foreach (['es', 'en'] as $idioma) {
                $texto = (string) $traductor->translate($hallazgo->messageKey(), $hallazgo->params, $idioma)
                    .' '.($hallazgo->fixKey() === null ? '' : (string) $traductor->translate($hallazgo->fixKey(), $hallazgo->params, $idioma));

                preg_match_all('/(?<![A-Za-z0-9\/]):[a-z][a-z_]*/', $texto, $marcadores);

                expect($marcadores[0])->toBe([], $idioma.' '.$hallazgo->messageKey().': '.$texto);
            }
        }
    }
})->group('RF-PD-13');

it('product:doctor incluye las tres comprobaciones de las copias', function (): void {
    $ids = array_map(
        static fn (DoctorCheck $check): string => $check->id,
        app(RunDoctorHandler::class)->handle('es')->checks,
    );

    expect($ids)->toContain('permissions.backup_path', 'permissions.backup_metrics', 'permissions.backup_copies');
})->group('RF-PD-13');
