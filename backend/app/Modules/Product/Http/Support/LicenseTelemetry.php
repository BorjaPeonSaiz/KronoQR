<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Support;

use App\Modules\Product\Domain\ValueObject\LicenseOverview;
use App\Modules\Shared\Application\Support\SpanScope;
use Psr\Log\LoggerInterface;

/**
 * El log de `GET /api/v1/license` y de `POST /license/activate`
 * (doc 02 §8.1, RF-PD-04).
 *
 * ## Lo que este log NO puede llevar
 *
 * **Ni la clave, ni su huella, ni el nombre del cliente.** Este log viaja a Loki
 * y al paquete de diagnostico (ADR-020, RF-PD-09), que sale de la instalacion
 * hacia el fabricante y va **anonimizado por defecto**. El nombre del cliente es
 * precisamente lo que ese paquete no debe llevar sin concesion expresa.
 *
 * Lo que si lleva es el **estado**, que es lo que hace falta para diagnosticar:
 * «esta instalacion lleva desde el martes en `unverifiable`» se responde con
 * esto, y no exige saber de quien es la instalacion — el paquete ya viene
 * identificado por otra via.
 *
 * ## `warning` al activar
 *
 * Activar una licencia cambia que funcionalidades tiene la instalacion. Quien
 * revise el log seis meses despues tiene que tropezarse con esa linea sin ir a
 * buscarla, igual que con un cambio de umbral legal. La lectura es `info`,
 * porque es lo que hace una pantalla al abrirse.
 */
final readonly class LicenseTelemetry
{
    public function __construct(private LoggerInterface $logger) {}

    /**
     * @param  callable(): LicenseOverview  $read
     */
    public function measureRead(callable $read): LicenseOverview
    {
        return $this->measure('product.license_retrieved', $read, warn: false);
    }

    /**
     * @param  callable(): LicenseOverview  $activate
     */
    public function measureActivation(callable $activate): LicenseOverview
    {
        return $this->measure('product.license_activated', $activate, warn: true);
    }

    /**
     * @param  non-empty-string  $logName
     * @param  callable(): LicenseOverview  $work
     */
    private function measure(string $logName, callable $work, bool $warn): LicenseOverview
    {
        $startedAt = microtime(true);

        $overview = $work();

        SpanScope::annotateCurrent(['license.state' => $overview->status->state->value]);

        $context = [
            'trace_id' => SpanScope::currentTraceId(),
            // El estado, y ningun dato del cliente. Ver el docblock.
            'state' => $overview->status->state->value,
            'degraded_features' => \count($overview->status->degradedFeatures()),
            'limits_exceeded' => \count($overview->exceeded()),
            'duration_seconds' => round(microtime(true) - $startedAt, 3),
        ];

        if ($warn) {
            $this->logger->warning($logName, $context);
        } else {
            $this->logger->info($logName, $context);
        }

        return $overview;
    }
}
