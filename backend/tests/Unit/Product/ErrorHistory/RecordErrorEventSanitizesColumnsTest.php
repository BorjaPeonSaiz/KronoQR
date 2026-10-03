<?php

declare(strict_types=1);

use App\Modules\Product\Application\Port\ErrorMetrics;
use App\Modules\Product\Application\UseCase\RecordErrorEvent;
use App\Modules\Shared\Domain\ValueObject\ErrorLevel;
use App\Modules\Shared\Domain\ValueObject\ErrorReport;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use Psr\Log\NullLogger;
use Tests\Support\Product\InMemoryErrorHistory;
use Tests\Support\Time\FixedClock;

/*
 * `RecordErrorEvent` sanea TODO el texto libre antes de llegar al repositorio
 * (ADR-048 decision 5, H7; RF-PD-15, RL-19).
 *
 * No solo el mensaje y el contexto: `app_version` lo escribe el cliente, y
 * `file`, `exception_class` y `code` pueden traer una ruta, una clase anonima o
 * un codigo de libreria con cualquier cosa dentro. Y la huella se calcula
 * DESPUES de sanear: si no, seria un `sha256` sin sal de un nombre.
 */

/**
 * @param  array<string, scalar|null>  $context
 */
function recordErrorEventSanitizesReport(
    string $message,
    ErrorSource $source = ErrorSource::Api,
    string $appVersion = '2.2.0',
    ?string $code = null,
    ?string $exceptionClass = RuntimeException::class,
    ?string $file = 'app/Modules/Product/Application/UseCase/RecordErrorEvent.php',
    array $context = [],
): ErrorReport {
    return new ErrorReport(
        source: $source,
        level: ErrorLevel::Error,
        message: $message,
        occurredAt: new DateTimeImmutable('2026-10-03T10:00:00Z'),
        appVersion: $appVersion,
        context: $context,
        code: $code,
        exceptionClass: $exceptionClass,
        file: $file,
        line: 12,
    );
}

function recordErrorEventSanitizesUseCase(InMemoryErrorHistory $history): RecordErrorEvent
{
    $metrics = new class implements ErrorMetrics
    {
        public function errorRecorded(ErrorSource $source, ErrorLevel $level): void {}

        public function groupOpened(ErrorSource $source, ErrorLevel $level): void {}
    };

    return new RecordErrorEvent($history, $metrics, FixedClock::at('2026-10-03 10:00:00'), new NullLogger, 500, 90);
}

it('no deja llegar un nombre al repositorio por ninguna columna de texto', function (): void {
    $history = new InMemoryErrorHistory;

    recordErrorEventSanitizesUseCase($history)->record(recordErrorEventSanitizesReport(
        message: 'No se pudo fichar a Rosa Ficticiana',
        appVersion: 'RosaFicticiana',
        code: 'Ficticiana',
        exceptionClass: 'class@anonymous/var/www/Ficticiana.php',
        file: '/home/ficticiana/Inventadez.php',
        context: ['reason' => 'Luz Inventadez', 'Will Testerson' => 'x'],
    ));

    expect($history->writes)->toHaveCount(1);

    $escrito = $history->writes[0];
    $volcado = json_encode([
        $escrito['message'], $escrito['context'], $escrito['report']->appVersion, $escrito['report']->code,
        $escrito['report']->exceptionClass, $escrito['report']->file,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    foreach (['Rosa', 'Ficticiana', 'Luz', 'Inventadez', 'Will', 'Testerson'] as $nombre) {
        expect($volcado)->not->toContain($nombre);
    }

    // Y lo que queda dice algo: el codigo de un error del servidor que no es ni
    // SQLSTATE ni entero se guarda nulo, no como texto filtrado.
    expect($escrito['report']->code)->toBeNull()
        ->and($escrito['message'])->toBe('No se pudo fichar a …');
})->group('RF-PD-15', 'RL-19');

it('conserva las columnas que tienen la forma que produce el producto', function (): void {
    $history = new InMemoryErrorHistory;

    recordErrorEventSanitizesUseCase($history)->record(recordErrorEventSanitizesReport(
        message: 'SQLSTATE[23505]: Unique violation',
        appVersion: '2.2.0-rc.1',
        code: '23505',
        exceptionClass: 'Illuminate\\Database\\QueryException',
        file: 'vendor/laravel/framework/src/Illuminate/Database/Connection.php',
    ));

    $report = $history->writes[0]['report'];

    expect($report->code)->toBe('23505')
        ->and($report->exceptionClass)->toBe('Illuminate\\Database\\QueryException')
        ->and($report->file)->toBe('vendor/laravel/framework/src/Illuminate/Database/Connection.php')
        ->and($report->appVersion)->toBe('2.2.0-rc.1');
})->group('RF-PD-15');

it('dos informes que solo se distinguen por el nombre comparten huella', function (): void {
    // La agrupacion mejora: el mismo fallo de dos personas es un solo grupo, y
    // la huella no puede servir para adivinar a quien se referia.
    $history = new InMemoryErrorHistory;
    $useCase = recordErrorEventSanitizesUseCase($history);

    $useCase->record(recordErrorEventSanitizesReport('No se pudo fichar a Rosa Ficticiana', ErrorSource::Admin, code: 'web.vue_error'));
    $useCase->record(recordErrorEventSanitizesReport('No se pudo fichar a Luz Inventadez', ErrorSource::Admin, code: 'web.vue_error'));

    expect($history->writes)->toHaveCount(2)
        ->and($history->writes[0]['fingerprint'])->toBe($history->writes[1]['fingerprint']);
})->group('RF-PD-15', 'RL-19');

it('la regla de SQLSTATE solo se aplica a los errores del servidor (H7)', function (): void {
    $history = new InMemoryErrorHistory;

    recordErrorEventSanitizesUseCase($history)->record(
        recordErrorEventSanitizesReport('x', ErrorSource::Kiosk, code: 'kiosk.camera.unavailable', exceptionClass: null, file: null),
    );

    expect($history->writes[0]['report']->code)->toBe('kiosk.camera.unavailable');
})->group('RF-PD-15');
