<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\RetentionTally;

/**
 * Lectura de las particiones de `audit_log` para el informe (ADR-027).
 *
 * **Esta separado de {@see AuditPartitionArchive} por los permisos, no por
 * gusto.** Contar filas y ver rangos lo hace el rol de la **aplicacion**, que
 * tiene `SELECT`; soltar una particion lo hace el de **mantenimiento**, cuya
 * credencial no vive en el `.env` de la aplicacion (ADR-033). Con un solo
 * puerto, el `--dry-run` —que no borra nada y tiene que poder lanzarse siempre,
 * tambien en una instalacion que aun no custodia esa credencial— exigiria el rol
 * capaz de destruir. La simulacion no puede necesitar mas permisos que los que
 * necesita para simular.
 */
interface AuditPartitionInventory
{
    /**
     * Anos con particion adjunta, ascendente.
     *
     * @return list<int>
     */
    public function attachedYears(): array;

    /** Recuento y rango de fechas de una particion, para el informe. */
    public function summarize(int $year): RetentionTally;
}
