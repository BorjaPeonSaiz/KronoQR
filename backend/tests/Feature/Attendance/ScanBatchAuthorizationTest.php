<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Identity\Infrastructure\Persistence\Device as DeviceModel;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Regla dura 18 y RQ-07: `POST /api/v1/scan/batch` con su prueba de autorizacion
 * negativa **por cada rol no autorizado**, sin excepciones (H-02 de la revision
 * interna ASVS de 2026-09, decision 12 de la ficha 3.8).
 *
 * ## Por que faltaba y por que importa
 *
 * `ScanBatchTest` cubre catorce caminos de este endpoint y ninguno es un `401`
 * ni un `403`: se escribio para probar que el lote se convierte en tramos. Y
 * `/scan/batch` es el hermano gemelo de `/scan` en lo unico que de verdad
 * importa —**crea registro horario**— con dos agravantes propios:
 *
 *   - Un lote trae hasta cincuenta escaneos por peticion, asi que quien lo
 *     alcance no fabrica una jornada sino una nomina entera de golpe.
 *   - El lote trae su propio `occurred_at`, porque sincronizar es fichar con
 *     retraso (regla dura 9). Quien pueda llamarlo elige **cuando** ocurrio lo
 *     que no ocurrio, y el registro legal usa ese campo.
 *
 * ## Los dos controles del §7.3, comprobados por separado
 *
 *   - **El ambito del token** (`scan:write`), que verifica el middleware
 *     `ability`. Es lo que deja fuera a toda sesion de gestion y al portal:
 *     ninguna lo lleva.
 *   - **La policy** `ScanPolicy`, que verifica QUIEN porta el token. Es lo que
 *     deja fuera a una cuenta que tuviera el ambito sin ser un dispositivo.
 *
 * Que los dos devuelvan 403 es correcto: desde fuera no se distingue por que se
 * ha denegado.
 *
 * ## Lo que esta prueba NO afirma
 *
 * Que el lote se procese bien. Eso es `ScanBatchTest` (feature y contrato) y
 * `Tests\Unit\Attendance\Application\ScanBatchTest` (el orden, sin base de
 * datos). Aqui solo se comprueba quien llega a la puerta.
 */

uses(RefreshDatabase::class);

const TARJETA_LOTE_DENEGADO = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa';

/**
 * Un elemento de cola valido, para que lo que falle sea la autorizacion y no la
 * validacion: un `422` esconderia si la policy funciona.
 *
 * @return array{scan_id: string, occurred_at: string, qr_payload: string}
 */
function escaneoDelLote(string $occurredAt, string $payload = TARJETA_LOTE_DENEGADO): array
{
    return [
        'scan_id' => Str::uuid7()->toString(),
        'occurred_at' => $occurredAt,
        'qr_payload' => $payload,
    ];
}

/**
 * Un lote valido de dos elementos —entrada y salida de la misma jornada—, con la
 * clave de idempotencia que el contrato exige para el envio.
 *
 * Dos y no uno a proposito: es la forma real de lo que drena el quiosco al
 * recuperar la red, y es la que hace significativa la prueba de que la
 * denegacion no cuenta cuantos elementos traia.
 *
 * @return array{0: string, 1: array{scans: list<array{scan_id: string, occurred_at: string, qr_payload: string}>}}
 */
function loteValido(): array
{
    return [Str::uuid7()->toString(), ['scans' => [
        escaneoDelLote('2026-03-14T07:02:31Z'),
        escaneoDelLote('2026-03-14T15:03:12Z'),
    ]]];
}

