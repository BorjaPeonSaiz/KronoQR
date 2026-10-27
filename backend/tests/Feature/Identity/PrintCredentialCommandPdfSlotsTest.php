<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Port\CardRenderer;
use App\Modules\Identity\Infrastructure\Adapter\ConcurrencyLimitedCardRenderer;
use App\Modules\Shared\Application\Port\PdfRenderingSlots;
use App\Modules\Shared\Application\Port\PdfRenderingSlotsBusy;
use App\Modules\Shared\Application\Port\PdfRenderingSlotsUnavailable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Lang;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\FakeCardRenderer;
use Tests\Support\Product\InstallationLocale;
use Tests\Support\Shared\RecordingPdfRenderingSlots;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `credentials:print` y `credentials:print-batch` con el tope de Chromium lleno
 * o sin Redis (RF-QR-04). La tarjeta se compone antes de la transaccion que la
 * marca impresa, asi que la orden sale con fallo, lo dice en el idioma de la
 * instalacion y la credencial sigue pendiente: basta con repetirla. Sin la
 * captura, el operador recibia la traza de una excepcion.
 */

uses(RefreshDatabase::class);

/** Un tope que no responde, como `RedisPdfRenderingSlots` sin Redis. */
final class PrintCommandUnavailableSlots implements PdfRenderingSlots
{
    public function compose(Closure $render, int $waitSeconds): string
    {
        throw PdfRenderingSlotsUnavailable::limiterFailed(new RuntimeException('Connection refused'));
    }
}

/** Un empleado con una credencial emitida y pendiente de imprimir. */
function imprimirConsolaEmpleadoPendiente(): string
{
    $employee = WorkforceFixtures::employee(WorkforceFixtures::site());

    expect(Artisan::call('credentials:issue', ['employee' => $employee]))->toBe(0);

    return $employee;
}

function imprimirConsolaTope(PdfRenderingSlots $slots): void
{
    app()->instance(CardRenderer::class, new ConcurrencyLimitedCardRenderer(new FakeCardRenderer, $slots, 0));
}

/**
 * @return array{0: int, 1: string}
 */
function imprimirConsolaEjecutar(string $command, string $employee, string $out): array
{
    $arguments = $command === 'credentials:print'
        ? ['employee' => $employee, '--out' => $out]
        : ['--pending' => true, '--out' => $out];

    $exit = Artisan::call($command, $arguments);

    return [$exit, Artisan::output()];
}

it('con el tope lleno sale con fallo, lo dice en el idioma de la instalacion y no imprime nada', function (string $command, string $locale): void {
    InstallationLocale::set($locale);
    $employee = imprimirConsolaEmpleadoPendiente();
    imprimirConsolaTope(new RecordingPdfRenderingSlots(full: true));
    $out = sys_get_temp_dir().'/kronoqr-tope-lleno-'.bin2hex(random_bytes(4)).'.pdf';
    Exceptions::fake();

    [$exit, $output] = imprimirConsolaEjecutar($command, $employee, $out);

    expect($exit)->toBe(1)
        ->and($output)->toContain(Lang::get('console.credentials.print.pdf_busy', ['seconds' => 1], $locale))
        ->and(is_file($out))->toBeFalse()
        ->and(DB::table('credentials')->whereNotNull('printed_at')->count())->toBe(0);

    // Es capacidad, no un fallo: no va a error_events.
    Exceptions::assertNotReported(PdfRenderingSlotsBusy::class);
})->with([
    'una tarjeta' => ['credentials:print'],
    'el lote' => ['credentials:print-batch'],
])->with(['es', 'en'])->group('RF-QR-04', 'RF-KI-05');

it('sin Redis sale con fallo, lo dice y lo informa como error', function (string $command): void {
    $employee = imprimirConsolaEmpleadoPendiente();
    imprimirConsolaTope(new PrintCommandUnavailableSlots);
    $out = sys_get_temp_dir().'/kronoqr-sin-redis-'.bin2hex(random_bytes(4)).'.pdf';
    Exceptions::fake();

    [$exit, $output] = imprimirConsolaEjecutar($command, $employee, $out);

    expect($exit)->toBe(1)
        ->and($output)->toContain(Lang::get('console.credentials.print.pdf_unavailable', [], 'es'))
        ->and(is_file($out))->toBeFalse()
        ->and(DB::table('credentials')->whereNotNull('printed_at')->count())->toBe(0);

    Exceptions::assertReported(PdfRenderingSlotsUnavailable::class);
})->with([
    'una tarjeta' => ['credentials:print'],
    'el lote' => ['credentials:print-batch'],
])->group('RF-QR-04');
