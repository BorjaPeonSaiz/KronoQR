<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\Policy\PinLockoutPolicy;

/*
 * Los tres escalones del bloqueo del PIN, con los numeros exactos (RS-12, doc 02
 * §7.5, tarea 1.12).
 *
 * **Unitaria y sin cache**, que es la razon de que `PinLockoutPolicy` exista
 * como objeto aparte: los umbrales del §7.5 son una tabla de decision, y una
 * tabla de decision se prueba con sus valores limite en milisegundos, no
 * levantando Redis para preguntar si tres fallos son cinco minutos.
 *
 * **Los valores limite del §3.5, uno a uno.** No basta con «tres fallos
 * bloquean»: hay que comprobar el intento 2 —que no bloquea—, el 3 —que si— y el
 * 4 —que sigue en el mismo escalon, no salta al siguiente—. Los errores de un
 * escalonado viven exactamente ahi, en el `>=` que alguien escribio `>`.
 */

/**
 * La politica de serie: los seis numeros del Anexo B del doc 02.
 *
 * Se construyen aqui y no se leen de la configuracion a proposito. Si la prueba
 * leyera `config()`, comprobaria que el codigo hace lo que dice la instalacion,
 * que es una tautologia; escribiendolos, comprueba que **la instalacion de serie
 * cumple el §7.5**, que es lo que importa.
 */
function politicaDeSerie(): PinLockoutPolicy
{
    return new PinLockoutPolicy(
        tier1Attempts: 3,
        tier1Seconds: 300,
        tier2Attempts: 5,
        tier2Seconds: 900,
        tier3Attempts: 10,
        tier3Seconds: 3600,
        resetSeconds: 24 * 3600,
    );
}

it('no bloquea antes del primer escalon', function (int $fallos): void {
    expect(politicaDeSerie()->lockSecondsFor($fallos))->toBe(0);
})->with([0, 1, 2])->group('RS-12', 'RF-AT-11');

it('bloquea cinco minutos en el primer escalon', function (int $fallos): void {
    // 3 y 4: el escalon empieza en 3 y no termina hasta que empieza el
    // siguiente. Un `>` en vez de un `>=` habria dejado pasar el tercer intento
    // sin castigo, que es justo el que RS-12 quiere frenar.
    expect(politicaDeSerie()->lockSecondsFor($fallos))->toBe(300);
})->with([3, 4])->group('RS-12', 'RF-AT-11');

it('bloquea quince minutos en el segundo escalon', function (int $fallos): void {
    expect(politicaDeSerie()->lockSecondsFor($fallos))->toBe(900);
})->with([5, 6, 9])->group('RS-12', 'RF-AT-11');

it('bloquea una hora en el tercer escalon y no sube mas', function (int $fallos): void {
    // Por encima de 10 el bloqueo no crece: es el techo, no una progresion
    // infinita. Un empleado que se equivoca veinte veces sigue teniendo su
    // tarjeta, y castigarle un dia entero seria dejarle sin fichar de verdad.
    expect(politicaDeSerie()->lockSecondsFor($fallos))->toBe(3600);
})->with([10, 11, 50, 1000])->group('RS-12', 'RF-AT-11');

it('recuerda solo los fallos que pueden cambiar el escalon', function (): void {
    // Por encima del ultimo escalon guardar mas marcas no cambia ninguna
    // respuesta, asi que la lista esta acotada. El margen sobre el umbral existe
    // para que la cifra siga siendo util al diagnosticar.
    expect(politicaDeSerie()->trackedFailures())->toBeGreaterThanOrEqual(10);
})->group('RS-12');

it('publica la ventana de olvido en segundos', function (): void {
    // 24 h del Anexo B. Es lo que el adaptador usa como TTL, y por eso tiene que
    // salir de la politica y no de una constante suya: dos sitios donde escribir
    // el mismo numero es un sitio donde acabara escrito otro.
    expect(politicaDeSerie()->resetSeconds())->toBe(86400);
})->group('RS-12');

it('nunca abarata el castigo al aumentar los fallos', function (): void {
    // Configuracion mal escrita a proposito: el tercer escalon mas corto que el
    // segundo. Sin la proteccion, el decimo fallo saldria mas barato que el
    // quinto —el incentivo exactamente contrario— y nada fallaria de forma
    // visible.
    $malConfigurada = new PinLockoutPolicy(
        tier1Attempts: 3,
        tier1Seconds: 300,
        tier2Attempts: 5,
        tier2Seconds: 900,
        tier3Attempts: 10,
        tier3Seconds: 60,
        resetSeconds: 86400,
    );

    expect($malConfigurada->lockSecondsFor(10))->toBe(900)
        ->and($malConfigurada->lockSecondsFor(20))->toBe(900);
})->group('RS-12');

it('rechaza una configuracion imposible', function (int $attempts, int $seconds): void {
    expect(fn () => new PinLockoutPolicy(
        tier1Attempts: $attempts,
        tier1Seconds: $seconds,
        tier2Attempts: 5,
        tier2Seconds: 900,
        tier3Attempts: 10,
        tier3Seconds: 3600,
        resetSeconds: 86400,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'cero intentos' => [0, 300],
    'intentos negativos' => [-1, 300],
    'sin duracion' => [3, 0],
])->group('RS-12');
