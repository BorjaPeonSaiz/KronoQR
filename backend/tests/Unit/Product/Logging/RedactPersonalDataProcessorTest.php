<?php

declare(strict_types=1);

use App\Modules\Product\Infrastructure\Logging\RedactPersonalDataProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\Support\Time\WallClockBudget;

/*
 * EL LOG TECNICO PASA POR EL MISMO SANEADOR QUE `error_events` (L1, regla dura
 * 21, RF-PD-15, RL-08).
 *
 * Unitaria sobre el `LogRecord`: que sanea mensaje, contexto y excepcion, que
 * no toca la correlacion, que conserva la forma del JSON y que nunca lanza. Que
 * el processor este MONTADO en cada canal, y el ultimo, lo fijan las pruebas de
 * `tests/Feature/Observability`.
 */

/**
 * @param  array<array-key, mixed>  $context
 * @param  array<array-key, mixed>  $extra
 */
function redactPersonalDataRecord(string $message, array $context = [], array $extra = []): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable('2026-10-01T10:00:00Z'),
        channel: 'stderr',
        level: Level::Error,
        message: $message,
        context: $context,
        extra: $extra,
    );
}

const REDACT_PROCESSOR_TRACE_ID = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

const REDACT_PROCESSOR_EMPLOYEE_UUID = '0199a1f0-0000-7000-8000-000000000000';

it('sanea el mensaje de la linea', function (): void {
    $record = (new RedactPersonalDataProcessor)(redactPersonalDataRecord(
        'SQLSTATE[23505]: DETAIL: Key (employee_code)=(E7K2M9QX4B) already exists. (Connection: pgsql, SQL: insert into "employees" values (Maria, 12345678Z))',
    ));

    expect($record->message)->not->toContain('E7K2M9QX4B')
        ->and($record->message)->not->toContain('Maria')
        ->and($record->message)->not->toContain('12345678Z')
        ->and($record->message)->toContain('SQLSTATE[23505]');
})->group('RF-PD-15', 'RL-08');

it('sanea cada texto del contexto, tambien anidado', function (): void {
    $record = (new RedactPersonalDataProcessor)(redactPersonalDataRecord('attendance.scan_failed', [
        'reason' => 'tarjeta E7K2M9QX4B',
        'detalle' => ['iban' => 'ES91 2100 0418 4502 0005 1332', 'lista' => ['documento 12.345.678-Z']],
        'attempts' => 3,
        'durable' => true,
    ]));

    $volcado = json_encode($record->context, JSON_THROW_ON_ERROR);

    expect($volcado)->not->toContain('E7K2M9QX4B')
        ->and($volcado)->not->toContain('2100 0418')
        ->and($volcado)->not->toContain('345.678')
        // Los escalares que no son texto, tal cual: son recuentos, no personas.
        ->and($record->context['attempts'])->toBe(3)
        ->and($record->context['durable'])->toBeTrue();
})->group('RF-PD-15', 'RL-08');

it('sustituye la excepcion por su forma normalizada, con la misma forma y sin el dato', function (): void {
    $previa = new RuntimeException('employee_code=739104 rejected', 7);
    $excepcion = new RuntimeException('Key (employee_code)=(E7K2M9QX4B) already exists.', 23505, $previa);

    $record = (new RedactPersonalDataProcessor)(redactPersonalDataRecord($excepcion->getMessage(), [
        'exception' => $excepcion,
    ]));

    $normalizada = $record->context['exception'];

    expect($normalizada)->toBeArray();

    /** @var array{class: string, message: string, code: int, file: string, previous: array{message: string, code: int}} $normalizada */

    // Las claves de `NormalizerFormatter::normalizeException()` sin traza, que
    // es como escribe el canal `stderr`: el JSON no cambia de forma.
    expect(array_keys($normalizada))->toBe(['class', 'message', 'code', 'file', 'previous'])
        ->and($normalizada['class'])->toBe(RuntimeException::class)
        ->and($normalizada['code'])->toBe(23505)
        ->and($normalizada['file'])->toBe($excepcion->getFile().':'.$excepcion->getLine())
        ->and($normalizada['message'])->not->toContain('E7K2M9QX4B')
        ->and($normalizada['previous']['message'])->not->toContain('739104')
        ->and($normalizada['previous']['code'])->toBe(7);
})->group('RF-PD-15', 'RL-08');

