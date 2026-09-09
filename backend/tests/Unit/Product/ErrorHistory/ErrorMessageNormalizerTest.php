<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\ErrorMessageNormalizer;

/*
 * La normalizacion quita lo que cambia entre repeticiones y **no toca ni una
 * palabra** (RF-PD-15, decision 4 de la ficha 5.12).
 *
 * Es la pieza delicada de la tarea: si deja pasar un identificador, la
 * agrupacion no agrupa; si normaliza de mas, junta errores distintos.
 */

it('sustituye cada clase de identificador por su marcador', function (string $texto, string $esperado): void {
    expect(ErrorMessageNormalizer::normalize($texto))->toBe($esperado);
})->with([
    'uuid' => ['fallo 0199f0aa-1111-7000-8000-0123456789ab', 'fallo <uuid>'],
    'uuid en mayusculas' => ['fallo 0199F0AA-1111-7000-8000-0123456789AB', 'fallo <uuid>'],
    'instante ISO' => ['desde 2026-09-09T08:12:03.512Z', 'desde <time>'],
    'fecha suelta' => ['del dia 2026-09-09', 'del dia <time>'],
    'hora suelta' => ['a las 22:15', 'a las <time>'],
    'ip' => ['conexion desde 10.0.0.42', 'conexion desde <ip>'],
    'correo' => ['destinatario ana.ruiz@hotel.es', 'destinatario <email>'],
    'ruta absoluta' => ['leyendo /var/www/html/storage/app', 'leyendo <path>'],
    'hexadecimal largo' => ['huella a1b2c3d4e5f60718', 'huella <hex>'],
    'numero' => ['reintento 47', 'reintento <n>'],
])->group('RF-PD-15');

it('no toca las palabras del mensaje', function (): void {
    // Si normalizara de mas, dos errores distintos acabarian en la misma fila y
    // el segundo no se veria nunca.
    expect(ErrorMessageNormalizer::normalize('conexion rechazada por el servidor'))
        ->toBe('conexion rechazada por el servidor');
})->group('RF-PD-15');

it('no destroza una llamada estatica de PHP confundiendola con una IPv6', function (): void {
    // Con la forma laxa de IPv6 -que admite grupos vacios-, `Handler::method`
    // casaba y toda llamada estatica de un mensaje se convertia en `<ip>`,
    // juntando en una sola huella errores de metodos distintos.
    expect(ErrorMessageNormalizer::normalize('RecordScan::handle fallo'))
        ->toBe('RecordScan::handle fallo');
})->group('RF-PD-15');

it('colapsa los espacios y los saltos de linea', function (): void {
    // Una traza con veinte lineas dentro del mensaje produciria una huella
    // distinta por cada indentacion del volcado.
    expect(ErrorMessageNormalizer::normalize("linea uno\n   linea dos\t\tfin"))
        ->toBe('linea uno linea dos fin');
})->group('RF-PD-15');

it('deja el mismo resultado para dos apariciones que solo se diferencian en datos variables', function (): void {
    $primera = ErrorMessageNormalizer::normalize(
        'SQLSTATE[08006] conexion con 10.0.0.42:5432 perdida el 2026-09-09T08:12:03Z tras 3 intentos',
    );

    $segunda = ErrorMessageNormalizer::normalize(
        'SQLSTATE[08006] conexion con 192.168.1.7:5432 perdida el 2026-11-30T21:44:19Z tras 12 intentos',
    );

    expect($segunda)->toBe($primera);
})->group('RF-PD-15');
