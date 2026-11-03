<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Architecture\Support\Repo;
use Tests\Contract\Support\ComponentSchema;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\DiagnosticsRecipient;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * EL ANONIMIZADO NO LLEVA RUTAS DEL SERVIDOR NI EL HOST, Y EL QUE LLEVA DATOS
 * PERSONALES SALE CIFRADO (ADR-020, enmienda del 01-11-2026; RL-19, RF-PD-09,
 * RS-08; doc 07, filas «rutas en el anonimizado» y «paquete con datos
 * personales sin cifrar»).
 *
 * ## Se siembra lo que haria un cliente de verdad
 *
 * Todas las ubicaciones configurables bajo un directorio con el nombre del
 * hotel —copias existentes y sin escritura, logotipo que no existe, informes,
 * log tecnico, paquetes y exportaciones—, `APP_URL` con su nombre publico, un
 * informe de actualizacion que nombra la instalacion y la copia previa, un
 * error cuyo texto lleva la ruta de las copias, y tres personas con nombre,
 * codigo, correo y fichajes.
 *
 * Y se busca **en los bytes que salen**, no en una seccion: una ruta que se
 * colara por un sitio nuevo tambien la encuentra.
 *
 * ## Control positivo
 *
 * Un paquete vacio pasaria las negativas. Por eso las comprobaciones de
 * `doctor` que hablan de esas ubicaciones tienen que estar, con su estado y la
 * etiqueta, y `configuration.locations` tiene que decir que las copias no
 * estan donde vienen de serie.
 */

uses(RefreshDatabase::class);

final class DiagnosticsPathsAndSealingSeed
{
    public static string $root = '';

    public static string $backup = '';

    /** @var array<string, mixed> */
    public static array $previousEnv = [];

    /** @var list<array{name: string, code: string, email: string, uuid: string, clocked_in_at: string}> */
    public static array $people = [];
}

const DIAGNOSTICS_PATHS_HOST = 'fichaje.hotel-ejemplo-sol.test';

/**
 * Las variables que el cliente habria puesto en su `.env`, con sus rutas.
 *
 * @return array<string, string>
 */
function diagnosticsPathsEnvironment(): array
{
    $root = DiagnosticsPathsAndSealingSeed::$root;

    return [
        'BACKUP_PATH' => DiagnosticsPathsAndSealingSeed::$backup,
        'BRANDING_LOGO_ROOT' => $root.'/marca-que-no-existe',
        'COMPLIANCE_RETENTION_REPORT_PATH' => $root.'/informes-retencion',
        'COMPLIANCE_TECHNICAL_LOG_PATH' => $root.'/log-tecnico',
        'PRODUCT_DIAGNOSTICS_PATH' => $root.'/paquetes',
        'PRODUCT_DATA_EXPORT_PATH' => $root.'/exportaciones',
        'APP_URL' => 'https://'.DIAGNOSTICS_PATHS_HOST,
    ];
}