it('deja la excepcion como esta si no habia nada que quitar', function (): void {
    // El caso comun: el formateador la serializa igual que siempre. Un mensaje
    // con palabras del vocabulario tecnico (ADR-048) no cambia.
    $excepcion = new RuntimeException('Connection refused by the server');

    $record = (new RedactPersonalDataProcessor)(redactPersonalDataRecord('x', ['exception' => $excepcion]));

    expect($record->context['exception'])->toBe($excepcion);
})->group('RF-PD-15');

it('no toca los identificadores de correlacion ni extra', function (): void {
    $record = (new RedactPersonalDataProcessor)(redactPersonalDataRecord(
        'attendance.scan_processed',
        ['trace_id' => REDACT_PROCESSOR_TRACE_ID, 'employee_uuid' => REDACT_PROCESSOR_EMPLOYEE_UUID],
        ['trace_id' => REDACT_PROCESSOR_TRACE_ID],
    ));

    expect($record->context)->toBe(['trace_id' => REDACT_PROCESSOR_TRACE_ID, 'employee_uuid' => REDACT_PROCESSOR_EMPLOYEE_UUID])
        ->and($record->extra)->toBe(['trace_id' => REDACT_PROCESSOR_TRACE_ID])
        ->and($record->message)->toBe('attendance.scan_processed');
})->group('RF-PD-15');

it('nunca lanza: si el saneado falla, la linea se escribe sin contenido y con su trace_id', function (): void {
    // Regla dura 19: un fallo del saneado no puede tumbar ni la linea ni la
    // peticion. Y se falla CERRADO: dejarla pasar sin sanear seria una fuga.
    $processor = new RedactPersonalDataProcessor(static fn (string $text): string => throw new RuntimeException('pcre'));

    $record = $processor(redactPersonalDataRecord(
        'Employee E7K2M9QX4B not found',
        ['reason' => 'E7K2M9QX4B'],
        ['trace_id' => REDACT_PROCESSOR_TRACE_ID],
    ));

    expect($record->message)->toBe(RedactPersonalDataProcessor::FAILED)
        ->and($record->context)->toBe([])
        ->and($record->extra)->toBe(['trace_id' => REDACT_PROCESSOR_TRACE_ID])
        ->and($record->level)->toBe(Level::Error);
})->group('RF-PD-15');

it('falla cerrado ante un texto que PCRE no puede leer', function (): void {
    // UTF-8 invalido: `preg_replace` con `/u` devuelve null. Sin manejarlo, el
    // texto se quedaria vacio sin aviso o, peor, pasaria sin sanear.
    $record = (new RedactPersonalDataProcessor)(redactPersonalDataRecord("E7K2M9QX4B \xC3\x28"));

    expect($record->message)->toBe(RedactPersonalDataProcessor::REDACTED);
})->group('RF-PD-15');

it('cuesta poco por linea', function (): void {
    /*
     * MICROBENCHMARK, no una prueba de rendimiento de verdad: fija un techo
     * holgado para que un patron catastrofico (retroceso exponencial) no pase
     * desapercibido. Una linea tipica del fichaje —mensaje corto y seis claves
     * de contexto— por debajo de 0,5 ms de media, con margen de sobra sobre lo
     * que se mide en el contenedor de desarrollo (anotado en el commit).
     */
    $processor = new RedactPersonalDataProcessor;
    $record = redactPersonalDataRecord('attendance.scan_processed', [
        'trace_id' => REDACT_PROCESSOR_TRACE_ID,
        'employee_uuid' => REDACT_PROCESSOR_EMPLOYEE_UUID,
        'scan_id' => '0199a1f0-0000-7000-8000-000000000001',
        'device_id' => '0199a1f0-0000-7000-8000-000000000002',
        'result' => 'accepted',
        'duration_ms' => 12.5,
    ]);

    $vueltas = 2000;
    $inicio = hrtime(true);

    for ($i = 0; $i < $vueltas; $i++) {
        $processor($record);
    }

    $mediaMs = (hrtime(true) - $inicio) / $vueltas / 1_000_000;

    // Sin instrumentacion se afirma; bajo `make coverage` y la mutacion, que
    // corren con Xdebug en modo coverage, se anuncia (CI-COB-01).
    WallClockBudget::expectBelowMilliseconds($mediaMs, 0.5, 'RF-PD-15');
})->group('RF-PD-15');

/*
 * ---------------------------------------------------------------------------
 * ADR-048 (bloque 19): el mensaje y las excepciones pasan por la lista blanca
 * por palabra; las claves anidadas tambien; las de correlacion, solo con su
 * forma.
 * ---------------------------------------------------------------------------
 */

