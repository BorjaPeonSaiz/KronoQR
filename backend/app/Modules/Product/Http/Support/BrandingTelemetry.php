<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Support;

use App\Modules\Product\Application\UseCase\InstallationBranding;
use App\Modules\Shared\Application\Support\SpanScope;
use App\Modules\Shared\Domain\ValueObject\LogoImage;
use OpenTelemetry\API\Trace\SpanKind;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * La traza y el log de `GET /api/v1/branding` (doc 02 §8.1, RF-PD-08).
 *
 * Mismo sitio y mismo motivo que {@see SettingsTelemetry}: en el borde, para que
 * el `trace_id` de `traceparent` sea el padre del span y para que el caso de uso
 * se quede componiendo la marca sin un `try/finally` de medicion alrededor.
 *
 * ## Que se registra, y por que tan poco
 *
 * Si hay color propio, si hay logotipo y cuantos idiomas se ofrecen. **Ni el
 * nombre de la aplicacion, ni el color, ni la ruta del logotipo.** Este log viaja
 * a Loki y al paquete de diagnostico, que sale de la instalacion (ADR-020, regla
 * dura 16): el nombre del hotel es un dato del cliente y su sitio es `audit_log`,
 * donde hay control de acceso y retencion.
 *
 * Con dos booleanos y una cuenta se responde a lo unico que se pregunta cuando
 * algo va mal —«¿este servidor esta sirviendo la marca configurada o la del
 * producto?»— sin llevarse nada. Es la misma linea que separa `settings.keys` de
 * los valores en la telemetria de la configuracion.
 *
 * ## `info`, aunque sea la ruta mas llamada del producto
 *
 * La piden todos los navegadores al arrancar. Es una lectura y no cambia nada,
 * asi que `info`, como el resto de lecturas; si el volumen molestara, lo que se
 * ajusta es el nivel del canal y no lo que se registra.
 */
final readonly class BrandingTelemetry
{
    public function __construct(private LoggerInterface $logger) {}

    /**
     * @param  callable(): InstallationBranding  $read
     */
    public function measureRead(callable $read): InstallationBranding
    {
        $span = SpanScope::start('kronoqr.product', 'product.read_branding', SpanKind::KIND_SERVER);
        $startedAt = microtime(true);

        try {
            $branding = $read();
        } catch (Throwable $failure) {
            $span->end();

            throw $failure;
        }

        $customAccent = $branding->accentColor !== null;
        $hasLogo = $branding->logo instanceof LogoImage;
        $locales = \count($branding->locales->available);

        $span->end([
            'branding.custom_accent' => $customAccent,
            'branding.has_logo' => $hasLogo,
            'branding.locales' => $locales,
        ]);

        $this->logger->info('product.branding_read', [
            'trace_id' => $span->traceId(),
            // Dos hechos y una cuenta, ningun valor. Ver el docblock de la clase.
            'custom_accent' => $customAccent,
            'has_logo' => $hasLogo,
            'locales' => $locales,
            'duration_seconds' => round(microtime(true) - $startedAt, 3),
        ]);

        return $branding;
    }
}
