<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\Credentials;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `GET /api/v1/kiosk/roster` — la divulgacion (RS-05, RF-KI-03, RL-15) y el
 * alcance por centro (RS-04, doc 02 §7.3).
 *
 * Es el endpoint por el que la plantilla de un centro sale del servidor hacia un
 * dispositivo colgado de una pared. Tres promesas escritas en `BuildKioskRoster`
 * y en `KioskDevice` no las comprobaba nadie:
 *
 *   1. **Queda asiento.** Sin el, RL-15 —«determinar el alcance de una brecha a
 *      partir del trail»— no se puede cumplir para el conjunto de datos que mas
 *      facil es perder: el que vive en una tablet.
 *   2. **El asiento describe el alcance y jamas lo divulgado** (regla dura 21).
 *   3. **El centro sale del token.** Un quiosco no puede ni formular la peticion
 *      «dame el padron del otro hotel de la cadena».
 */

uses(RefreshDatabase::class);

/**
 * Un hotel con dos personas con tarjeta impresa y su quiosco.
 *
 * @return array{token: string, site: int, deviceUuid: string}
 */
function hotelConDosTarjetas(): array
{
    $escenario = AttendanceFixtures::scenario();

    Credentials::issueFor(AttendanceFixtures::employeeIdOf($escenario['employee']));

    $segunda = WorkforceFixtures::employee($escenario['site'], $escenario['department']);
    Credentials::issueFor(AttendanceFixtures::employeeIdOf($segunda));

    return [
        'token' => $escenario['token'],
        'site' => $escenario['site'],
        'deviceUuid' => $escenario['deviceUuid'],
    ];
}

it('deja asiento de la divulgacion cada vez que un quiosco descarga el padron', function (): void {
    $hotel = hotelConDosTarjetas();

    expect(DB::table('audit_log')->count())->toBe(0);

    Api::as($hotel['token'])
        ->get('/api/v1/kiosk/roster')
        ->assertStatus(200)
        ->assertJsonCount(2, 'entries');

    $asiento = DB::table('audit_log')->orderBy('id')->first();

    expect($asiento)->not->toBeNull();

    /** @var object{action: string, subject_type: string, payload: string} $asiento */
    $payload = json_decode($asiento->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($asiento->action)->toBe('personal_data.accessed')
        ->and($asiento->subject_type)->toBe('personal_data')
        ->and($payload)->toMatchArray([
            'dataset' => 'kiosk_roster',
            // El recuento es lo que convierte «una tablet pidio el padron» en
            // «una tablet se llevo dos fichas de personal».
            'record_count' => 2,
            'site_id' => $hotel['site'],
            'device_uuid' => $hotel['deviceUuid'],
        ]);
})->group('RS-05', 'RF-KI-03', 'RL-15');

it('registra el alcance del padron y jamas los nombres ni los hashes que reparte', function (): void {
    // Regla dura 21. Un `audit_log` que copiara lo divulgado seria una segunda
    // copia del padron, con cuatro años de retencion (RL-02) y en la tabla que se
    // enseña en una inspeccion.
    $hotel = hotelConDosTarjetas();

    $respuesta = Api::as($hotel['token'])->get('/api/v1/kiosk/roster')->assertStatus(200);

    $primerHash = $respuesta->json('entries.0.token_hash');

    expect($primerHash)->toBeString();

    $asiento = DB::table('audit_log')->orderBy('id')->first();

    expect($asiento)->not->toBeNull();

    /** @var object{payload: string} $asiento */
    expect($asiento->payload)->not->toContain('Persona')
        ->and($asiento->payload)->not->toContain('De Prueba')
        ->and($asiento->payload)->not->toContain(is_string($primerHash) ? $primerHash : '@');
})->group('RS-05', 'RF-KI-03');

it('sirve solo el padron del centro del quiosco, no el de otro hotel de la cadena', function (): void {
    // El alcance del §7.3, comprobado sobre datos y no sobre la firma del metodo:
    // la promesa es «solo el minimo necesario DEL CENTRO al que esta vinculado el
    // dispositivo». El otro hotel existe y tiene tarjetas impresas; si el filtro
    // se cayera, esta prueba veria tres entradas en vez de dos.
    $hotel = hotelConDosTarjetas();

    $otroHotel = WorkforceFixtures::site('Hotel de la otra punta');
    $ajena = WorkforceFixtures::employee($otroHotel);
    Credentials::issueFor(AttendanceFixtures::employeeIdOf($ajena));

    Api::as($hotel['token'])
        ->get('/api/v1/kiosk/roster')
        ->assertStatus(200)
        ->assertJsonCount(2, 'entries');
})->group('RS-04', 'RF-KI-03');

it('rechaza un site_id en la peticion en vez de ignorarlo en silencio', function (): void {
    // `RejectsUnknownInput`. Ignorarlo dejaria a quien lo envia convencido de
    // haber pedido el padron de otro centro y de haberlo recibido — cuando lo que
    // recibio fue el suyo. Es la diferencia entre un control y una coincidencia.
    //
    // `400` y no `422`: en el camino del quiosco un `422` significa «tarjeta
    // rechazada», y una peticion mal formada no puede compartir codigo con eso
    // (regla dura 17).
    $hotel = hotelConDosTarjetas();

    Api::as($hotel['token'])
        ->get('/api/v1/kiosk/roster', ['site_id' => 999])
        ->assertStatus(400);

    // Y un intento rechazado no es una divulgacion: no se ha entregado nada.
    expect(DB::table('audit_log')->count())->toBe(0);
})->group('RS-04', 'RF-KI-03', 'RS-05');

it('no incluye en el padron a quien todavia no tiene tarjeta impresa', function (): void {
    // ADR-034: el QR se acuña al imprimir. Quien tiene la credencial emitida pero
    // sin imprimir no tiene tarjeta que resolver, asi que su nombre solo seria un
    // nombre de mas viajando a una tablet (minimizacion, RL-12).
    //
    // **Que no aparezca no le impide fichar** (regla dura 19): el quiosco encola
    // igual y el servidor resuelve la credencial al sincronizar.
    $hotel = hotelConDosTarjetas();

    $sinImprimir = WorkforceFixtures::employee($hotel['site']);
    Credentials::pendingFor(AttendanceFixtures::employeeIdOf($sinImprimir));

    Api::as($hotel['token'])
        ->get('/api/v1/kiosk/roster')
        ->assertStatus(200)
        ->assertJsonCount(2, 'entries');
})->group('RF-KI-03', 'RL-12');
