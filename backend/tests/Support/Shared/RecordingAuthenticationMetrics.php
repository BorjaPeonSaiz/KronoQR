<?php

declare(strict_types=1);

namespace Tests\Support\Shared;

use App\Modules\Shared\Application\Port\AuthenticationMetrics;
use App\Modules\Shared\Domain\ValueObject\AuthChannel;
use App\Modules\Shared\Domain\ValueObject\AuthOutcome;

/**
 * Doble de `kronoqr_auth_attempts_total{channel,outcome}` que recuerda lo
 * contado.
 *
 * Mismo papel que `RecordingScanMetrics` y `RecordingPinMetrics`: lo que hay que
 * comprobar es que el intento se cuenta, con que canal y con que desenlace, y
 * eso se verifica con un doble en vez de levantando Redis y Prometheus.
 *
 * Las etiquetas se guardan como la cadena `channel=…,outcome=…`, que es
 * literalmente la clave con la que el adaptador de Redis las escribe: si alguien
 * cambiara el orden o el nombre de una etiqueta, la prueba lo veria.
 */
final class RecordingAuthenticationMetrics implements AuthenticationMetrics
{
    /** @var array<string, int> */
    public array $attempts = [];

    public function attempt(AuthChannel $channel, AuthOutcome $outcome): void
    {
        $key = 'channel='.$channel->value.',outcome='.$outcome->value;

        $this->attempts[$key] = ($this->attempts[$key] ?? 0) + 1;
    }

    public function countOf(AuthChannel $channel, AuthOutcome $outcome): int
    {
        return $this->attempts['channel='.$channel->value.',outcome='.$outcome->value] ?? 0;
    }
}
