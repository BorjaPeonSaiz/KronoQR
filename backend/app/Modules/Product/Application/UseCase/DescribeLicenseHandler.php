<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\PlanUsageCounter;
use App\Modules\Product\Domain\ValueObject\License;
use App\Modules\Product\Domain\ValueObject\LicenseOverview;
use App\Modules\Product\Domain\ValueObject\PlanLimit;
use App\Modules\Product\Domain\ValueObject\PlanUsage;

/**
 * La foto completa de la licencia para quien la administra: estado, plan,
 * vigencia y uso frente a lo contratado (RF-PD-04, ADR-028).
 *
 * ## Dos casos de uso y no uno
 *
 * {@see GetLicenseStatusHandler} responde «¿esta habilitado esto?» y lo hace
 * muchas veces por peticion: una fila y una verificacion, sin tocar nada mas.
 * Este responde «cuentame como esta mi licencia» y lo hace tres o cuatro veces
 * al año: ademas cuenta la plantilla y los dispositivos, que son dos consultas
 * de agregacion.
 *
 * Separarlos evita que cada pantalla del panel cuente la plantilla entera solo
 * para saber si puede pintar una comparativa.
 *
 * ## Verifica a proposito, asi que anota
 *
 * Se llama con `touch`: quien pregunta por el estado de su licencia esta
 * verificandola, y esa es la marca que `last_verified_at` guarda (ADR-018).
 */
final readonly class DescribeLicenseHandler
{
    public function __construct(
        private GetLicenseStatusHandler $status,
        private PlanUsageCounter $counter,
    ) {}

    public function handle(): LicenseOverview
    {
        $status = $this->status->handle(touch: true);
        $license = $status->license;

        $usage = array_map(
            fn (PlanLimit $limit): PlanUsage => new PlanUsage(
                $limit,
                // Sin licencia verificada no hay cifra contratada. Las reales se
                // enseñan igual: son utiles por si solas y son lo que el
                // fabricante pide en una revision.
                $license instanceof License ? $license->limits->contractedFor($limit) : null,
                $this->counter->count($limit),
            ),
            PlanLimit::cases(),
        );

        return new LicenseOverview($status, $this->status->stored(), $usage);
    }
}