it('no deja sincronizar un lote sin token', function (): void {
    // La mitad de la regla dura 18 que mas se olvida: comprobar tambien que sin
    // credenciales no se entra. Y en este endpoint es la mas facil de dejarse,
    // porque su respuesta normal es un `207` que nunca es `200`: una prueba que
    // solo mirara «no es 200» habria dado verde con la puerta abierta.
    [$batchKey, $cuerpo] = loteValido();

    Api::guest()
        ->withHeaders(['Idempotency-Key' => $batchKey])
        ->post('/api/v1/scan/batch', $cuerpo)
        ->assertStatus(401);

    expect(DB::table('scan_events')->count())->toBe(0);
})->group('RS-04', 'RQ-07', 'RF-KI-04');

it('deniega la sincronizacion del lote a cada rol de gestion', function (UserRole $rol): void {
    // Ninguna sesion de gestion lleva `scan:write` (§7.3), asi que ni el
    // administrador de la instalacion puede inyectar un lote. Para rectificar el
    // registro horario existe la correccion trazada de RF-PA-04, que deja autor
    // y motivo (RN-13); cincuenta fichajes entrados por aqui serian
    // indistinguibles de los reales.
    [$batchKey, $cuerpo] = loteValido();

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole($rol));

    Api::as($token)
        ->withHeaders(['Idempotency-Key' => $batchKey])
        ->post('/api/v1/scan/batch', $cuerpo)
        ->assertStatus(403);

    expect(DB::table('scan_events')->count())->toBe(0);
})->with([
    'administrador' => [UserRole::ADMIN],
    'rrhh' => [UserRole::RRHH],
    'responsable de departamento' => [UserRole::RESPONSABLE_DEPARTAMENTO],
    'auditor' => [UserRole::AUDITOR],
    'empleado' => [UserRole::EMPLEADO],
])->group('RS-04', 'RQ-07', 'RF-KI-04');

it('deniega la sincronizacion a una cuenta con rol kiosk que no es un dispositivo', function (): void {
    // El caso que la policy existe para cubrir y que el ambito NO cubre: esta
    // cuenta si lleva `scan:write`, asi que pasa el middleware `ability` y llega
    // al `FormRequest`. Lo que la para es que su `tokenable` es una fila de
    // `users` y no de `devices`.
    //
    // No es hipotetico: es lo que ocurriria si alguien decidiera «dar de alta un
    // usuario por tablet» en lugar de emparejar el dispositivo (RF-ID-04).
    [$batchKey, $cuerpo] = loteValido();

    Api::as(ManagementUsers::kioskToken())
        ->withHeaders(['Idempotency-Key' => $batchKey])
        ->post('/api/v1/scan/batch', $cuerpo)
        ->assertStatus(403);

    expect(DB::table('scan_events')->count())->toBe(0);
})->group('RS-04', 'RQ-07', 'RF-ID-04');

it('deniega la sincronizacion a un quiosco cuyo token no tiene scan:write', function (): void {
    // El caso simetrico del anterior: el portador SI es un dispositivo, pero su
    // token solo puede leer el padron. Es lo que hace que un token de quiosco
    // comprometido no sirva para lo que no se le concedio (§7.3), y aqui vale
    // doble: `/scan` y `/scan/batch` comparten ambito a proposito —sincronizar
    // es fichar con retraso, no una potestad distinta— asi que si el ambito
    // dejara de comprobarse en uno de los dos, el otro seguiria en verde.
    [$batchKey, $cuerpo] = loteValido();

    $site = WorkforceFixtures::site('Hotel sin ambito');
    $device = AttendanceFixtures::device($site);
    $sinAmbito = DeviceModel::query()->findOrFail($device['id'])
        ->createToken('Solo padron', [TokenAbility::ROSTER_READ->value])
        ->plainTextToken;

    Api::as($sinAmbito)
        ->withHeaders(['Idempotency-Key' => $batchKey])
        ->post('/api/v1/scan/batch', $cuerpo)
        ->assertStatus(403);

    expect(DB::table('scan_events')->count())->toBe(0);
})->group('RS-04', 'RQ-07', 'RF-ID-04');

