<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\ErrorFingerprint;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;

/*
 * La huella agrupa lo que es el mismo fallo y separa lo que no (RF-PD-15,
 * tarea 5.12, decision 4).
 *
 * Son los dos fallos opuestos y los dos caros:
 *
 *   - **Agrupar de menos**: el fallo de un endpoint durante un cambio de turno
 *     deja cuatrocientas filas -una por `scan_id`- en lugar de una con
 *     `occurrences: 400`, y el error importante queda enterrado. Es el problema
 *     que la tabla existe para evitar (doc 02 §8.2.1).
 *   - **Agrupar de mas**: dos fallos distintos comparten fila y el segundo no se
 *     ve nunca. Peor, porque no deja rastro de que existe.
 */

it('agrupa el mismo fallo aunque cambien el uuid, el numero y la ruta del mensaje', function (): void {
    $primera = ErrorFingerprint::forServer(
        ErrorSource::Api,
        'RuntimeException',
        'app/Modules/Attendance/Application/UseCase/RecordScan.php',
        88,
        'No se pudo procesar el escaneo 0199f0aa-1111-7000-8000-0123456789ab: 3 intentos desde /var/www/html/storage/app/queue',
    );

    $segunda = ErrorFingerprint::forServer(
        ErrorSource::Api,
        'RuntimeException',
        'app/Modules/Attendance/Application/UseCase/RecordScan.php',
        88,
        'No se pudo procesar el escaneo 0199ffff-2222-7000-8000-fedcba987654: 17 intentos desde /srv/kronoqr/var/spool',
    );

    expect($segunda->value)->toBe($primera->value)
        ->and($primera->value)->toHaveLength(ErrorFingerprint::LENGTH)
        ->and($primera->value)->toMatch('/^[0-9a-f]{64}$/');
})->group('RF-PD-15');

it('separa dos clases de excepcion distintas con el mismo mensaje', function (): void {
    $conexion = ErrorFingerprint::forServer(
        ErrorSource::Api,
        'PDOException',
        'app/Foo.php',
        10,
        'no se pudo completar la operacion',
    );

    $permisos = ErrorFingerprint::forServer(
        ErrorSource::Api,
        'RuntimeException',
        'app/Foo.php',
        10,
        'no se pudo completar la operacion',
    );

    expect($permisos->value)->not->toBe($conexion->value);
})->group('RF-PD-15');

it('separa el mismo fallo lanzado desde dos puntos distintos', function (): void {
    $arriba = ErrorFingerprint::forServer(ErrorSource::Worker, 'RuntimeException', 'app/Foo.php', 10, 'nada');
    $abajo = ErrorFingerprint::forServer(ErrorSource::Worker, 'RuntimeException', 'app/Foo.php', 240, 'nada');
    $otroFichero = ErrorFingerprint::forServer(ErrorSource::Worker, 'RuntimeException', 'app/Bar.php', 10, 'nada');

    expect($abajo->value)->not->toBe($arriba->value)
        ->and($otroFichero->value)->not->toBe($arriba->value);
})->group('RF-PD-15');

it('separa el mismo mensaje segun el origen', function (): void {
    // El mismo fallo visto desde la API y desde la cola son dos problemas: uno
    // lo esta viendo alguien y el otro no.
    $api = ErrorFingerprint::forServer(ErrorSource::Api, 'RuntimeException', 'app/Foo.php', 10, 'nada');
    $cola = ErrorFingerprint::forServer(ErrorSource::Worker, 'RuntimeException', 'app/Foo.php', 10, 'nada');

    expect($cola->value)->not->toBe($api->value);
})->group('RF-PD-15');

it('agrupa los errores de cliente por codigo, sin fichero ni linea', function (): void {
    // El `stack` del navegador nunca viaja, asi que el codigo del catalogo hace
    // el trabajo que en el servidor hacen la clase y la linea.
    $primera = ErrorFingerprint::forClient(ErrorSource::Kiosk, 'kiosk.camera.stream_lost', 'perdida a los 3 s');
    $segunda = ErrorFingerprint::forClient(ErrorSource::Kiosk, 'kiosk.camera.stream_lost', 'perdida a los 41 s');
    $otroCodigo = ErrorFingerprint::forClient(ErrorSource::Kiosk, 'kiosk.scanner.start_failed', 'perdida a los 3 s');
    $otroCliente = ErrorFingerprint::forClient(ErrorSource::Portal, 'kiosk.camera.stream_lost', 'perdida a los 3 s');

    expect($segunda->value)->toBe($primera->value)
        ->and($otroCodigo->value)->not->toBe($primera->value)
        ->and($otroCliente->value)->not->toBe($primera->value);
})->group('RF-PD-15');

it('no confunde dos huellas por como se concatenan sus partes', function (): void {
    // Sin separador, `origen=api` + `clase=Foo` seria indistinguible de
    // `origen=apiF` + `clase=oo`.
    $una = ErrorFingerprint::forServer(ErrorSource::Api, 'Foo', null, null, 'x');
    $otra = ErrorFingerprint::forServer(ErrorSource::Api, 'Foo|', null, null, 'x');

    expect($otra->value)->not->toBe($una->value);
})->group('RF-PD-15');
