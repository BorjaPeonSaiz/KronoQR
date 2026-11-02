<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Product\Infrastructure\Diagnostics\BundleDoctorFindingFilter;
use App\Modules\Product\Infrastructure\Diagnostics\DiagnosticsLocations;

/*
 * LO QUE UN HALLAZGO DE `doctor` LLEVA DENTRO DEL PAQUETE: UNA LISTA CERRADA DE
 * CLAVES (ADR-020, enmienda del 01-11-2026; RL-19, RF-PD-09).
 *
 * Con rutas inventadas que no existen en el disco: aqui se prueba la
 * clasificacion y las etiquetas, no el sistema de ficheros. La prueba con rutas
 * sembradas de verdad es `DiagnosticsPathsAndSealingTest`.
 */

const BUNDLE_DOCTOR_FILTER_BACKUP = '/srv/hotel-ejemplo-sol/copias';

const BUNDLE_DOCTOR_FILTER_METRICS = '/srv/hotel-ejemplo-sol/copias/metrics';

const BUNDLE_DOCTOR_FILTER_STORAGE = '/opt/hotel-ejemplo-sol/app/storage';

function bundleDoctorFilter(): BundleDoctorFindingFilter
{
    $roots = [
        'backup_path' => BUNDLE_DOCTOR_FILTER_BACKUP,
        'backup_metrics' => BUNDLE_DOCTOR_FILTER_METRICS,
        'storage_root' => BUNDLE_DOCTOR_FILTER_STORAGE,
        'application_root' => '/opt/hotel-ejemplo-sol/app',
    ];

    return new BundleDoctorFindingFilter(
        new DiagnosticsLocations($roots, $roots),
        ['PRODUCT_DATA_EXPORT_PATH', 'storage/app/legal-exports'],
    );
}

/**
 * @param  array<string, string|int|float|bool|null>  $params
 * @param  array<string, mixed>  $details
 */
function bundleDoctorFiltered(array $params, array $details = []): DoctorFinding
{
    return bundleDoctorFilter()->filter(new DoctorFinding('disk.backup', DoctorStatus::Warning, $params, $details, 'missing'));
}

it('una ruta que es la raiz de un papel sale como su etiqueta', function (): void {
    $finding = bundleDoctorFiltered(['path' => BUNDLE_DOCTOR_FILTER_BACKUP], ['path' => BUNDLE_DOCTOR_FILTER_BACKUP.'/']);

    expect($finding->params)->toBe(['path' => '<backup_path>'])
        ->and($finding->details)->toBe(['path' => '<backup_path>']);
})->group('RL-19', 'RF-PD-09');

it('una ruta bajo una raiz sale como la etiqueta y el resto por la lista blanca de palabras', function (): void {
    $finding = bundleDoctorFiltered(
        ['path' => BUNDLE_DOCTOR_FILTER_BACKUP.'/daily'],
        ['path' => BUNDLE_DOCTOR_FILTER_BACKUP.'/reports/hotel-ejemplo-sol'],
    );

    expect($finding->params['path'])->toBe('<backup_path>/daily')
        ->and($finding->details['path'])->toStartWith('<backup_path>/reports/')
        ->and($finding->details['path'])->not->toContain('hotel')
        ->and($finding->details['path'])->not->toContain('ejemplo');
})->group('RL-19', 'RF-PD-09');

it('gana la raiz mas especifica', function (): void {
    expect(bundleDoctorFiltered(['path' => BUNDLE_DOCTOR_FILTER_METRICS.'/kronoqr_wal.prom'])->params['path'])
        ->toStartWith('<backup_metrics>/kronoqr_wal.');
})->group('RL-19', 'RF-PD-09');

it('una ruta que no cuelga de ninguna raiz sale como [path]', function (string $path): void {
    expect(bundleDoctorFiltered(['path' => $path])->params['path'])->toBe('[path]');
})->with([
    'otra ruta absoluta' => ['/home/recepcion/hotel-ejemplo-sol'],
    'un prefijo de una raiz sin ser hija' => [BUNDLE_DOCTOR_FILTER_BACKUP.'-viejas'],
    'una ruta relativa' => ['copias/daily'],
    'vacia' => [''],
])->group('RL-19', 'RF-PD-09');

