<?php

declare(strict_types=1);

use App\Modules\Reporting\Application\Port\ReportExportRepository;
use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\DataExports;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Reporting\ReportExports;
use Tests\Support\Shared\GeneratedFilesSandbox;
use Tests\Support\Shared\RecordingGeneratedFileMetrics;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Las dos descargas solo entregan un fichero DENTRO de su raiz (ADR-045,
 * hallazgo F3 de seguridad; RS-05, RS-08).
 *
 * Antes las dos servian el `file_path` de la fila con un `is_file()`: quien
 * pudiera escribir en `data_exports` o `report_exports` ponia
 * `file_path='/proc/self/environ'` en una fila `completed` y la descarga
 * entregaba `APP_KEY`, la contraseña de la base de datos y las claves del QR.
 * Ahora la ruta pasa por el localizador confinado de su clase: fuera de la
 * raiz, con un nombre que no es de la clase o a traves de un enlace simbolico,
 * `404` sin decir por que y la metrica `refused` sube.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
    DataExports::useTemporaryPath();
    ReportExports::useTemporaryPath();
});

afterEach(function (): void {
    DataExports::cleanUpTemporaryPath();
    ReportExports::cleanUpTemporaryPath();
    GeneratedFilesSandbox::cleanUp();
});

/** Un fichero con «secretos» fuera de cualquier raiz de clase. */
function secretoFueraDeLaRaiz(): string
{
    return GeneratedFilesSandbox::file(GeneratedFilesSandbox::directory('secretos').'/environ', "APP_KEY=base64:secreto\n");
}

/**
 * Las tres alteraciones de la ruta de una fila de exportacion integra.
 *
 * @return array<string, Closure(string): string>
 */
function alteracionesDeExportacion(): array
{
    return [
        'ruta fuera de la raiz' => static fn (string $secreto): string => $secreto,
        'enlace simbolico dentro de la raiz con nombre de ZIP' => static function (string $secreto): string {
            $enlace = config()->string('product.data_export_path').'/kronoqr-export-2.2.0-20261002T120000Z.zip';
            symlink($secreto, $enlace);

            return $enlace;
        },
        'nombre fuera de patron dentro de la raiz' => static function (string $secreto): string {
            return GeneratedFilesSandbox::file(config()->string('product.data_export_path').'/environ', (string) file_get_contents($secreto));
        },
    ];
}

it('la descarga de la exportacion integra no entrega una ruta alterada', function (string $caso): void {
    $metricas = RecordingGeneratedFileMetrics::install();
    $admin = ManagementUsers::withRole(UserRole::ADMIN);
    $exportacion = DataExports::completed(requestedByUserId: $admin->id);
    $alterada = alteracionesDeExportacion()[$caso](secretoFueraDeLaRaiz());
    DB::table('data_exports')->where('uuid', $exportacion->uuid)->update(['file_path' => $alterada]);

    $respuesta = Api::as(ManagementUsers::tokenFor($admin))->get('/api/v1/data-export/'.$exportacion->uuid.'/download');

    $respuesta->assertStatus(404);

    expect((string) $respuesta->getContent())->not->toContain('APP_KEY')
        ->and($metricas->refused)->toBe(['data_export'])
        // Sin descarga no hay asiento de descarga.
        ->and(DB::table('audit_log')->where('action', 'data_export.downloaded')->count())->toBe(0);
})->with([
    'ruta fuera de la raiz',
    'enlace simbolico dentro de la raiz con nombre de ZIP',
    'nombre fuera de patron dentro de la raiz',
])->group('RF-PD-14', 'RS-05');

it('la descarga legitima de la exportacion integra sigue funcionando', function (): void {
    $admin = ManagementUsers::withRole(UserRole::ADMIN);
    $exportacion = DataExports::completed(requestedByUserId: $admin->id);

    Api::as(ManagementUsers::tokenFor($admin))
        ->get('/api/v1/data-export/'.$exportacion->uuid.'/download')
        ->assertOk();
})->group('RF-PD-14', 'RL-20');

/** Emite un enlace de un solo uso para el informe y devuelve su token. */
function tokenDeInforme(ReportExport $informe): string
{
    $token = 'token-'.bin2hex(random_bytes(12));

    app(ReportExportRepository::class)->save(
        $informe->issueDownloadToken(hash('sha256', $token), app(Clock::class)->now()->modify('+15 minutes')),
    );

    return $token;
}

it('la descarga de un informe no entrega una ruta alterada ni gasta el enlace', function (string $caso): void {
    $metricas = RecordingGeneratedFileMetrics::install();
    $informe = ReportExports::completedFor(ManagementUsers::withRole(UserRole::RRHH)->id);
    $secreto = secretoFueraDeLaRaiz();
    $raiz = config()->string('reporting.export.path');

    $alterada = match ($caso) {
        'ruta fuera de la raiz' => $secreto,
        'enlace simbolico dentro de su directorio' => (static function () use ($secreto, $informe): string {
            $enlace = \dirname((string) $informe->filePath).'/enlace.csv';
            symlink($secreto, $enlace);

            return $enlace;
        })(),
        default => GeneratedFilesSandbox::file($raiz.'/no-es-un-uuid/x.csv', "APP_KEY=base64:secreto\n"),
    };

    DB::table('report_exports')->where('id', $informe->id)->update(['file_path' => $alterada]);
    $token = tokenDeInforme(ReportExports::find($informe->uuid));

    $respuesta = Api::guest()->get('/api/v1/reports/exports/'.$informe->uuid.'/download?token='.$token);

    $respuesta->assertStatus(404);

    expect((string) $respuesta->getContent())->not->toContain('APP_KEY')
        ->and($metricas->refused)->toBe(['report_export'])
        // El enlace no se gasta contra nada.
        ->and(ReportExports::find($informe->uuid)->downloadCount)->toBe(0);
})->with([
    'ruta fuera de la raiz',
    'enlace simbolico dentro de su directorio',
    'directorio fuera de patron dentro de la raiz',
])->group('RF-IN-06', 'RS-05');

it('la descarga legitima de un informe sigue siendo de un solo uso', function (): void {
    $informe = ReportExports::completedFor(ManagementUsers::withRole(UserRole::RRHH)->id);
    $token = tokenDeInforme($informe);
    $url = '/api/v1/reports/exports/'.$informe->uuid.'/download?token='.$token;

    Api::guest()->get($url)->assertOk();
    Api::guest()->get($url)->assertStatus(410);

    expect(ReportExports::find($informe->uuid)->downloadCount)->toBe(1);
})->group('RF-IN-06', 'RS-05');