it('quita un nombre sin comillas del mensaje de la linea', function (): void {
    $record = (new RedactPersonalDataProcessor)(redactPersonalDataRecord('No se pudo fichar a Rosa Ficticiana'));

    expect($record->message)->not->toContain('Rosa')
        ->and($record->message)->not->toContain('Ficticiana')
        ->and($record->message)->toBe('No se pudo fichar a …');
})->group('RF-PD-15', 'RL-19');

it('quita un nombre del mensaje de una excepcion y del de su previa', function (): void {
    $previa = new RuntimeException('Employee Luz Inventadez not found');
    $excepcion = new RuntimeException('No se pudo fichar a Rosa Ficticiana', 0, $previa);

    $record = (new RedactPersonalDataProcessor)(redactPersonalDataRecord('x', ['exception' => $excepcion]));
    $volcado = json_encode($record->context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    expect($volcado)->not->toContain('Ficticiana')
        ->and($volcado)->not->toContain('Inventadez')
        ->and($volcado)->not->toContain('Rosa')
        ->and($volcado)->toContain('Employee … not found');
})->group('RF-PD-15', 'RL-19');

it('sanea las claves de un mapa anidado y no pierde valores si chocan', function (): void {
    $record = (new RedactPersonalDataProcessor)(redactPersonalDataRecord('workforce.import_failed', [
        'rows' => [
            'Rosa Ficticiana' => 'fila 1',
            'Luz Inventadez' => 'fila 2',
            'Will Testerson' => 'fila 3',
            'error' => 'fila 4',
        ],
    ]));

    /** @var array<string, mixed> $filas */
    $filas = $record->context['rows'];

    expect(array_keys($filas))->toBe(['…', '…#2', '…#3', 'error'])
        ->and(array_values($filas))->toBe(['fila 1', 'fila 2', 'fila 3', 'fila 4'])
        // Las del primer nivel las escribe el codigo: no se tocan.
        ->and(array_keys($record->context))->toBe(['rows']);
})->group('RF-PD-15', 'RL-19');

it('solo deja pasar una clave de correlacion con su forma', function (string $clave, string $valor): void {
    $record = (new RedactPersonalDataProcessor)(redactPersonalDataRecord('x', [$clave => $valor]));

    expect($record->context[$clave])->toBe($valor);
})->with([
    'trace_id' => ['trace_id', REDACT_PROCESSOR_TRACE_ID],
    'traceparent' => ['traceparent', '00-'.REDACT_PROCESSOR_TRACE_ID.'-00f067aa0ba902b7-01'],
    'employee_uuid' => ['employee_uuid', REDACT_PROCESSOR_EMPLOYEE_UUID],
    'device_id' => ['device_id', '0199A1F0-ABCD-7000-8000-00000000AB12'],
    'scan_id' => ['scan_id', '0199a1f0-0000-7000-8000-000000000001'],
])->group('RF-PD-15');

it('sanea una clave de correlacion que no tiene su forma', function (string $clave, string $valor, string $prohibido): void {
    $record = (new RedactPersonalDataProcessor)(redactPersonalDataRecord('x', [$clave => $valor]));

    expect((string) json_encode($record->context[$clave]))->not->toContain($prohibido);
})->with([
    'employee_uuid con un correo' => ['employee_uuid', 'rosa.ficticiana@hotel-ejemplo.es', 'rosa.ficticiana'],
    'device_id con un telefono' => ['device_id', 'tablet 612 345 678', '612 345 678'],
    'trace_id con un dni' => ['trace_id', '45678912K', '45678912K'],
])->group('RF-PD-15', 'RL-19');

it('deja pasar una clave de correlacion que no es texto solo si la sanea', function (): void {
    // Un `employee_uuid` numerico no tiene la forma: pasa por el camino normal,
    // que deja los enteros como estan.
    $record = (new RedactPersonalDataProcessor)(redactPersonalDataRecord('x', ['employee_uuid' => 42]));

    expect($record->context['employee_uuid'])->toBe(42);
})->group('RF-PD-15');

it('los valores del contexto siguen pasando solo por patrones (D3)', function (): void {
    // Una ruta o una clase que no esta en el vocabulario se conserva en el log
    // local: lo escribe el codigo del producto. Un correo no.
    $record = (new RedactPersonalDataProcessor)(redactPersonalDataRecord('x', [
        'job' => 'App\\Modules\\Workforce\\Infrastructure\\Jobs\\ImportarPlantilla',
        'reason' => 'aviso a rosa@hotel-ejemplo.es',
    ]));

    expect($record->context['job'])->toBe('App\\Modules\\Workforce\\Infrastructure\\Jobs\\ImportarPlantilla')
        ->and($record->context['reason'])->toBe('aviso a [email]');
})->group('RF-PD-15');
