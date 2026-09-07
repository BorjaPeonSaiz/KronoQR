<?php

declare(strict_types=1);

use App\Modules\Kiosk\Http\Response\PairingRejectedResponse;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **Los tres rechazos de `POST /api/v1/kiosk/pair/claim` son indistinguibles
 * desde fuera**, en respuesta y en tiempo (RS-03, regla dura 17, RF-PD-06).
 *
 * Es la ruta publica por la que sale el token de un quiosco. Si «no existe», «no
 * es tuya» y «ya no vale» se pudieran separar, cualquiera con un `pairing_id`
 * inventado tendria un comprobador de solicitudes vivas y, peor, un oraculo con
 * el que afinar la busqueda de un secreto.
 *
 * **Lo que se mide, y por que hacen falta las tres cosas**, igual que en
 * `ConstantTimeRejectionTest` para el escaneo:
 *
 *   1. **El cuerpo.** Los tres devuelven el mismo JSON **byte a byte**, y el
 *      esquema `PairingRejected` no tiene donde alojar la causa.
 *   2. **El trabajo.** Los tres ejecutan el mismo numero de consultas. Es la
 *      mitad estructural del control y es deterministica: si mañana alguien
 *      añade un `return` temprano «para ahorrar una consulta cuando no existe»,
 *      esta prueba falla aunque el reloj de la CI no de para distinguirlo.
 *   3. **El reloj.** Las tres medianas caen en la misma banda. Es lo que exige el
 *      requisito, y sin el suelo de `security.rejection_floor_ms` seria
 *      intermitente. Es el MISMO umbral y el mismo objeto que usa la
 *      resolucion de credenciales del fichaje: los dos caminos tienen la misma
 *      obligacion y ya no pueden divergir.
 *
 * **Lo que este fichero NO mide, a proposito**: que `pending` y `paired` tarden
 * lo mismo que un rechazo. Prometerlo seria mentir —confirmar un emparejamiento
 * emite un token y escribe en `audit_log`— y el contrato tampoco lo promete. La
 * garantia es **entre los tres rechazos**, que es donde estaria la fuga.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    app()->instance(Clock::class, FixedClock::at('2026-09-07 10:00:00'));
});

/**
 * Una solicitud recien creada, ya tipada.
 *
 * `->json()` devuelve `mixed` y PHPStan 9 no deja acceder a un `mixed` por
 * indice: la forma se escribe una vez aqui en lugar de anotarse en cada caso.
 *
 * @return array{pairing_id: string, pairing_secret: string, code: string}
 */
function solicitudDePrueba(): array
{
    /** @var array{pairing_id: string, pairing_secret: string, code: string} $ticket */
    $ticket = Api::guest()->post('/api/v1/kiosk/pair', ['app_version' => '1.4.2'])->json();

    return $ticket;
}

/**
 * Las tres causas de rechazo, cada una con sus credenciales.
 *
 * @return array<string, array{pairing_id: string, pairing_secret: string}>
 */
function tresRechazos(): array
{
    WorkforceFixtures::site();

    // 1. Secreto que no coincide: la solicitud EXISTE y esta pendiente.
    $viva = solicitudDePrueba();

    // 2. Solicitud ya consumida: existe, se confirmo y su token ya se entrego.
    $consumida = solicitudDePrueba();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)->post('/api/v1/kiosk/pair/confirm', [
        'code' => $consumida['code'],
        'name' => 'Recepcion',
    ])->assertOk();

    Api::guest()->post('/api/v1/kiosk/pair/claim', [
        'pairing_id' => $consumida['pairing_id'],
        'pairing_secret' => $consumida['pairing_secret'],
    ])->assertOk();

    return [
        // 3. Y el que no tiene fila detras: tiene que costar lo mismo que los
        //    otros dos, que es justo lo que exige el paso «hashear siempre».
        'pairing_id desconocido' => [
            'pairing_id' => '0199aaaa-bbbb-7ccc-8ddd-eeeeffff0000',
            'pairing_secret' => str_repeat('z', 43),
        ],
        'secreto incorrecto' => [
            'pairing_id' => $viva['pairing_id'],
            'pairing_secret' => str_repeat('z', 43),
        ],
        'solicitud ya consumida' => [
            'pairing_id' => $consumida['pairing_id'],
            'pairing_secret' => $consumida['pairing_secret'],
        ],
    ];
}

/**
 * @param  array{pairing_id: string, pairing_secret: string}  $credenciales
 * @return TestResponse<Response>
 */
function sondeoRechazado(array $credenciales): TestResponse
{
    return Api::guest()->post('/api/v1/kiosk/pair/claim', $credenciales);
}

it('devuelve exactamente el mismo cuerpo en los tres rechazos', function (): void {
    // Byte a byte. Un campo de mas —aunque fuera el eco del `pairing_id` que
    // envio el cliente— seria el sitio donde alguien acabaria escribiendo la
    // causa una tarde de diagnostico.
    $cuerpos = [];

    foreach (tresRechazos() as $caso => $credenciales) {
        $respuesta = sondeoRechazado($credenciales);

        $respuesta->assertStatus(422)->assertValidResponse();

        $cuerpos[$caso] = (string) $respuesta->getContent();
    }

    expect(array_unique(array_values($cuerpos)))
        ->toHaveCount(1, 'Los rechazos NO son identicos: '.json_encode($cuerpos));

    // Y es exactamente el cuerpo unico que declara el contrato.
    expect(json_decode((string) reset($cuerpos), true))
        ->toBe(PairingRejectedResponse::body());
})->group('RS-03', 'RF-PD-06', 'RQ-06');

