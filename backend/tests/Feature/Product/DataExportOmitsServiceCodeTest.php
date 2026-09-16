<?php

declare(strict_types=1);

use App\Modules\Product\Application\Command\RequestDataExportCommand;
use App\Modules\Product\Application\UseCase\GenerateDataExportHandler;
use App\Modules\Product\Application\UseCase\RequestDataExportHandler;
use App\Modules\Product\Domain\ValueObject\DataExportOrigin;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\DataExports;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **El codigo de servicio del quiosco tampoco viaja en la exportacion integra**
 * (RF-KI-08, **RF-PD-14**, RL-20; tarea 3.3, segunda vuelta de
 * `seguridad-cumplimiento`).
 *
 * ## Por que hacia falta esta prueba ademas de la del paquete de diagnostico
 *
 * Son dos ficheros con dos politicas opuestas y por eso se razonan por separado.
 * El **paquete de diagnostico** va anonimizado porque sale hacia el fabricante
 * (ADR-020). La **exportacion integra** se queda con el cliente, que es su
 * responsable del tratamiento (RL-16), asi que lleva sus datos personales
 * enteros — y precisamente por eso nadie habria mirado dos veces si el codigo
 * salia dentro.
 *
 * Pero un ZIP se descarga, se guarda en un disco compartido, se reenvia por
 * correo y se archiva años. Lo que hay dentro de `KIOSK_SERVICE_CODE` es la
 * llave con la que se abre la pantalla de mantenimiento de **todas** las tablets
 * del hotel, y no caduca: es el mismo criterio con el que no salen `pin_hash`
 * ni `token_hash`. Un secreto vivo no es un dato del registro horario.
 *
 * ## La fila SALE; lo que no sale es el valor
 *
 * `value` nulo y `value_redacted: true`. Una fila ausente le diria al cliente
 * «ese ajuste no esta configurado», que es falso; con la columna, el fichero
 * dice que existe, cuando se cambio, quien lo hizo y que el valor se ha retirado
 * a proposito. Es la misma decision que ya toma el asiento de `audit_log` de su
 * propio cambio, para que las dos evidencias digan lo mismo.
 *
 * Y sigue habiendo un sitio donde leerlo: `GET /api/v1/settings`, en la pantalla
 * donde el cliente lo escribio.
 */

uses(RefreshDatabase::class);

/** El codigo sembrado. Si aparece en el ZIP, la redaccion ha fallado. */
const CODIGO_DE_SERVICIO_SEMBRADO = '900112233';

beforeEach(function (): void {
    FrozenTime::at('2026-09-16 10:00:00');
    WorkforceFixtures::site('Hotel de la exportacion', 'Europe/Madrid');
    LicenseKeys::grantAll();

    DataExports::useTemporaryPath();
});

afterEach(function (): void {
    DataExports::cleanUpTemporaryPath();
});

/**
 * Deja el codigo configurado **por la via real** —el panel— y genera el ZIP.
 *
 * Se escribe con el `PATCH` y no con un `INSERT` a proposito: asi la fila lleva
 * su `updated_by_user_id` y su instante, que es lo que la consulta de la
 * exportacion resuelve, y la prueba ejercita el estado que tiene una instalacion
 * de verdad.
 *
 * @return array{path: string, contents: string}
 */
function exportacionConCodigoDeServicio(): array
{
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->patch('/api/v1/settings', [
            'settings' => [
                'KIOSK_SERVICE_CODE' => CODIGO_DE_SERVICIO_SEMBRADO,
                // Una clave corriente al lado, para que el control positivo de
                // mas abajo no dependa de un valor de serie.
                'BRANDING_APP_NAME' => 'Hotel de la exportacion',
            ],
        ])
        ->assertStatus(200);

    $requested = app(RequestDataExportHandler::class)->handle(
        new RequestDataExportCommand(DataExportOrigin::Console, null),
    );

    $export = app(GenerateDataExportHandler::class)->handle($requested->uuid);
    $path = (string) $export->filePath;

    $zip = new ZipArchive;

    expect($zip->open($path))->toBeTrue('No se pudo abrir el ZIP de la exportacion: '.$path);

    $contenido = '';

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $contenido .= (string) $zip->getFromIndex($index);
    }

    $ajustes = (string) $zip->getFromName('installation_settings.json');

    $zip->close();

    return ['path' => $ajustes, 'contents' => $contenido];
}

/**
 * Una fila de `installation_settings.json`, por su clave.
 *
 * Falla con el nombre delante en vez de devolver `null`: una clave que
 * desapareciera del fichero dejaria la prueba comparando contra nada, que es
 * justo el fallo que esta suite existe para detectar.
 *
 * @return array<string, mixed>
 */
function ajusteExportado(string $json, string $key): array
{
    /** @var list<array<string, mixed>> $ajustes */
    $ajustes = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

    foreach ($ajustes as $fila) {
        if (($fila['key'] ?? null) === $key) {
            return $fila;
        }
    }

    throw new RuntimeException('`installation_settings.json` de la exportacion no trae la clave '.$key.'.');
}

it('no escribe el codigo de servicio en ningun fichero del ZIP', function (): void {
    // EL ZIP ENTERO, COMO TEXTO. Es la comprobacion que no depende de por que
    // fichero podria colarse: si el codigo aparece en `installation_settings`,
    // en el `audit_log` del cambio que acaba de hacerse o en el README, esta
    // prueba lo dice.
    $zip = exportacionConCodigoDeServicio();

    expect($zip['contents'])->not->toContain(CODIGO_DE_SERVICIO_SEMBRADO);
})->group('RF-KI-08', 'RF-PD-14', 'RL-20');

it('deja la fila del codigo de servicio marcada como redactada', function (): void {
    // La fila SALE. Ocultarla diria «ese ajuste no esta configurado», que es
    // falso y ademas peor: con la columna, el cliente sabe que existe, cuando se
    // cambio y que el valor se retiro a proposito.
    $zip = exportacionConCodigoDeServicio();

    $fila = ajusteExportado($zip['path'], 'KIOSK_SERVICE_CODE');

    expect($fila['value'])->toBeNull()
        // `true`/`false` como texto: es lo que el escritor produce para las
        // columnas booleanas que vienen de SQL, igual que en el resto del ZIP.
        ->and($fila['value_redacted'])->toBe('true')
        // Y lo que hace util la fila: cuando se cambio y quien lo hizo.
        ->and($fila['updated_at'])->toBeString()
        ->and($fila['updated_by_user_uuid'])->toBeString();
})->group('RF-KI-08', 'RF-PD-14', 'RL-20');

it('sigue exportando entero el valor de las claves que no son secretas', function (): void {
    // EL GUARDA DEL GUARDA. Si la redaccion se aplicara de mas, la exportacion
    // integra dejaria de serlo: el cliente pide **todos** sus datos (RL-20), y
    // una configuracion en blanco no es una copia de nada.
    $zip = exportacionConCodigoDeServicio();

    $fila = ajusteExportado($zip['path'], 'BRANDING_APP_NAME');

    expect($fila['value'])->toBe('Hotel de la exportacion')
        ->and($fila['value_redacted'])->toBe('false');
})->group('RF-PD-14', 'RL-20');

/*
 * La declaracion de la columna en el catalogo —y su descripcion en las dos
 * lenguas— se comprueba en `tests/Unit/Product/Domain/DataExportCatalogTest.php`,
 * que es donde vive el catalogo y donde se puede leer entero sin base de datos.
 */
