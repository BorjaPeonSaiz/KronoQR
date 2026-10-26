<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\SupportGrantRepository;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use DateInterval;
use DateTimeImmutable;

/**
 * Vacia el motivo de las concesiones de soporte cerradas hace mas del plazo
 * (RGPD art. 5.1.e, RL-11, ADR-020).
 *
 * ## Por que se vacia y no se borra
 *
 * La concesion es el rastro de que el fabricante entro en la instalacion, y se
 * conserva entera para siempre (regla dura 5): quien, cuando, con que alcance,
 * hasta cuando y si se revoco. Lo que no hace falta para siempre es el texto
 * libre, que puede nombrar a alguien y que ya consta tal cual en el asiento
 * `support_grant.granted`.
 *
 * ## Sin asiento
 *
 * El hecho con valor legal —la concesion y su motivo— ya esta en `audit_log`
 * y no se toca. Vaciar la copia de la fila es mantenimiento, como borrar un
 * token caducado.
 *
 * ## El plazo
 *
 * `PRODUCT_SUPPORT_GRANT_REASON_RETENTION_DAYS`, 90 de serie y **nunca menos de
 * 30**: un valor menor dejaria al cliente sin poder preguntar en el panel por un
 * acceso del mes pasado. Se cuenta desde que la concesion se cerro: su
 * `revoked_at` o, si no se revoco, su `expires_at`. Una concesion vigente no se
 * toca nunca.
 */
final readonly class PruneSupportGrantReasons
{
    /** El minimo que admite el plazo, en dias. */
    public const int MINIMUM_RETENTION_DAYS = 30;

    public function __construct(
        private SupportGrantRepository $grants,
        private Clock $clock,
        private int $retentionDays,
    ) {}

    /** El plazo efectivo, con el minimo ya aplicado. */
    public function retentionDays(): int
    {
        return max(self::MINIMUM_RETENTION_DAYS, $this->retentionDays);
    }

    /** Las concesiones cerradas en o antes de este instante pierden el motivo. */
    public function cutoff(): DateTimeImmutable
    {
        return $this->cutoffAt($this->clock->now());
    }

    /** El corte para un instante ya leido del reloj: el mismo calculo en un solo sitio. */
    public function cutoffAt(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->sub(new DateInterval('P'.$this->retentionDays().'D'));
    }

    /** Cuantas vaciaria {@see self::handle()} ahora. */
    public function pending(): int
    {
        return $this->grants->countReasonsClosedBy($this->cutoff());
    }

    /** @return int Concesiones cuyo motivo se ha vaciado. */
    public function handle(): int
    {
        return UseCaseInstrumentation::run('product.prune_support_grant_reasons', function (): int {
            $now = $this->clock->now();

            return $this->grants->purgeReasonsClosedBy($this->cutoffAt($now), $now);
        });
    }
}