it('varias rutas unidas por coma, en lista o en mapa, salen etiquetadas una a una', function (): void {
    $finding = bundleDoctorFiltered(
        ['paths' => BUNDLE_DOCTOR_FILTER_BACKUP.'/daily, '.BUNDLE_DOCTOR_FILTER_BACKUP.'/base'],
        [
            'not_writable' => [BUNDLE_DOCTOR_FILTER_STORAGE, '/etc/hotel-ejemplo-sol'],
            'roots' => ['PRODUCT_DATA_EXPORT_PATH' => BUNDLE_DOCTOR_FILTER_STORAGE.'/app/exports'],
        ],
    );

    expect($finding->params['paths'])->toBe('<backup_path>/daily, <backup_path>/base')
        ->and($finding->details['not_writable'])->toBe(['<storage_root>', '[path]'])
        ->and($finding->details['roots'])->toBe(['PRODUCT_DATA_EXPORT_PATH' => '<storage_root>/app/exports']);
})->group('RL-19', 'RF-PD-09');

it('una clave de ubicacion con un booleano o una cifra pasa tal cual', function (): void {
    $finding = bundleDoctorFiltered([], ['writable' => true, 'missing' => 2]);

    expect($finding->details)->toBe(['writable' => true, 'missing' => 2]);
})->group('RL-19', 'RF-PD-09');

it('el nombre de una raiz de clase del producto pasa; un directorio del cliente, no', function (): void {
    $finding = bundleDoctorFiltered(
        ['first' => 'PRODUCT_DATA_EXPORT_PATH', 'second' => 'storage/app/legal-exports', 'names' => 'PRODUCT_DATA_EXPORT_PATH, exportaciones-hotel-ejemplo-sol'],
        ['directories' => ['exportaciones-hotel-ejemplo-sol'], 'outside_volume' => ['PRODUCT_DATA_EXPORT_PATH']],
    );

    expect($finding->params)->toBe([
        'first' => 'PRODUCT_DATA_EXPORT_PATH',
        'second' => 'storage/app/legal-exports',
        'names' => 'PRODUCT_DATA_EXPORT_PATH, [path]',
    ])->and($finding->details)->toBe([
        'directories' => ['[path]'],
        'outside_volume' => ['PRODUCT_DATA_EXPORT_PATH'],
    ]);
})->group('RL-19', 'RF-PD-09');

it('el host de APP_URL sale como [app_host]', function (): void {
    $finding = bundleDoctorFiltered(['host' => 'fichaje.hotel-ejemplo-sol.test', 'port' => 443]);

    expect($finding->params)->toBe(['host' => '[app_host]', 'port' => 443]);
})->group('RL-19', 'RF-PD-09');

it('una clave app_url con la direccion entera sale como [app_host]; su estado pasa', function (): void {
    // Revision de la 33.8: `app_url` estaba en PASS_KEYS, y una sonda que
    // pusiera ahi APP_URL la habria sacado tal cual. `TlsProbe` dice el estado
    // en `app_url_state`.
    $finding = bundleDoctorFiltered(
        ['app_url' => 'https://fichaje.hotel-ejemplo-sol.test/'],
        ['app_url' => 'https://fichaje.hotel-ejemplo-sol.test', 'app_url_state' => 'unparseable'],
    );

    expect($finding->params)->toBe(['app_url' => '[app_host]'])
        ->and($finding->details)->toBe(['app_url' => '[app_host]', 'app_url_state' => 'unparseable'])
        ->and(BundleDoctorFindingFilter::classify('app_url'))->toBe(BundleDoctorFindingFilter::HOST)
        ->and(BundleDoctorFindingFilter::classify('app_url_state'))->toBe(BundleDoctorFindingFilter::PASS)
        ->and(BundleDoctorFindingFilter::PASS_KEYS)->not->toContain('app_url');
})->group('RL-19', 'RF-PD-09');

