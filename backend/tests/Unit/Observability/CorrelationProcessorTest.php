<?php

declare(strict_types=1);

use App\Support\Observability\Logging\CorrelationProcessor;
use Monolog\Level;
use Monolog\LogRecord;

/*
 * El processor que pone `trace_id`, `scan_id`, `device_id` y `employee_uuid` en
 * **todas** las lineas del log tecnico (doc 02 §8.1, doc 01 §9.4, decision 9 de
 * la ficha 3.1).
 *
 * ## Por que unitario y con el contexto inyectado
 *
 * Lo que hay que fijar aqui es el **orden de precedencia** de las cuatro fuentes
 * del `trace_id` y que un identificador a ceros no cuenta como identificador.
 * Eso son reglas, no integracion: el `Context` de Laravel entra por una clausura
 * y no hace falta arrancar el framework.
 *
 * Que el processor este de verdad enganchado a los canales —y que el `trace_id`
 * de una peticion aparezca en el log y en `error_events`— lo prueba
 * `tests/Feature/Observability`.
 */

/**
 * @param  array<string, mixed>  $context
 * @param  array<string, mixed>  $ambient
 * @return array<string, mixed>
 */
function procesado(array $context = [], array $ambient = []): array
{
    $processor = new CorrelationProcessor(static fn (): array => $ambient);

    $record = $processor(new LogRecord(
        new DateTimeImmutable('2026-09-10 06:00:00'),
        'testing',
        Level::Info,
        'attendance.scan_processed',
        $context,
    ));

    return $record->extra;
}

const TRAZA_DEL_APUNTE = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

const TRAZA_ENTRANTE = '0af7651916cd43dd8448eb211c80319c';

it('saca el trace_id del traceparent que envio el cliente cuando no hay SDK', function (): void {
    // El caso de la MAYORIA de las instalaciones: sin exportador de trazas no hay
    // span, pero el quiosco manda su `traceparent` en cada `fetch` y con el se
    // puede seguir una peticion entera por el log.
    $extra = procesado(ambient: ['traceparent' => '00-'.TRAZA_ENTRANTE.'-b7ad6b7169203331-01']);

    expect($extra['trace_id'])->toBe(TRAZA_ENTRANTE);
})->group('RF-PD-15', 'RL-08');

it('respeta el trace_id que ya trae el apunte', function (): void {
    // Lo pone la telemetria del acto con el span que midio ESE acto: es el mas
    // preciso que hay.
    $extra = procesado(
        context: ['trace_id' => TRAZA_DEL_APUNTE],
        ambient: ['trace_id' => TRAZA_ENTRANTE],
    );

    expect($extra['trace_id'])->toBe(TRAZA_DEL_APUNTE);
})->group('RF-PD-15');

it('cae al trace_id del contexto cuando el del apunte viene vacio', function (): void {
    // Ocurre de verdad: las clases `*Telemetry` escriben `trace_id => null`
    // cuando el proveedor de trazas es el inerte, y esa linea se quedaba sin nada
    // con que unirla a la peticion.
    $extra = procesado(
        context: ['trace_id' => null],
        ambient: ['trace_id' => TRAZA_ENTRANTE],
    );

    expect($extra['trace_id'])->toBe(TRAZA_ENTRANTE);
})->group('RF-PD-15');

it('no da por bueno un identificador a ceros', function (): void {
    // Es lo que devuelve un span inerte. Escribirlo seria peor que no escribir
    // nada: parece un identificador y nadie lo buscaria dos veces.
    $extra = procesado(
        context: ['trace_id' => '00000000000000000000000000000000'],
        ambient: ['traceparent' => '00-00000000000000000000000000000000-0000000000000000-00'],
    );

    expect($extra)->not->toHaveKey('trace_id');
})->group('RF-PD-15');

it('copia los tres identificadores del contexto de la peticion', function (): void {
    $extra = procesado(ambient: [
        'scan_id' => '019216c0-0000-7000-8000-000000000001',
        'device_id' => '019216c0-0000-7000-8000-000000000002',
        'employee_uuid' => '019216c0-0000-7000-8000-000000000003',
    ]);

    expect($extra['scan_id'])->toBe('019216c0-0000-7000-8000-000000000001')
        ->and($extra['device_id'])->toBe('019216c0-0000-7000-8000-000000000002')
        ->and($extra['employee_uuid'])->toBe('019216c0-0000-7000-8000-000000000003');
})->group('RF-PD-15', 'RL-08');

it('no pisa lo que el apunte ya dice', function (): void {
    // Quien escribio la linea sabia de que hablaba; el contexto solo rellena lo
    // que falta. En un worker de vida larga, una clave heredada pisando la del
    // apunte fecharia la linea con el escaneo equivocado.
    $extra = procesado(
        context: ['scan_id' => 'el-del-apunte'],
        ambient: ['scan_id' => 'el-del-contexto', 'device_id' => 'el-del-contexto'],
    );

    expect($extra)->not->toHaveKey('scan_id')
        ->and($extra['device_id'])->toBe('el-del-contexto');
})->group('RF-PD-15');

it('no anade nada que no sean los cuatro identificadores', function (): void {
    // La regla dura 21 en su forma mas simple: lo que el processor copia esta
    // enumerado, asi que un nombre en el `Context` no puede colarse en el log por
    // esta via.
    $extra = procesado(ambient: [
        'employee_name' => 'Maria Gonzalez Perez',
        'email' => 'maria.gonzalez@hotelplaya.es',
        'scan_id' => 'el-del-contexto',
    ]);

    expect(array_keys($extra))->toBe(['scan_id']);
})->group('RF-PD-15', 'RL-08');

it('devuelve el registro intacto si el contexto revienta', function (): void {
    // Un processor que lanza deja la linea sin escribir, y el sitio donde eso
    // duele es el informe de la excepcion que se estaba intentando registrar.
    $processor = new CorrelationProcessor(static fn (): array => throw new RuntimeException('sin contexto'));

    $record = $processor(new LogRecord(
        new DateTimeImmutable('2026-09-10 06:00:00'),
        'testing',
        Level::Error,
        'algo se rompio',
        ['trace_id' => TRAZA_DEL_APUNTE],
    ));

    expect($record->message)->toBe('algo se rompio')
        ->and($record->extra)->toBe([]);
})->group('RF-PD-15');
