<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\TelemetryUsage;

/**
 * Los contadores **acumulados** de las series que alimentan `usage_7d`
 * (**RF-PD-12**, doc 02 §8.2).
 *
 * ## Acumulados, y la resta la hace el caso de uso
 *
 * Las series del producto son contadores monotonos desde que se instalo
 * (`scans_total`, `report_exports_total`...). Este puerto devuelve la lectura de
 * hoy tal cual; quien la convierte en «lo ocurrido esta semana» es
 * `BuildTelemetryReportHandler`, restando el acumulado del envio anterior que
 * guarda {@see TelemetryStateStore}. Poner la resta aqui obligaria al adaptador
 * a conocer el estado y a decidir una regla de negocio.
 *
 * ## Nunca lanza y puede devolver menos claves de las que promete
 *
 * Una serie que no existe **no aparece en el mapa**, y eso se traduce en un
 * `null` en el documento. Es la diferencia entre «no hubo fichajes» y «no lo
 * sabemos», y la ficha exige la segunda: *si una serie no existe, `null`, nunca
 * inventar*.
 *
 * @see TelemetryUsage
 */
interface TelemetryCounters
{
    /** Clave de `usage_7d` => acumulado. Las que faltan no se conocen. */
    public const array KEYS = ['scans_accepted', 'scans_rejected', 'batches_synced', 'reports_generated'];

    /**
     * @return array<string, int>
     */
    public function snapshot(): array;
}