it('una clave sin clasificar se descarta: falla cerrado', function (): void {
    $finding = bundleDoctorFiltered(
        ['count' => 3, 'domicilio' => '/srv/hotel-ejemplo-sol'],
        ['free_bytes' => 1024, 'nueva_ruta' => '/srv/hotel-ejemplo-sol', 'peer_name' => 'fichaje.hotel-ejemplo-sol.test'],
    );

    expect($finding->params)->toBe(['count' => 3])
        ->and($finding->details)->toBe(['free_bytes' => 1024]);
})->group('RL-19', 'RF-PD-09');

it('las cifras, los estados y los enumerados pasan intactos', function (): void {
    $details = [
        'free_bytes' => 12_345_678,
        'free_percent' => 12.5,
        'service' => 'scheduler',
        'category' => 'restricted',
        'roles' => 'admin, rrhh',
        'exception' => 'PDOException',
        'reachable' => false,
        'last_result' => null,
        'by_role' => ['admin' => 1],
    ];

    expect(bundleDoctorFiltered([], $details)->details)->toBe($details);
})->group('RL-19', 'RF-PD-09');

it('conserva el id, el estado y la variante del hallazgo', function (): void {
    $finding = bundleDoctorFiltered(['path' => BUNDLE_DOCTOR_FILTER_BACKUP]);

    expect($finding->id)->toBe('disk.backup')
        ->and($finding->status)->toBe(DoctorStatus::Warning)
        ->and($finding->variant)->toBe('missing');
})->group('RL-19', 'RF-PD-09');

it('clasifica cada clave en una sola clase', function (): void {
    $all = [
        ...BundleDoctorFindingFilter::PASS_KEYS,
        ...BundleDoctorFindingFilter::LOCATION_KEYS,
        ...BundleDoctorFindingFilter::LABEL_KEYS,
        ...BundleDoctorFindingFilter::HOST_KEYS,
    ];

    expect(array_unique($all))->toHaveCount(\count($all))
        ->and(BundleDoctorFindingFilter::classify('path'))->toBe(BundleDoctorFindingFilter::LOCATION)
        ->and(BundleDoctorFindingFilter::classify('first'))->toBe(BundleDoctorFindingFilter::LABEL)
        ->and(BundleDoctorFindingFilter::classify('host'))->toBe(BundleDoctorFindingFilter::HOST)
        ->and(BundleDoctorFindingFilter::classify('count'))->toBe(BundleDoctorFindingFilter::PASS)
        ->and(BundleDoctorFindingFilter::classify('sin_clasificar'))->toBeNull();
})->group('RL-19', 'RF-PD-09');

it('quita las rutas de un texto libre, tambien dentro de una URL', function (): void {
    $locations = new DiagnosticsLocations(['backup_path' => BUNDLE_DOCTOR_FILTER_BACKUP], ['backup_path' => BUNDLE_DOCTOR_FILTER_BACKUP]);

    $text = $locations->redact(
        'Copia previa: '.BUNDLE_DOCTOR_FILTER_BACKUP.'/daily/pre-update.kqe (ok) · paquete nuevo: /opt/hotel-ejemplo-sol/kronoqr'
        .' · https://fichaje.hotel-ejemplo-sol.test/admin'
    );

    expect($text)->toStartWith('Copia previa: <backup_path>/daily/')
        ->and($text)->toContain('paquete nuevo: [path]')
        ->and($text)->not->toContain('hotel-ejemplo-sol');
})->group('RL-19', 'RF-PD-09');

it('describe cada papel sin su valor: si es el de serie, si existe y si se escribe', function (): void {
    $locations = new DiagnosticsLocations(
        ['backup_path' => BUNDLE_DOCTOR_FILTER_BACKUP, 'storage_root' => sys_get_temp_dir()],
        ['backup_path' => DiagnosticsLocations::SERIES_BACKUP_PATH, 'storage_root' => sys_get_temp_dir().'/'],
    );

    $described = $locations->describe();

    expect(array_keys($described))->toBe(DiagnosticsLocations::ROLES)
        ->and($described['backup_path'])->toBe(['customized' => true, 'exists' => false, 'writable' => false])
        ->and($described['storage_root'])->toBe(['customized' => false, 'exists' => true, 'writable' => true])
        ->and(json_encode($described))->not->toContain('hotel-ejemplo-sol');
})->group('RL-19', 'RF-PD-09');