it('ejecuta el mismo numero de consultas en los tres rechazos', function (): void {
    // La mitad estructural del tiempo constante, y la que no depende del reloj de
    // la maquina. Un `if ($fila === null) return rechazo;` antes del
    // `hash_equals` ahorraria trabajo en el caso «no existe» y lo haria medible.
    $casos = tresRechazos();
    $consultas = [];

    foreach ($casos as $caso => $credenciales) {
        DB::flushQueryLog();
        DB::enableQueryLog();

        sondeoRechazado($credenciales);

        $consultas[$caso] = count(DB::getRawQueryLog());
        DB::disableQueryLog();
    }

    expect(array_unique(array_values($consultas)))
        ->toHaveCount(1, 'Los rechazos no cuestan lo mismo: '.json_encode($consultas));
})->group('RS-03', 'RF-PD-06');

it('consume el mismo tiempo en los tres rechazos', function (): void {
    // La comprobacion que exige el requisito. El suelo configurado —25 ms de
    // serie— absorbe la varianza de PostgreSQL, que es lo que sin el hace que un
    // acierto de indice y un fallo de indice se distingan con suficientes
    // muestras.
    $casos = tresRechazos();
    $suelo = Config::integer('security.rejection_floor_ms');

    expect($suelo)->toBeGreaterThan(0, 'La suite tiene el suelo de RS-03 del emparejamiento desactivado.');

    /** @var array<string, list<float>> $muestras */
    $muestras = array_fill_keys(array_keys($casos), []);

    // Intercaladas y no en bloques: si la maquina se ralentiza a mitad de la
    // prueba —otra prueba, el recolector, la CI— el sesgo cae por igual sobre los
    // tres casos en vez de sobre el que se estuviera midiendo.
    for ($ronda = 0; $ronda < 8; $ronda++) {
        foreach ($casos as $caso => $credenciales) {
            $inicio = hrtime(true);
            sondeoRechazado($credenciales);
            $muestras[$caso][] = (hrtime(true) - $inicio) / 1_000_000;
        }
    }

    /** @var non-empty-array<string, float> $medianas */
    $medianas = array_map(medianaDeMuestras(...), $muestras);

    foreach ($medianas as $caso => $mediana) {
        expect($mediana)->toBeGreaterThanOrEqual(
            $suelo * 0.9,
            "El caso «{$caso}» ha tardado {$mediana} ms, por debajo del suelo de {$suelo} ms.",
        );
    }

    // Banda de 15 ms entre la mediana mas rapida y la mas lenta. No es cero
    // —esto corre en un contenedor compartido con PostgreSQL y cada muestra es
    // una peticion HTTP entera, no una llamada a un adaptador— pero es un orden
    // de magnitud menor que lo que hace falta para distinguir dos casos a traves
    // de la red con un numero razonable de intentos.
    $dispersion = max($medianas) - min($medianas);

    expect($dispersion)->toBeLessThan(
        15.0,
        'Los rechazos se distinguen por tiempo: '.json_encode(array_map(
            static fn (float $ms): float => round($ms, 2),
            $medianas,
        )),
    );
})->group('RS-03', 'RF-PD-06');

it('no distingue las tres causas del codigo tampoco en el confirm', function (): void {
    // La ruta del panel esta autenticada, asi que aqui no se protege un secreto:
    // lo que hace que las tres compartan respuesta es que **tienen la misma
    // accion siguiente**, que es pedirle otro codigo a la tablet. Sin tiempo
    // constante —a un `admin` no hay nada que ocultarle— pero con un solo cuerpo.
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    // Caducado.
    $caducado = solicitudDePrueba();

    // Ya usado.
    $usado = solicitudDePrueba();
    Api::as($token)->post('/api/v1/kiosk/pair/confirm', [
        'code' => $usado['code'],
        'name' => 'Recepcion',
    ])->assertOk();

    app()->instance(Clock::class, FixedClock::at('2026-09-07 10:10:01'));

    $cuerpos = [];

    foreach ([
        'inexistente' => '000000',
        'caducado' => $caducado['code'],
        'ya usado' => $usado['code'],
    ] as $caso => $codigo) {
        $respuesta = Api::as($token)->post('/api/v1/kiosk/pair/confirm', [
            'code' => $codigo,
            'name' => 'Cocina',
        ]);

        $respuesta->assertStatus(422)->assertValidResponse();

        $cuerpos[$caso] = (string) $respuesta->getContent();
    }

    expect(array_unique(array_values($cuerpos)))
        ->toHaveCount(1, 'El confirm distingue las causas del codigo: '.json_encode($cuerpos));
})->group('RS-03', 'RF-PD-06');

/**
 * Mediana y no media: una sola muestra lenta —el planificador del contenedor, un
 * `checkpoint` de PostgreSQL— desplazaria la media y haria fallar la prueba sin
 * que hubiera ningun canal de tiempo.
 *
 * @param  list<float>  $valores
 */
function medianaDeMuestras(array $valores): float
{
    sort($valores);

    $mitad = count($valores) >> 1;

    return count($valores) % 2 === 1
        ? $valores[$mitad]
        : ($valores[$mitad - 1] + $valores[$mitad]) / 2;
}
