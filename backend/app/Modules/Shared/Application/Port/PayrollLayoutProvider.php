<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\PayrollLayout;

/**
 * Entrega la **plantilla de la salida a nomina** ya resuelta (**RF-IN-07**,
 * RF-PD-01, regla dura 14, ADR-025).
 *
 * ## Por que es un puerto y no una llamada a la configuracion
 *
 * Por lo mismo que {@see OperationalSettingsProvider} y
 * {@see CompliancePolicyProvider}: quien construye el fichero es `Reporting`,
 * quien tiene `installation_settings` es `Product`, y ninguno de los dos puede
 * importar al otro (doc 02 §1.6). El puerto vive en `Shared/Application/Port` y
 * su adaptador —`Product\Infrastructure\Adapter\DbPayrollLayoutProvider`, en
 * prosa porque un puerto no importa adaptadores— resuelve la cascada de los seis
 * ajustes `PAYROLL_EXPORT_*`.
 *
 * Es ademas lo que hace cumplible la regla dura 14 aqui: el dominio recibe la
 * plantilla **resuelta** y no consulta nada.
 *
 * ## Nunca falla por falta de configuracion
 *
 * Una instalacion sin ninguna fila recibe la plantilla de serie del producto
 * ({@see PayrollLayout::shipped()}), y una fila corrupta se descarta con su valor
 * de serie en lugar de lanzar. Es el mismo criterio que la configuracion
 * operativa y por el mismo motivo: un ajuste de presentacion no puede dejar a
 * RRHH sin poder sacar las horas el dia de cierre de nomina.
 */
interface PayrollLayoutProvider
{
    /**
     * La plantilla vigente para el centro indicado.
     *
     * `$siteId` se conserva en la firma por lo mismo que en
     * {@see OperationalSettingsProvider::forSite()}: hay exactamente un centro
     * por instalacion (ADR-040) y quien llama lo tiene a mano, asi que el dia que
     * el producto volviera a tener varios esto no hay que rehacerlo.
     */
    public function forSite(int $siteId): PayrollLayout;
}
