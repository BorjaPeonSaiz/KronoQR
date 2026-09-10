<?php

declare(strict_types=1);

use App\Support\Observability\Logging\LogChannelStack;

/*
 * **QUIEN PONE `LOKI_URL` YA HA DICHO QUE QUIERE LOKI** (doc 02 §8.2.1,
 * decision 8 de la ficha 3.1).
 *
 * ## La semantica que este fichero fija, y por que hace falta fijarla
 *
 * La pila del canal `stack` no se declara: se calcula. Parte de `LOG_STACK` y
 * anade o quita `loki` segun `LOKI_URL`:
 *
 *   · `LOKI_URL` con valor  -> `loki` esta en la pila, diga lo que diga `LOG_STACK`.
 *   · `LOKI_URL` vacia      -> `loki` NO esta, aunque `LOG_STACK` lo nombre.
 *
 * La segunda mitad es la que sorprende, y es deliberada: un `.env` copiado de
 * otra instalacion no puede dejar la aplicacion intentando un `POST` por cada
 * linea de log contra un destino que no existe. La primera evita el fallo
 * contrario —el que traia el repositorio—: una variable documentada que ningun
 * codigo leia.
 *
 * Sin estas pruebas la semantica vivia en cuatro lineas al principio de
 * `config/logging.php`, donde no se puede ejecutar sin arrancar el framework, y
 * `configuracion.md` podia decir otra cosa sin que nada fallara.
 */

it('con LOKI_URL puesta, el canal loki entra en la pila', function (): void {
    expect(LogChannelStack::compose('stderr', 'http://loki:3100'))->toBe(['stderr', 'loki']);
})->group('RL-08');

it('con LOKI_URL vacia, el canal loki NO entra aunque LOG_STACK lo nombre', function (string $url): void {
    expect(LogChannelStack::compose('stderr,loki', $url))->toBe(['stderr']);
})->with([
    'vacia' => [''],
    'solo espacios' => ['   '],
])->group('RL-08');

it('no duplica el canal cuando LOG_STACK ya lo nombra y LOKI_URL tiene valor', function (): void {
    // Duplicado significaria cada linea enviada dos veces a Loki.
    expect(LogChannelStack::compose('stderr,loki', 'http://loki:3100'))->toBe(['stderr', 'loki']);
})->group('RL-08');

it('respeta el orden y los espacios de LOG_STACK', function (): void {
    expect(LogChannelStack::compose(' stderr , single ', 'http://loki:3100'))
        ->toBe(['stderr', 'single', 'loki']);
})->group('RL-08');

it('stderr es el canal primario y no se queda fuera por un LOG_STACK vacio', function (): void {
    // Docker conserva stderr, el paquete de diagnostico lo lee y sigue existiendo
    // cuando Loki no esta. Una pila vacia dejaria la instalacion sin log tecnico
    // y sin que nada fallara.
    expect(LogChannelStack::compose('', ''))->toBe(['stderr'])
        ->and(LogChannelStack::compose('  ,  ', 'http://loki:3100'))->toBe(['stderr', 'loki']);
})->group('RL-08');

it('loki a solas sin destino no deja la pila vacia', function (): void {
    // El caso limite del `.env` copiado: `LOG_STACK=loki` y `LOKI_URL` vacia. Sin
    // el respaldo de `stderr` la instalacion se quedaria sin ninguna linea de log.
    expect(LogChannelStack::compose('loki', ''))->toBe(['stderr']);
})->group('RL-08');
