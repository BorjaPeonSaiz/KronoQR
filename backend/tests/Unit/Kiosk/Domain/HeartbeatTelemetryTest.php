<?php

declare(strict_types=1);

use App\Modules\Kiosk\Domain\ValueObject\HeartbeatTelemetry;

/*
 * Lo que un quiosco declara de si mismo en cada latido (**RF-PA-07**, tarea
 * 3.3, decision 1).
 *
 * ## Es la tercera linea, y es la unica que protege a quien no pasa por el borde
 *
 * El `FormRequest` acota 0..100 en la peticion HTTP y la columna lleva su
 * `CHECK`. Este objeto lo comprueba otra vez porque lo construyen tambien un
 * comando de consola y las propias pruebas, y por ahi no pasa ninguna de las
 * otras dos guardas. Un `-1` colado aqui saldria a la metrica
 * `kiosk_battery_level` y a la columna del panel sin que nada lo parase.
 *
 * ## Los tres `null` son estado normal, no error
 *
 * `batteryLevel` y `batteryCharging` son `null` en todo navegador que no sea
 * Chrome en Android, que es casi cualquier tablet que no sea la del producto; y
 * `oldestPendingAt` es `null` con la cola vacia. **Una tablet que no informa no
 * es una tablet averiada**: si el constructor los rechazara, la flota entera
 * dejaria de poder latir.
 */

it('acepta un quiosco con la cola vacia', function (): void {
    // Cero es el caso normal, no un valor de relleno: la inmensa mayoria de los
    // latidos de una instalacion sana llegan con la cola a cero.
    $telemetry = new HeartbeatTelemetry(appVersion: '2.2.0', pendingQueueSize: 0);

    expect($telemetry->pendingQueueSize)->toBe(0)
        ->and($telemetry->oldestPendingAt)->toBeNull();
})->group('RF-PA-07');

it('rechaza una cola pendiente negativa', function (): void {
    // Un numero negativo de fichajes sin enviar no significa nada, y restaria en
    // el recuento de la flota que lee el panel.
    expect(fn (): HeartbeatTelemetry => new HeartbeatTelemetry(appVersion: '2.2.0', pendingQueueSize: -1))
        ->toThrow(InvalidArgumentException::class);
})->group('RF-PA-07');

it('acepta los dos extremos del nivel de bateria', function (int $level): void {
    // Cero por ciento es una tablet que se apaga ahora mismo —justo lo que la
    // columna existe para enseñar— y cien es una recien desenchufada.
    $telemetry = new HeartbeatTelemetry(
        appVersion: '2.2.0',
        pendingQueueSize: 0,
        batteryLevel: $level,
        batteryCharging: false,
    );

    expect($telemetry->batteryLevel)->toBe($level)
        ->and($telemetry->batteryCharging)->toBeFalse();
})->with([
    'a punto de apagarse' => [0],
    'recien desenchufada' => [100],
])->group('RF-PA-07');

it('rechaza un nivel de bateria que no es un porcentaje', function (int $level): void {
    expect(fn (): HeartbeatTelemetry => new HeartbeatTelemetry(
        appVersion: '2.2.0',
        pendingQueueSize: 0,
        batteryLevel: $level,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'uno por debajo del minimo' => [-1],
    'uno por encima del maximo' => [101],
])->group('RF-PA-07');

it('acepta que el navegador no informe de la bateria', function (): void {
    // La Battery Status API no existe fuera de Chrome en Android. Si esto
    // lanzara, todas las tablets que no son la del producto se quedarian sin
    // poder latir, y el panel de salud no veria ninguna.
    $telemetry = new HeartbeatTelemetry(appVersion: '2.2.0', pendingQueueSize: 4);

    expect($telemetry->batteryLevel)->toBeNull()
        ->and($telemetry->batteryCharging)->toBeNull();
})->group('RF-PA-07');

it('conserva el instante del fichaje mas antiguo de la cola', function (): void {
    // Es lo que convierte «37 pendientes» en «el mas antiguo es de hace tres
    // horas», que es la diferencia entre una sincronizacion en curso y un quiosco
    // que lleva media jornada incomunicado.
    $oldest = new DateTimeImmutable('2026-09-16 05:12:44', new DateTimeZone('UTC'));

    $telemetry = new HeartbeatTelemetry(
        appVersion: '2.2.0',
        pendingQueueSize: 37,
        oldestPendingAt: $oldest,
    );

    expect($telemetry->oldestPendingAt?->format('Y-m-d H:i:s'))->toBe('2026-09-16 05:12:44')
        ->and($telemetry->pendingQueueSize)->toBe(37);
})->group('RF-PA-07');
