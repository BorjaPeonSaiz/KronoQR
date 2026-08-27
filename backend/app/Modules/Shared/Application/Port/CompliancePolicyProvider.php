<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use App\Modules\Shared\Domain\ValueObject\CompliancePolicy;

/**
 * Entrega los umbrales **legales** del centro ya resueltos (regla dura 14).
 *
 * El dominio nunca pregunta «que dice la configuracion»: recibe el umbral ya
 * decidido. Esa es la diferencia entre poder vender el producto en otra
 * jurisdiccion cambiando una fila y tener que tocar el repositorio (ADR-017).
 *
 * **Vive en Shared y no en Attendance** (ADR-025) porque lo consumen tres
 * modulos —Attendance, Compliance y Reporting— y no representa una regla de
 * negocio de ninguno: es el criterio de admision que ADR-021 fijo para `Clock`.
 * Su adaptador es `Product/Infrastructure/Adapter/DbCompliancePolicyProvider`,
 * que es donde esta `compliance_profiles` (tarea 5.2). Hoy solo existe la
 * interfaz: adelantar el puerto cuesta un fichero y evita que el dominio nazca
 * leyendo constantes que habria que extraer dos fases mas tarde.
 *
 * Una interfaz con una sola implementacion se justifica aqui porque es un
 * puerto del hexagono: la segunda implementacion es la de las pruebas
 * (doc 02 §3.5).
 */
interface CompliancePolicyProvider
{
    /**
     * Perfil de cumplimiento vigente para el centro indicado.
     *
     * Se resuelve por centro porque `sites.compliance_profile_id` lo es: una
     * instalacion con hoteles en dos jurisdicciones tiene dos perfiles.
     */
    public function forSite(int $siteId): CompliancePolicy;
}
