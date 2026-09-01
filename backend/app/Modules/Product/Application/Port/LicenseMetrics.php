<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\PlanLimit;

/**
 * Instrumentacion de la licencia (doc 02 §8.2).
 *
 * Una sola serie, `license_limit_exceeded_total{limit}`, y esta elegida a
 * proposito: es la unica cifra de licencia que **cambia sola** y que interesa
 * ver en el tiempo. El estado de la licencia no es una metrica —es un estado, y
 * vive en `GET /api/v1/health`, en `GET /api/v1/license` y en `license:show`—,
 * y las cifras de uso frente a plan se consultan cuando se preguntan, no se
 * muestrean.
 *
 * El contador sube con **cada alta en exceso**, no solo con el cruce: es lo que
 * permite ver en una grafica si el hotel se paso tres personas en marzo o
 * cuarenta desde junio.
 */
interface LicenseMetrics
{
    public function limitExceeded(PlanLimit $limit): void;
}
