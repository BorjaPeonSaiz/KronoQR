<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Support\Database\RefreshDatabase;

/*
 * La ultima linea de defensa del emparejamiento (RF-PD-06), probada **por SQL
 * directo** contra PostgreSQL real.
 *
 * Todo lo que se intenta aqui esta ya prohibido en `PairingRequest` y en los
 * casos de uso, y cubierto por sus pruebas unitarias. Lo que se comprueba aqui
 * es lo OTRO: que si alguien escribe en la tabla **sin pasar por la
 * aplicacion** —una restauracion, un script de correccion, un `UPDATE` mal
 * escrito una noche— PostgreSQL tambien lo rechaza. Doc 02 §3.2: «en un sistema
 * con valor probatorio la integridad no puede depender solo del codigo de
 * aplicacion».
 *
 * Aqui pesa mas de lo habitual, y por una razon concreta: una fila de esta tabla
 * en el estado equivocado **emite el token de un quiosco sin que ningun
 * administrador lo haya autorizado**. A partir de ahi, todo lo que entre por esa
 * tablet es un fichaje con valor legal cuyo origen nadie aprobo.
 *
 * Ninguna prueba de este fichero usa un modelo, un repositorio ni un caso de
 * uso: se inserta con el constructor de consultas, que es lo mas parecido a un
 * `psql` que la suite puede ejecutar. Y lo que se espera es un error de
 * PostgreSQL **con el nombre de la restriccion dentro**: sin el nombre, una
 * clave ajena rota o un tipo mal puesto darian la prueba por buena.
 */

uses(RefreshDatabase::class);

/**
 * Instante fijo en lugar de «ahora»: las restricciones no dependen del reloj y
 * una prueba que si dependiera fallaria un dia al ano.
 */
const PAIRING_NOW = '2026-09-07 10:00:00+00';

const PAIRING_EXPIRES_AT = '2026-09-07 10:10:00+00';

/**
 * Centro y un quiosco ya dado de alta, que es lo que hace falta para poder
 * apuntar a un `device_id` valido.
 *
 * @return array{site: int, device: int}
 */
function pairingFixture(): array
{
    $site = DB::table('sites')->insertGetId([
        'name' => 'Centro de pruebas',
        'timezone' => 'Europe/Madrid',
        'settings' => '{}',
        'created_at' => PAIRING_NOW,
    ]);

    $device = DB::table('devices')->insertGetId([
        'uuid' => Str::uuid7()->toString(),
        'site_id' => $site,
        'name' => 'Recepcion',
        'status' => 'active',
        'pending_queue_size' => 0,
        'created_at' => PAIRING_NOW,
        'updated_at' => PAIRING_NOW,
    ]);

    return ['site' => $site, 'device' => $device];
}

/**
 * Inserta una solicitud por SQL directo, sin pasar por la aplicacion.
 *
 * @param  array<string, string|int|null>  $overrides
 */
function insertPairingRequest(array $overrides = []): int
{
    return DB::table('device_pairing_requests')->insertGetId(array_merge([
        'uuid' => Str::uuid7()->toString(),
        'code_hash' => hash('sha256', '483921'),
        'secret_hash' => hash('sha256', 'secreto'),
        'status' => 'pending',
        'app_version' => '1.4.2',
        'device_id' => null,
        'confirmed_by_user_id' => null,
        'expires_at' => PAIRING_EXPIRES_AT,
        'confirmed_at' => null,
        'claimed_at' => null,
        'created_at' => PAIRING_NOW,
        'updated_at' => PAIRING_NOW,
    ], $overrides));
}

/**
 * Comprueba que PostgreSQL rechaza la escritura y que lo hace por la
 * restriccion que se cree.
 *
 * Va dentro de una transaccion propia —que sobre la de `RefreshDatabase` es un
 * `SAVEPOINT`— porque en PostgreSQL un error aborta la transaccion entera: sin
 * ese punto de retorno, la prueba no podria seguir consultando despues.
 *
 * @param  Closure(): void  $write
 */
function expectPairingRejectionBy(string $constraint, Closure $write): void
{
    try {
        DB::transaction(static function () use ($write): void {
            $write();
        });
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain($constraint);

        return;
    }

    Assert::fail(
        'PostgreSQL acepto una fila que «'.$constraint.'» tenia que rechazar. La ultima linea de defensa no esta.',
    );
}

// --- El codigo es unico entre las pendientes --------------------------------

it('rechaza por SQL directo dos solicitudes pendientes con el mismo codigo', function (): void {
    // Sin esto, el `confirm` no sabria cual de las dos esta confirmando el
    // administrador, y la tablet equivocada se llevaria el quiosco.
    pairingFixture();

    insertPairingRequest();

    expectPairingRejectionBy('device_pairing_requests_code_hash_pending_uidx', function (): void {
        insertPairingRequest();
    });
})->group('RF-PD-06', 'RS-03');

