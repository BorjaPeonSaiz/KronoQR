<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Adapter;

use App\Modules\Product\Application\Port\SettingsAnomalyReporter;
use App\Modules\Product\Domain\ValueObject\InvalidSetting;
use App\Modules\Product\Domain\ValueObject\ResolvedSettings;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Anuncia en el log lo que la configuracion guardada tiene y no se puede aplicar
 * (RF-PD-01, doc 02 §8.1).
 *
 * ## `warning` y no `error`
 *
 * El sistema sigue funcionando: rige el valor de serie y se ficha con
 * normalidad. Pero **alguien tiene que enterarse**, porque una clave de impacto
 * `worked_hours` descartada cambia los minutos que se calculan y nadie lo veria
 * en la nomina hasta un mes despues. `error` esta reservado para lo que no se
 * puede completar.
 *
 * ## Se agrupa por ventana, y no es una optimizacion
 *
 * Quien lee la configuracion es el camino de fichaje: `RegisterScanHandler` la
 * pide en cada escaneo. Un aviso por lectura serian cincuenta por segundo en un
 * cambio de turno (RNF-P-06) — el mismo problema que resolvio ADR-037 con las
 * lecturas de datos personales, y con la misma palanca: agrupar por frecuencia
 * detras del puerto, **sin quitar el aviso**. La firma de la anomalia entra en
 * la clave de cache, asi que una corrupcion NUEVA se anuncia de inmediato aunque
 * la anterior siga dentro de su ventana.
 *
 * ## Que sale en el log y que no
 *
 * Nombres de clave del catalogo, el motivo tecnico en ingles y si afecta al
 * calculo. **Nunca el valor guardado**: el nombre de un hotel o la ruta de su
 * logotipo son datos del cliente, y este log viaja a Loki y al paquete de
 * diagnostico, que sale de la instalacion (ADR-020, regla dura 21). El valor
 * completo esta en la tabla y en `audit_log`, donde hay control de acceso.
 *
 * ## No puede romper una lectura
 *
 * Todo el metodo va en `try`: si Redis no contesta, se pierde el aviso y la
 * configuracion se sirve igual. Perder un aviso es infinitamente mas barato que
 * perder un fichaje (regla dura 19).
 */
final readonly class LoggingSettingsAnomalyReporter implements SettingsAnomalyReporter
{
    private const string KEY_PREFIX = 'kronoqr:product:settings_anomaly:';

    public function __construct(
        private LoggerInterface $logger,
        private CacheRepository $cache,
        /** Cuanto se calla una anomalia ya anunciada. Alineado con el TTL de la cache de configuracion. */
        private int $windowSeconds = 300,
    ) {}

    public function report(ResolvedSettings $settings): void
    {
        if (! $settings->hasAnomalies()) {
            // El caso normal. Ni una llamada a Redis: esto corre en el camino de
            // fichaje.
            return;
        }

        try {
            $this->announce($settings);
        } catch (Throwable) {
            // Silencio deliberado y acotado a este metodo: ver el docblock.
        }
    }

    private function announce(ResolvedSettings $settings): void
    {
        $invalid = array_map(
            static fn (InvalidSetting $rejected): array => [
                'key' => $rejected->key->value,
                'reason' => $rejected->detail,
                'affects_worked_hours' => $rejected->affectsWorkedHours,
            ],
            $settings->invalidKeys,
        );

        $context = [
            'invalid_keys' => $invalid,
            'unknown_keys' => $settings->unknownKeys,
        ];

        if ($this->windowSeconds > 0 && ! $this->cache->add($this->keyFor($context), true, $this->windowSeconds)) {
            return;
        }

        $this->logger->warning('product.settings_anomaly', [
            ...$context,
            // Lo que hay que hacer, no solo lo que ha fallado: quien lee esto en
            // el servidor de un cliente no conoce el codigo.
            'action' => 'Las claves invalidas se han descartado y rige su valor de serie. '
                .'Revisa installation_settings y vuelve a guardarlas desde el panel.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function keyFor(array $context): string
    {
        // `sha1` sobre la firma de la anomalia, no sobre su contenido crudo: la
        // clave de cache no tiene por que llevar nada legible, y asi tampoco
        // depende de la longitud.
        return self::KEY_PREFIX.sha1(json_encode($context, JSON_THROW_ON_ERROR));
    }
}