beforeEach(function (): void {
    FrozenTime::at('2026-09-09 08:00:00');
    WorkforceFixtures::site();
    LicenseKeys::install();

    $root = sys_get_temp_dir().'/kq-srv/hotel-ejemplo-sol-'.bin2hex(random_bytes(4));
    $backup = $root.'/copias';
    DiagnosticsPathsAndSealingSeed::$root = $root;
    DiagnosticsPathsAndSealingSeed::$backup = $backup;

    foreach (['/metrics', '/reports', '/daily', '/base'] as $sub) {
        mkdir($backup.$sub, 0o755, true);
    }

    foreach (['/informes-retencion', '/log-tecnico', '/paquetes', '/exportaciones'] as $sub) {
        mkdir($root.$sub, 0o755, true);
    }

    // El informe de la ultima actualizacion, con las dos lineas que nombran el
    // servidor: el directorio de la instalacion y la copia previa.
    file_put_contents($backup.'/reports/update-2.2.1-20260901T020000Z.log', implode("\n", [
        'INFORME DE ACTUALIZACION DE KRONOQR',
        'Inicio (UTC): 2026-09-01T02:00:00Z',
        'Version de origen: 2.2.0 → 2.2.1',
        'Instalacion actual: '.$root.'/kronoqr · paquete nuevo: '.$root.'/kronoqr-2.2.1',
        'Copia previa: '.$backup.'/daily/pre-update-2.2.1.kqe (verificada)',
        'Estado final: 2.2.1 · operativa',
        '',
    ]));

    // Copias existentes y sin escritura: el caso que `doctor` da por bueno.
    chmod($backup, 0o555);

    $environment = diagnosticsPathsEnvironment();

    foreach ($environment as $key => $value) {
        DiagnosticsPathsAndSealingSeed::$previousEnv[$key] = $_ENV[$key] ?? null;
        $_ENV[$key] = $value;
    }

    config([
        'app.url' => $environment['APP_URL'],
        'backup.path' => $backup,
        'observability.metrics.textfile_path' => $backup.'/metrics',
        'branding.logo_root' => $environment['BRANDING_LOGO_ROOT'],
        'compliance.retention.report_path' => $environment['COMPLIANCE_RETENTION_REPORT_PATH'],
        'compliance.retention.technical_log_path' => $environment['COMPLIANCE_TECHNICAL_LOG_PATH'],
        'product.diagnostics_storage_path' => $environment['PRODUCT_DIAGNOSTICS_PATH'],
        'product.data_export_path' => $environment['PRODUCT_DATA_EXPORT_PATH'],
    ]);

    // Un error cuyo texto lleva la ruta de las copias.
    $now = now()->toDateTimeString('microsecond');
    DB::table('error_events')->insert([
        'fingerprint' => bin2hex(random_bytes(32)),
        'level' => 'error',
        'source' => 'scheduler',
        'module' => 'compliance',
        'code' => null,
        'message' => 'No se pudo escribir en '.$backup.'/daily: permiso denegado',
        'exception_class' => 'RuntimeException',
        'file' => 'app/Modules/Compliance/Infrastructure/Backup/Writer.php',
        'line' => 42,
        'context' => json_encode(['command' => 'backup:run']),
        'trace_id' => str_repeat('a1b2c3d4', 4),
        'device_id' => null,
        'employee_uuid' => null,
        'app_version' => '2.3.0',
        'occurrences' => 1,
        'first_seen_at' => $now,
        'last_seen_at' => $now,
        'resolved_at' => null,
        'resolved_by_user_id' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Tres personas con nombre, codigo, correo y un tramo de hoy.
    $siteId = WorkforceFixtures::onlySiteId();
    DiagnosticsPathsAndSealingSeed::$people = [];

    foreach ([['Lucia', 'Ferrandiz Olmedo', 'HES-7731'], ['Bartolome', 'Quintanilla Ruiz', 'HES-7732'], ['Remedios', 'Altamirano Vidal', 'HES-7733']] as $index => [$first, $last, $code]) {
        $uuid = WorkforceFixtures::employee($siteId, firstName: $first, lastName: $last, employeeCode: $code);
        $email = strtolower($first).'.'.Str::slug($last).'@hotel-ejemplo-sol.test';
        DB::table('employees')->where('uuid', $uuid)->update(['email' => $email]);
        $clockedIn = now()->subHours(5 + $index)->addMinutes(7 * ($index + 1))->toIso8601String();
        // La duracion es la de sus marcas (trigger de RN-06): hasta hace una hora.
        $minutes = (4 + $index) * 60 - 7 * ($index + 1);

        DB::table('shift_entries')->insert([
            'uuid' => Str::uuid7()->toString(),
            'employee_id' => DB::table('employees')->where('uuid', $uuid)->value('id'),
            'site_id' => $siteId,
            'work_date' => now()->toDateString(),
            'clocked_in_at' => $clockedIn,
            'clocked_out_at' => now()->subHour()->toIso8601String(),
            'duration_minutes' => $minutes,
            'status' => 'closed',
            'clock_in_source' => 'qr_kiosk',
            'clock_out_source' => 'qr_kiosk',
            'version' => 1,
            'created_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
        ]);

        DiagnosticsPathsAndSealingSeed::$people[] = [
            'name' => $first.' '.$last,
            'code' => $code,
            'email' => $email,
            'uuid' => $uuid,
            'clocked_in_at' => substr($clockedIn, 0, 16),
        ];
    }
});

afterEach(function (): void {
    foreach (DiagnosticsPathsAndSealingSeed::$previousEnv as $key => $value) {
        if ($value === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $value;
        }
    }

    DiagnosticsPathsAndSealingSeed::$previousEnv = [];
    DiagnosticsRecipient::uninstall();

    $root = DiagnosticsPathsAndSealingSeed::$root;

    if ($root !== '' && is_dir($root)) {
        @chmod(DiagnosticsPathsAndSealingSeed::$backup, 0o755);
        exec('rm -rf '.escapeshellarg($root));
    }
});

/**
 * El `payload` del unico asiento de esa accion.
 *
 * @return array<string, mixed>
 */
function diagnosticsPathsPayload(string $action): array
{
    $payload = DB::table('audit_log')->where('action', $action)->value('payload');
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(is_string($payload) ? $payload : '{}', true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

function diagnosticsPathsToken(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
}

/**
 * Lo que no puede aparecer en ningun byte del anonimizado.
 *
 * `base_path()` y `storage_path()` se buscan despues de quitar las rutas que el
 * catalogo de `doctor` documenta de la imagen del producto (`/var/www/html/…`),
 * que son las mismas en todas las instalaciones: lo que se busca es la ruta de
 * ESTE servidor.
 *
 * @return list<string>
 */
function diagnosticsPathsForbidden(): array
{
    return [
        DiagnosticsPathsAndSealingSeed::$root,
        DiagnosticsPathsAndSealingSeed::$backup,
        ...array_values(array_filter(diagnosticsPathsEnvironment(), static fn (string $value): bool => str_starts_with($value, '/'))),
        'hotel-ejemplo-sol',
        DIAGNOSTICS_PATHS_HOST,
    ];
}

function diagnosticsPathsAssertAnonymized(string $bytes): void
{
    diagnosticsPathsAssertNoServerPaths($bytes);
    diagnosticsPathsAssertUseful($bytes);
}

/** Ningun byte con una ruta de este servidor ni su nombre publico. */
function diagnosticsPathsAssertNoServerPaths(string $bytes): void
{
    foreach (diagnosticsPathsForbidden() as $forbidden) {
        expect(str_contains($bytes, $forbidden))->toBeFalse('El paquete anonimizado lleva «'.$forbidden.'».');
    }

    preg_match_all('#/var/www/html[^\s\'"`,;)]*#', Repo::contents('backend/lang/es/doctor.php'), $product);
    $local = str_replace(array_unique($product[0]), '', $bytes);

    foreach ([base_path(), storage_path()] as $server) {
        expect(str_contains($local, json_encode($server, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)))->toBeFalse()
            ->and(str_contains($local, $server.'/'))->toBeFalse('El paquete anonimizado lleva la ruta '.$server.'.');
    }
}

/**
 * Control positivo: un paquete vacio pasaria las negativas. Las comprobaciones
 * que hablan de esas ubicaciones estan, con su estado y la etiqueta del
 * producto, y `configuration.locations` dice lo que soporte necesita.
 */
function diagnosticsPathsAssertUseful(string $bytes): void
{
    /** @var array<string, mixed> $document */
    $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    /** @var list<array{id: string, status: string, summary: string, details: array<string, mixed>}> $checks */
    $checks = data_get($document, 'doctor.checks', []);
    $byId = array_column($checks, null, 'id');
    /** @var array<string, mixed> $env */
    $env = data_get($document, 'configuration.env', []);

    // Control positivo: las comprobaciones que hablan de esas ubicaciones estan,
    // con su estado y la etiqueta del producto.
    expect($byId)->toHaveKeys(['permissions.backup_path', 'disk.backup', 'tls.certificate'])
        ->and($byId['permissions.backup_path']['status'])->toBe('ok')
        ->and($byId['permissions.backup_path']['details']['path'] ?? null)->toBe('<backup_path>')
        ->and($byId['permissions.backup_path']['summary'])->toContain('<backup_path>')
        ->and($byId['disk.backup']['details']['path'] ?? null)->toBe('<backup_path>')
        ->and($byId['tls.certificate']['status'])->toBeIn(['ok', 'warning', 'failure'])
        ->and(data_get($document, 'configuration.locations.backup_path.customized'))->toBeTrue()
        ->and(data_get($document, 'configuration.locations.backup_path.exists'))->toBeTrue()
        ->and(data_get($document, 'configuration.locations.backup_path.writable'))->toBeFalse()
        ->and(data_get($document, 'configuration.locations.branding_logo_root.exists'))->toBeFalse()
        ->and($env)->not->toHaveKey('BRANDING_LOGO_ROOT')
        ->and($env)->not->toHaveKey('PRODUCT_DIAGNOSTICS_PATH')
        ->and(data_get($document, 'updates.location'))->toBe('<backup_path>/reports')
        ->and((string) json_encode(data_get($document, 'updates.latest.content'), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
        ->toContain('Copia previa: <backup_path>/daily/');

    if (($byId['tls.certificate']['details']['resolvable'] ?? true) === false) {
        // Con un nombre que no resuelve, el texto lo nombra: sale la etiqueta.
        expect($byId['tls.certificate']['summary'])->toContain('[app_host]');
    }
}

it('el anonimizado del panel no lleva rutas del servidor ni el host, y dice lo que soporte necesita', function (): void {
    $response = Api::as(diagnosticsPathsToken())->post('/api/v1/diagnostics/bundle')->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('application/json');

    diagnosticsPathsAssertAnonymized((string) $response->getContent());
})->group('RL-19', 'RF-PD-09');

it('el anonimizado de consola tampoco', function (): void {
    $output = sys_get_temp_dir().'/kq-paths-console-'.bin2hex(random_bytes(4));
    mkdir($output, 0o700);

    try {
        expect(Artisan::call('product:diagnostics', ['--output' => $output]))->toBe(0);

        $files = glob($output.'/kronoqr-diagnostics-*.json') ?: [];

        expect($files)->toHaveCount(1);

        diagnosticsPathsAssertAnonymized((string) file_get_contents($files[0]));
    } finally {
        exec('rm -rf '.escapeshellarg($output));
    }
})->group('RL-19', 'RF-PD-09');

it('el doctor local sigue enseñando la ruta real a quien administra el servidor', function (): void {
    Artisan::call('product:doctor', ['--json' => true]);
    $output = Artisan::output();
    $backup = DiagnosticsPathsAndSealingSeed::$backup;

    expect(str_contains($output, $backup) || str_contains($output, str_replace('/', '\/', $backup)))->toBeTrue();
})->group('RF-PD-13');

it('con datos personales los bytes no llevan a nadie, y solo los abre la clave de soporte', function (): void {
    $recipient = DiagnosticsRecipient::install();

    $response = Api::as(diagnosticsPathsToken())
        ->post('/api/v1/diagnostics/bundle', ['include_personal_data' => true, 'period_days' => 7])
        ->assertOk();

    $bytes = (string) $response->getContent();

    expect($response->headers->get('Content-Type'))->toStartWith('application/vnd.kronoqr.diagnostics-sealed+json')
        ->and(ComponentSchema::errors('SealedDiagnosticsBundle', $bytes))->toBe([]);

    foreach (DiagnosticsPathsAndSealingSeed::$people as $person) {
        foreach (['name', 'code', 'email', 'uuid', 'clocked_in_at'] as $field) {
            expect(str_contains($bytes, $person[$field]))->toBeFalse('El sobre lleva en claro '.$field.': '.$person[$field]);
        }
    }

    // Con otro par recien generado no abre.
    expect(DiagnosticsRecipient::generate()->run($bytes)['exit'])->toBe(1);

    // Con la privada del par instalado, abre, sale integro y estan las personas.
    $opened = $recipient->run($bytes);

    expect($opened['exit'])->toBe(0, $opened['stderr'])
        ->and($opened['stdout'])->toContain('huella integra');

    foreach (DiagnosticsPathsAndSealingSeed::$people as $person) {
        expect($opened['plaintext'])->toContain($person['name']);
    }
})->group('RL-19', 'RF-PD-09', 'RS-08');

it('con la cabecera alterada el sobre no abre', function (string $field, mixed $value): void {
    $recipient = DiagnosticsRecipient::install();

    $bytes = (string) Api::as(diagnosticsPathsToken())
        ->post('/api/v1/diagnostics/bundle', ['include_personal_data' => true])
        ->assertOk()
        ->getContent();

    /** @var array<string, mixed> $document */
    $document = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    data_set($document, $field, $value);

    expect($recipient->run((string) json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))['exit'])->toBe(1);
})->with([
    'anonymized a true' => ['manifest.anonymized', true],
    'sin una seccion' => ['manifest.sections', ['installation']],
    'otro recuento' => ['contents.plaintext_bytes', 1],
])->group('RL-19', 'RS-08');

it('los dos asientos dicen que salio cifrado, para que clave y con la huella de los bytes entregados', function (): void {
    $recipient = DiagnosticsRecipient::install();

    $response = Api::as(diagnosticsPathsToken())
        ->post('/api/v1/diagnostics/bundle', ['include_personal_data' => true])
        ->assertOk();

    $bytes = (string) $response->getContent();
    $generated = diagnosticsPathsPayload('diagnostics.bundle_generated');
    $personal = diagnosticsPathsPayload('diagnostics.personal_data_included');

    $plaintextDigest = $response->json('manifest.sha256');

    expect($generated)->toMatchArray([
        'sealed' => true,
        'anonymized' => false,
        'recipient_key_id' => $recipient->keyId,
        'sealed_sha256' => hash('sha256', $bytes),
        'size_bytes' => \strlen($bytes),
        'sha256' => $plaintextDigest,
    ])->and($personal)->toMatchArray([
        'sealed' => true,
        'recipient_key_id' => $recipient->keyId,
        'sealed_sha256' => hash('sha256', $bytes),
        'sha256' => $plaintextDigest,
    ]);
})->group('RL-19', 'RF-PD-09');

it('el anonimizado deja sealed false y nada mas del sobre en su asiento', function (): void {
    Api::as(diagnosticsPathsToken())->post('/api/v1/diagnostics/bundle')->assertOk();

    $generated = diagnosticsPathsPayload('diagnostics.bundle_generated');

    expect($generated['sealed'] ?? null)->toBeFalse()
        ->and($generated)->not->toHaveKey('recipient_key_id')
        ->and($generated)->not->toHaveKey('sealed_sha256');
})->group('RL-19', 'RF-PD-09');
