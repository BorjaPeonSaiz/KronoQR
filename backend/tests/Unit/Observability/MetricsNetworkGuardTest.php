<?php

declare(strict_types=1);

use App\Http\Middleware\RestrictToMetricsNetwork;

/*
 * La guarda de red de `GET /metrics` (RS-09, doc 01 Anexo B, tarea 3.1).
 *
 * **Por que unitaria.** Lo que aqui se comprueba es aritmetica de bits sobre
 * direcciones, con casos de borde que no tienen nada que ver con HTTP: el bit
 * suelto de un `/23`, la mezcla de IPv4 con IPv6, un prefijo que no es un
 * numero. Probarlo a traves de una peticion obligaria a levantar el framework
 * para comparar dos cadenas, y cada caso de borde costaria una peticion.
 *
 * Que el `403` llega de verdad al cliente es otra afirmacion y esta en
 * `tests/Feature/Metrics/MetricsEndpointTest.php`.
 *
 * **La regla de esta clase: ante la duda, NO.** Toda entrada que no se entienda
 * —direccion ilegible, prefijo raro, familia distinta— tiene que cerrar. Un
 * fallo abierto aqui publica la operacion del hotel a quien pase por delante.
 */

it('acepta una direccion dentro del rango', function (string $address, string $range): void {
    expect(RestrictToMetricsNetwork::inRange($address, $range))->toBeTrue();
})->with([
    'la propia direccion, con /32' => ['172.29.0.20', '172.29.0.20/32'],
    'sin prefijo se trata como direccion suelta' => ['172.29.0.20', '172.29.0.20'],
    'primer valor de la red' => ['10.91.0.0', '10.91.0.0/24'],
    'ultimo valor de la red' => ['10.91.0.255', '10.91.0.0/24'],
    'prefijo que no cae en byte entero' => ['10.91.1.7', '10.91.0.0/23'],
    'toda la internet' => ['203.0.113.7', '0.0.0.0/0'],
    'IPv6 dentro del rango' => ['2001:db8::1', '2001:db8::/32'],
    'bucle local IPv6' => ['::1', '::1/128'],
])->group('RS-09');

it('rechaza una direccion fuera del rango', function (string $address, string $range): void {
    expect(RestrictToMetricsNetwork::inRange($address, $range))->toBeFalse();
})->with([
    'vecina de la autorizada' => ['172.29.0.21', '172.29.0.20/32'],
    'justo debajo de la red' => ['10.90.255.255', '10.91.0.0/24'],
    'justo encima de la red' => ['10.91.1.0', '10.91.0.0/24'],
    // El caso que un `/24` mal escrito como `/23` dejaria pasar sin que nadie lo
    // note hasta que alguien lea las series desde la red de invitados.
    'fuera del prefijo parcial' => ['10.91.2.7', '10.91.0.0/23'],
    'IPv6 fuera del rango' => ['2001:db9::1', '2001:db8::/32'],
])->group('RS-09');

it('no abre ante una entrada que no entiende', function (string $address, string $range): void {
    // Fallar cerrado es lo unico admisible en una guarda de red: una entrada
    // rara es un error de configuracion, y un error de configuracion no puede
    // publicar las metricas.
    expect(RestrictToMetricsNetwork::inRange($address, $range))->toBeFalse();
})->with([
    'direccion ilegible' => ['no-es-una-ip', '10.91.0.0/24'],
    'rango ilegible' => ['10.91.0.5', 'tambien-mal/24'],
    'prefijo que no es un numero' => ['10.91.0.5', '10.91.0.0/veinticuatro'],
    'prefijo mayor que la familia' => ['10.91.0.5', '10.91.0.0/33'],
    'IPv4 contra un rango IPv6' => ['10.91.0.5', '2001:db8::/32'],
    'IPv6 contra un rango IPv4' => ['2001:db8::1', '10.91.0.0/24'],
    'cadena vacia' => ['', '10.91.0.0/24'],
])->group('RS-09');

it('no deja pasar a nadie sin direccion de origen', function (): void {
    // Una peticion sin `REMOTE_ADDR` no existe detras de este borde; si
    // apareciera, es que algo se ha saltado a Nginx.
    expect(RestrictToMetricsNetwork::allows(null))
        ->toBeFalse()
        ->and(RestrictToMetricsNetwork::allows(''))
        ->toBeFalse();
})->group('RS-09');
