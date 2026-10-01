<?php

declare(strict_types=1);

use App\Modules\Product\Infrastructure\Logging\RedactPersonalData;
use App\Modules\Product\Infrastructure\Logging\RedactPersonalDataProcessor;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Monolog\Logger as Monolog;
use Monolog\Processor\PsrLogMessageProcessor;

/*
 * **TODOS LOS CANALES DE LOG SANEAN, Y EL SANEADO ES LO ULTIMO** (L1, regla
 * dura 21, RF-PD-15, RL-08).
 *
 * `RedactPersonalDataProcessor` solo protege la linea si esta montado en el
 * canal que la escribe. Un canal nuevo en `config/logging.php` sin el tap —un
 * `daily` que alguien active en una instalacion, un `syslog` para el SIEM del
 * cliente— escribiria las excepciones en claro sin que ninguna otra prueba lo
 * viera. Por eso se recorre la configuracion entera y no una lista escrita a
 * mano.
 *
 * Y el orden: Monolog ejecuta los processors como una pila. Si el saneado
 * corriera antes de `PsrLogMessageProcessor`, la interpolacion de
 * `{placeholders}` meteria en el mensaje lo que el contexto llevara.
 */

/**
 * Canales que no escriben a ningun sitio o que no pasan por los taps.
 *
 * - `stack` no declara taps: hereda los processors de sus canales, y eso lo
 *   fija la ultima prueba de este fichero.
 * - `null` descarta.
 * - `emergency` no es un canal: es la ruta del logger de emergencia que
 *   Laravel monta a mano cuando la configuracion falla, sin taps posibles.
 */
const EVERY_LOG_CHANNEL_EXENTOS = ['stack', 'null', 'emergency'];

it('declara el tap de saneado en cada canal que escribe', function (): void {
    /** @var array<string, array<string, mixed>> $canales */
    $canales = config('logging.channels');

    $sinTap = [];

    foreach ($canales as $nombre => $canal) {
        if (in_array($nombre, EVERY_LOG_CHANNEL_EXENTOS, true)) {
            continue;
        }

        $taps = is_array($canal['tap'] ?? null) ? $canal['tap'] : [];

        if (! in_array(RedactPersonalData::class, $taps, true)) {
            $sinTap[] = $nombre;
        }
    }

    expect($sinTap)->toBe([], 'Estos canales de log escriben sin sanear (regla dura 21): '.implode(', ', $sinTap)
        .'. Anade RedactPersonalData::class a su clave `tap` en config/logging.php.');

    // Y el recorrido no ha sido en vacio.
    expect(count($canales))->toBeGreaterThan(count(EVERY_LOG_CHANNEL_EXENTOS));
})->group('RF-PD-15', 'RL-08');

/**
 * El Monolog que hay debajo de un canal.
 */
function everyLogChannelMonolog(string $canal): Monolog
{
    $wrapper = Log::channel($canal);

    $monolog = $wrapper instanceof Logger ? $wrapper->getLogger() : null;

    if (! $monolog instanceof Monolog) {
        throw new RuntimeException('El canal '.$canal.' no es un logger de Monolog.');
    }

    return $monolog;
}

it('deja el saneado el ultimo de la cadena, detras de la interpolacion', function (string $canal): void {
    $processors = everyLogChannelMonolog($canal)->getProcessors();
    $ultimo = end($processors);

    expect($ultimo)->toBeInstanceOf(RedactPersonalDataProcessor::class);

    // Uno solo: aplicar el tap dos veces no duplica el trabajo por linea.
    $saneadores = array_filter($processors, static fn (mixed $p): bool => $p instanceof RedactPersonalDataProcessor);
    expect($saneadores)->toHaveCount(1);

    $psr = array_keys(array_filter($processors, static fn (mixed $p): bool => $p instanceof PsrLogMessageProcessor));

    foreach ($psr as $posicion) {
        expect($posicion)->toBeLessThan(count($processors) - 1);
    }
})->with(['stderr', 'single', 'daily'])->group('RF-PD-15', 'RL-08');

it('la pila hereda el saneado de cada canal y termina en el', function (): void {
    config(['logging.channels.stack.channels' => ['stderr']]);
    Log::forgetChannel('stack');

    $processors = everyLogChannelMonolog('stack')->getProcessors();

    expect(end($processors))->toBeInstanceOf(RedactPersonalDataProcessor::class);
})->group('RF-PD-15', 'RL-08');