it('deniega la sincronizacion a una sesion de portal', function (): void {
    // ADR-015 y §7.3: el token del portal lleva `self:read` y nada mas. Entra
    // como caso propio y no como el `empleado` del conjunto de arriba porque lo
    // que se comprueba es otra cosa: aquel es un ROL sin ambito de fichaje, este
    // es el token que el producto emite de verdad a una persona de la plantilla
    // (`POST /api/v1/me/login`). El portal es de LECTURA: nadie ficha desde el
    // movil, ni por si mismo ni con una cola inventada.
    [$batchKey, $cuerpo] = loteValido();

    $empleado = ManagementUsers::withRole(UserRole::EMPLEADO);
    $tokenDePortal = $empleado->createToken('Portal', [TokenAbility::SELF_READ->value])->plainTextToken;

    Api::as($tokenDePortal)
        ->withHeaders(['Idempotency-Key' => $batchKey])
        ->post('/api/v1/scan/batch', $cuerpo)
        ->assertStatus(403);

    expect(DB::table('scan_events')->count())->toBe(0);
})->group('RS-04', 'RQ-07', 'RF-ID-07');

it('no revela nada del lote en una respuesta denegada', function (): void {
    // Regla dura 17 llevada al borde de la autorizacion. La respuesta normal de
    // este endpoint es un `207` que enumera **un resultado por elemento**, asi
    // que la tentacion de que el error se le parezca es real: bastaria con que
    // el `403` dijera cuantos elementos traia el lote, o repitiera sus
    // `scan_id`, para convertir la denegacion en un acuse de recibo — y para
    // confirmarle a quien robo una tablet que sus reenvios llegan.
    //
    // Se comparan dos lotes que no se parecen en nada: uno de dos elementos y
    // otro de un solo elemento con otra tarjeta. Los cuerpos deben ser
    // identicos.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    [$primeraClave, $lote] = loteValido();

    $denegadoGrande = Api::as($token)
        ->withHeaders(['Idempotency-Key' => $primeraClave])
        ->post('/api/v1/scan/batch', $lote);

    $denegadoPequeno = Api::as($token)
        ->withHeaders(['Idempotency-Key' => Str::uuid7()->toString()])
        ->post('/api/v1/scan/batch', ['scans' => [
            escaneoDelLote('2026-03-14T22:14:09Z', 'FH1.b7.0000000000000000000000.0000000000000000'),
        ]]);

    $denegadoGrande->assertStatus(403);
    $denegadoPequeno->assertStatus(403);

    expect($denegadoGrande->json())->toBe($denegadoPequeno->json());

    $cuerpoDenegado = json_encode($denegadoGrande->json(), JSON_THROW_ON_ERROR);

    expect($cuerpoDenegado)->not->toContain($lote['scans'][0]['scan_id']);
    expect($cuerpoDenegado)->not->toContain(TARJETA_LOTE_DENEGADO);
})->group('RS-03', 'RS-04', 'RF-KI-04');

it('deja sincronizar al quiosco, que es el control positivo de los seis casos de arriba', function (): void {
    // Sin esto, los `401` y los `403` de arriba pasarian identicos si la ruta no
    // existiera o estuviera rota: un endpoint que revienta y uno que deniega se
    // parecen mucho desde una prueba que solo mira que no sea 207.
    //
    // No se comprueba lo que hace el lote —eso es `ScanBatchTest`—, solo que el
    // portador legitimo **atraviesa la autorizacion**. La tarjeta no la resuelve
    // ningun doble a proposito: un `207` con los dos elementos rechazados
    // demuestra que se llego al caso de uso, que es justo lo que aqui hay que
    // demostrar.
    $escenario = AttendanceFixtures::scenario();

    [$batchKey, $cuerpo] = loteValido();

    Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $batchKey])
        ->post('/api/v1/scan/batch', $cuerpo)
        ->assertStatus(207);
})->group('RS-04', 'RQ-07', 'RF-KI-04');
