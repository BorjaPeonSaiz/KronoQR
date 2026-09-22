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
 * El contador sube con **cada operacion en exceso**, no solo con el cruce: es lo
 * que permite ver en una grafica si el hotel se paso en marzo o desde junio.
 *
 * ## Una operacion, un incremento — tambien la importacion
 *
 * Una carga masiva de plantilla (RF-GP-05) sube el contador **una vez**, no una
 * por persona: es la misma unidad que el asiento de `audit_log` desde la 3.8
 * (H-04), y subirlo doscientas veces de golpe dibujaria un pico indistinguible
 * de doscientas altas hechas una a una. **La magnitud no se muestrea aqui**: el
 * asiento lleva el recuento alcanzado y cuantas altas de esa operacion quedaron
 * por encima del plan, y `GET /api/v1/license` responde la cifra de hoy cuando
 * se le pregunta.
 */
interface LicenseMetrics
{
    public function limitExceeded(PlanLimit $limit): void;
}
