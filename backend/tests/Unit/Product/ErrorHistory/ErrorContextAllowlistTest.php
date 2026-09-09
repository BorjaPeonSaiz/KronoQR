<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\ErrorContextAllowlist;

/*
 * `context` es una lista de PERMITIDOS, no de exclusiones (RF-PD-15, RL-19,
 * regla dura 21, decision 5 de la ficha 5.12).
 *
 * Quien rellena el contexto es un cliente. El dia que alguien anada
 * `context: { employee_name: … }` en el panel «para depurar», con exclusiones
 * ese nombre empezaria a viajar hacia el fabricante dentro del paquete de
 * diagnostico y nadie se enteraria.
 */

it('descarta cualquier clave que no este declarada', function (): void {
    $limpio = ErrorContextAllowlist::apply([
        'route' => '/api/v1/scan',
        'employee_name' => 'Ana Ruiz',
        'email' => 'ana.ruiz@hotel.es',
        'national_id' => '12345678Z',
        'token' => 'secreto',
    ]);

    expect($limpio)->toBe(['route' => '/api/v1/scan']);
})->group('RF-PD-15', 'RL-19');

it('descarta las estructuras anidadas', function (): void {
    // Un valor que es a su vez un mapa no entra: la lista no puede afirmar nada
    // sobre sus claves, y permitir uno colaria el objeto entero que llevara
    // dentro. Es exactamente como se filtra un `client_meta`.
    expect(ErrorContextAllowlist::apply([
        'reason' => ['nombre' => 'Ana Ruiz'],
        'cause' => ['a', 'b'],
        'http_status' => 500,
    ]))->toBe(['http_status' => 500]);
})->group('RF-PD-15', 'RL-19');

it('sanea tambien los valores permitidos', function (): void {
    // `reason` es texto libre escrito por quien programo el cliente: puede
    // llevar cualquier cosa dentro, exactamente igual que el mensaje.
    $limpio = ErrorContextAllowlist::apply(['reason' => 'fallo al avisar a ana.ruiz@hotel.es']);

    expect($limpio['reason'] ?? '')->toContain('[email]')
        ->and($limpio['reason'] ?? '')->not->toContain('ana.ruiz@hotel.es');
})->group('RF-PD-15', 'RL-19');

it('no admite las tres identidades por el contexto: tienen columna propia', function (): void {
    // Si estuvieran en la lista habria dos sitios donde puede vivir un
    // `employee_uuid`, y solo uno de los dos tiene el tipo `uuid` detras.
    expect(ErrorContextAllowlist::allows('trace_id'))->toBeFalse()
        ->and(ErrorContextAllowlist::allows('employee_uuid'))->toBeFalse()
        ->and(ErrorContextAllowlist::allows('device_id'))->toBeFalse();
})->group('RF-PD-15');

it('conserva los escalares tal cual y respeta el orden declarado', function (): void {
    $limpio = ErrorContextAllowlist::apply([
        'reason' => 'timeout',
        'http_status' => 500,
        'method' => 'POST',
        'attempts' => 3,
        'durable' => false,
    ]);

    expect(array_keys($limpio))->toBe(['method', 'attempts', 'reason', 'http_status', 'durable'])
        ->and($limpio['http_status'])->toBe(500)
        ->and($limpio['attempts'])->toBe(3)
        ->and($limpio['durable'])->toBeFalse();
})->group('RF-PD-15');

it('devuelve un mapa vacio cuando no hay nada permitido', function (): void {
    // Y no dieciseis claves con `null`: el contrato declara `context` con
    // valores escalares y sin nulos.
    expect(ErrorContextAllowlist::apply(['lo_que_sea' => 'x']))->toBe([]);
})->group('RF-PD-15');

it('declara exactamente las claves que el servidor y los clientes emiten', function (): void {
    /*
     * La lista literal, valor a valor: anadir o quitar una clave tiene que ser
     * un cambio visible que alguien revise, no un efecto colateral.
     *
     * Que estas sean **las de verdad** —y no las cuatro inventadas de la primera
     * version, que dejaban el contexto vacio en todos los errores de cliente— lo
     * comprueba `ClientErrorContextKeysTest` contra los ficheros de los propios
     * clientes. Aqui solo se fija la lista.
     */
    expect(ErrorContextAllowlist::keys())->toBe([
        // Servidor (`Product\Infrastructure\Capture`).
        'route', 'method',
        'job', 'queue', 'attempts',
        'command',
        // Comunes a los tres clientes.
        'cause', 'reason', 'error_type', 'http_status',
        // Panel y portal (`packages/web-kit/src/clientErrors.ts`).
        'component', 'hook', 'source', 'line',
        // Quiosco (`frontend-kiosk/src`).
        'scope', 'audio_state', 'silence_ms', 'skew_seconds', 'durable',
        'entries', 'items', 'missing', 'purged',
    ]);
})->group('RF-PD-15');

it('no guarda `message` en el contexto: asciende a la columna', function (): void {
    /*
     * Los tres reporters mandan el texto del error con esa clave. El servidor lo
     * eleva a `error_events.message` —es lo que hace que la fila del panel diga
     * algo, y lo que hace que dos errores distintos tengan huellas distintas— y
     * lo retira del contexto: guardarlo dos veces lo dejaria con dos longitudes
     * maximas distintas (1000 y 200) y duplicado en el paquete de diagnostico.
     */
    expect(ErrorContextAllowlist::allows(ErrorContextAllowlist::MESSAGE_KEY))->toBeFalse()
        ->and(ErrorContextAllowlist::apply(['message' => 'TypeError: x', 'component' => 'Vista']))
        ->toBe(['component' => 'Vista'])
        // Y la extraccion, que es lo que usan las dos puertas de entrada.
        ->and(ErrorContextAllowlist::messageIn(['message' => 'TypeError: x']))->toBe('TypeError: x')
        ->and(ErrorContextAllowlist::messageIn(['message' => '   ']))->toBeNull()
        ->and(ErrorContextAllowlist::messageIn(['component' => 'Vista']))->toBeNull();
})->group('RF-PD-15');