it('deja reutilizar el codigo de una solicitud ya consumida', function (): void {
    // La unicidad es PARCIAL a proposito. Con seis digitos, exigirla sobre el
    // historico entero agotaria el espacio de codigos y acabaria negando
    // emparejamientos por una solicitud de hace meses que ya no existe para
    // nadie.
    $fixture = pairingFixture();

    insertPairingRequest([
        'status' => 'claimed',
        'device_id' => $fixture['device'],
        'confirmed_at' => PAIRING_NOW,
        'claimed_at' => PAIRING_NOW,
    ]);

    expect(insertPairingRequest())->toBeGreaterThan(0);
})->group('RF-PD-06');

// --- Confirmada implica dispositivo -----------------------------------------

it('rechaza por SQL directo una solicitud confirmada sin dispositivo', function (): void {
    // **Es la invariante que impide saltarse al administrador.** Con una fila
    // `confirmed` sin `device_id`, el `claim` llegaria a pedir un token para un
    // quiosco que nadie dio de alta.
    pairingFixture();

    expectPairingRejectionBy('device_pairing_requests_chk_confirmed_has_device', function (): void {
        insertPairingRequest([
            'status' => 'confirmed',
            'device_id' => null,
            'confirmed_at' => PAIRING_NOW,
        ]);
    });
})->group('RF-PD-06');

it('rechaza por SQL directo una solicitud pendiente que ya apunta a un dispositivo', function (): void {
    // La otra mitad de la misma invariante: pendiente significa que todavia no
    // hay quiosco. Una fila asi seria un alta a medias que el panel no muestra.
    $fixture = pairingFixture();

    expectPairingRejectionBy('device_pairing_requests_chk_confirmed_has_device', function () use ($fixture): void {
        insertPairingRequest([
            'status' => 'pending',
            'device_id' => $fixture['device'],
        ]);
    });
})->group('RF-PD-06');

// --- Consumida implica instante de recogida ---------------------------------

it('rechaza por SQL directo una solicitud consumida sin instante de recogida', function (): void {
    // `claimed_at` es el rastro de cuando salio el token. Una fila consumida sin
    // el deja el emparejamiento sin momento, que es la mitad de lo que un
    // asiento tiene que poder contrastar.
    $fixture = pairingFixture();

    expectPairingRejectionBy('device_pairing_requests_chk_claimed_at_matches_status', function () use ($fixture): void {
        insertPairingRequest([
            'status' => 'claimed',
            'device_id' => $fixture['device'],
            'confirmed_at' => PAIRING_NOW,
            'claimed_at' => null,
        ]);
    });
})->group('RF-PD-06');

it('rechaza por SQL directo un instante de recogida en una solicitud sin consumir', function (): void {
    $fixture = pairingFixture();

    expectPairingRejectionBy('device_pairing_requests_chk_claimed_at_matches_status', function () use ($fixture): void {
        insertPairingRequest([
            'status' => 'confirmed',
            'device_id' => $fixture['device'],
            'confirmed_at' => PAIRING_NOW,
            'claimed_at' => PAIRING_NOW,
        ]);
    });
})->group('RF-PD-06');

// --- Estados del catalogo y nada mas ----------------------------------------

it('rechaza por SQL directo un estado que el producto no sabe interpretar', function (): void {
    // El enum de PHP no viaja a la base de datos. Una fila con `expired` —que es
    // un estado DERIVADO y nunca se escribe— dejaria al `claim` decidiendo sobre
    // algo que su `match` no contempla.
    //
    // La fila cumple todo lo demas a proposito —dispositivo, `confirmed_at` y
    // `claimed_at` coherentes— para que lo unico que pueda rechazarla sea el
    // catalogo de estados. Con una fila que viola dos restricciones, PostgreSQL
    // informa de la primera que evalua y la prueba pasaria por el motivo
    // equivocado.
    $fixture = pairingFixture();

    expectPairingRejectionBy('device_pairing_requests_chk_status', function () use ($fixture): void {
        insertPairingRequest([
            'status' => 'expired',
            'device_id' => $fixture['device'],
            'confirmed_at' => PAIRING_NOW,
        ]);
    });
})->group('RF-PD-06');

it('rechaza por SQL directo dos solicitudes con el mismo pairing_id', function (): void {
    // El `pairing_id` es lo unico que la tablet reenvia en cada sondeo. Dos filas
    // con el mismo valor harian que la recogida dependiera del orden del indice.
    pairingFixture();

    $uuid = Str::uuid7()->toString();

    insertPairingRequest(['uuid' => $uuid]);

    expectPairingRejectionBy('device_pairing_requests_uuid_unique', function () use ($uuid): void {
        insertPairingRequest(['uuid' => $uuid, 'code_hash' => hash('sha256', '111111')]);
    });
})->group('RF-PD-06');
