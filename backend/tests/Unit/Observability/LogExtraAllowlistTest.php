<?php

declare(strict_types=1);

use App\Support\Observability\Logging\CorrelationOnlyExtra;
use App\Support\Observability\Logging\CorrelationProcessor;
use Monolog\Level;
use Monolog\LogRecord;

/*
 * **EN `extra` SOLO CABEN LOS CINCO IDENTIFICADORES DEL §8.1** (doc 01 §9.4,
 * RL-08, regla dura 21).
 *
 * ## El defecto que estas pruebas fijan
 *
 * `CorrelationProcessor` prometia en su docblock que el log tecnico lleva
 * «cuatro claves y son identificadores opacos», y la promesa valia para lo que
 * ESE processor anade, no para lo que llega a la linea:
 * `Illuminate\Log\Context\ContextLogProcessor` —del framework— copia
 * `Context::all()` ENTERO a `extra`, y el `LokiHandler` serializa `extra` tal
 * cual. Un `Context::add('employee_name', 'Maria …')` en cualquier punto del
 * producto acababa en Loki y se quedaba ahi los 90 dias de la retencion.
 *
 * El `Context` de Laravel es un canal de proposito general: sirve para arrastrar
 * lo que sea a traves de una peticion y de un job. Que desemboque sin filtro en
 * el log tecnico convierte «no escribas nombres en el log» en una norma que
 * nadie puede verificar.
 *
 * ## Es una lista de PERMITIDOS
 *
 * Mismo criterio que `FieldAllowlist` en el paquete de diagnostico (ADR-020):
 * una lista de prohibidos deja pasar lo que nadie penso en prohibir, que es
 * exactamente el dato nuevo que alguien anade manana. Por eso la ultima prueba
 * de este fichero comprueba un nombre de clave inventado: no hace falta que
 * exista para que la garantia sea cierta.
 */

/**
 * Una linea de log con lo que el framework ya volco en `extra`.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function extraPodado(array $extra): array
{
    $record = new LogRecord(
        new DateTimeImmutable('2026-09-10 06:00:00'),
        'testing',
        Level::Info,
        'attendance.scan_processed',
        [],
        $extra,
    );

    return (new CorrelationOnlyExtra)($record)->extra;
}

it('deja pasar los cinco identificadores del contexto', function (): void {
    $identificadores = [
        'trace_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        'traceparent' => '00-a1b2c3d4e5f60718293a4b5c6d7e8f90-00f067aa0ba902b7-01',
        'scan_id' => '0198f2ab-1c2d-7e3f-8a9b-0c1d2e3f4a5b',
        'device_id' => 'kiosk-recepcion-01',
        'employee_uuid' => '9f8e7d6c-5b4a-4938-8271-605f4e3d2c1b',
    ];

    expect(extraPodado($identificadores))->toBe($identificadores);
})->group('RL-08', 'RF-PD-15');

it('descarta un nombre de empleado que alguien dejo en el Context', function (): void {
    // El caso literal de la regla dura 21, y el que llegaba a Loki.
    $podado = extraPodado([
        'trace_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        'employee_name' => 'Maria Gonzalez Perez',
        'employee_email' => 'maria@hotel.example',
        'national_id' => '12345678Z',
    ]);

    expect($podado)->toBe(['trace_id' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90']);
})->group('RL-08', 'RF-PD-15');

it('descarta tambien una clave que nadie ha previsto, porque es lista de permitidos', function (): void {
    expect(extraPodado(['una_clave_que_no_existia_ayer' => 'lo que sea']))->toBe([]);
})->group('RL-08', 'RF-PD-15');

it('no toca el mensaje ni el contexto del apunte', function (): void {
    // `context` lo escribe quien pone la linea, a la vista en su propio fichero y
    // bajo la revision de `TechnicalLogHasNoPersonalDataTest`. Podarlo aqui
    // borraria informacion que alguien puso a proposito.
    $record = new LogRecord(
        new DateTimeImmutable('2026-09-10 06:00:00'),
        'testing',
        Level::Warning,
        'attendance.scan_rejected',
        ['result' => 'unknown_credential'],
        ['employee_name' => 'Maria'],
    );

    $procesado = (new CorrelationOnlyExtra)($record);

    expect($procesado->message)->toBe('attendance.scan_rejected')
        ->and($procesado->context)->toBe(['result' => 'unknown_credential'])
        ->and($procesado->extra)->toBe([]);
})->group('RL-08');

it('la lista de permitidos es exactamente la del §8.1', function (): void {
    // Que sean cinco y esas cinco no es un detalle de implementacion: es la
    // enumeracion del §8.1. Si alguien anade una sexta, que sea una decision.
    expect(CorrelationOnlyExtra::ALLOWED)->toBe([
        'trace_id',
        'traceparent',
        ...CorrelationProcessor::CORRELATION_KEYS,
    ])->and(CorrelationOnlyExtra::ALLOWED)->toHaveCount(5);
})->group('RL-08');
