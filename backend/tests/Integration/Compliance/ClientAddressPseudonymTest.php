<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Audit\ClientAddressPseudonym;
use Illuminate\Support\Facades\Config;

/*
 * El origen de un intento de autenticacion, seudonimizado (regla dura 21,
 * ADR-020, RGPD).
 *
 * **Por que Integration y no Unit.** La clave sale de la configuracion de la
 * instalacion, y `tests/Unit` no arranca el framework a proposito (doc 02 §9.1).
 * Lo que se comprueba aqui no es aritmetica: es que el valor que acaba en el log
 * y en `audit_log` **no permite reconstruir la direccion** sin la clave del
 * cliente, que es lo que hace que el paquete de diagnostico pueda viajar al
 * fabricante.
 */

it('devuelve siempre lo mismo para la misma direccion', function (): void {
    // Sin estabilidad no sirve para nada: la pregunta que responde es «¿estos
    // 400 fallos vienen del mismo sitio que el acceso correcto de despues?».
    $pseudonym = new ClientAddressPseudonym;

    expect($pseudonym->of('10.0.4.17'))->toBe($pseudonym->of('10.0.4.17'));
})->group('RS-12');

it('da valores distintos a direcciones distintas y no contiene la direccion', function (): void {
    $pseudonym = new ClientAddressPseudonym;

    $uno = $pseudonym->of('10.0.4.17');
    $otro = $pseudonym->of('10.0.4.18');

    expect($uno)->not->toBe($otro)
        ->and($uno)->not->toContain('10.0.4.17')
        // 64 bits en hexadecimal: bastan para agrupar los origenes de una
        // instalacion y caben en una linea de log.
        ->and($uno)->toMatch('/^[0-9a-f]{16}$/');
})->group('RS-12');

it('cambia por completo si cambia la clave de la instalacion', function (): void {
    // Es lo que impide que el fabricante —que no tiene `APP_KEY`— compare los
    // seudonimos de dos clientes, y lo que hace inutil una tabla precalculada
    // del espacio IPv4.
    $pseudonym = new ClientAddressPseudonym;

    $conLaClave = $pseudonym->of('10.0.4.17');

    Config::set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    expect($pseudonym->of('10.0.4.17'))->not->toBe($conLaClave);
})->group('RS-12');

it('no devuelve nada si no hay direccion o no hay clave', function (): void {
    // Fallar cerrado: devolver la direccion en claro «porque no se pudo cifrar»
    // seria exactamente el agujero que esta clase existe para evitar.
    $pseudonym = new ClientAddressPseudonym;

    expect($pseudonym->of(null))->toBeNull()
        ->and($pseudonym->of(''))->toBeNull();

    Config::set('app.key', '');

    expect($pseudonym->of('10.0.4.17'))->toBeNull();
})->group('RS-12');
