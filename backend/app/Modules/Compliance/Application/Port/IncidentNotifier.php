<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

/**
 * Manda el resumen al responsable (RF-PR-01: «se notifica al responsable del
 * departamento»).
 *
 * **Es un puerto y no una llamada a `Mail::`** porque el caso de uso no puede
 * usar facades (doc 02 §3.5) y porque el canal es una decision de instalacion:
 * hoy es correo —las cuentas de gestion tienen `users.email` obligatorio, a
 * diferencia del empleado (regla dura 12, ADR-015)— y manana puede ser otro sin
 * tocar la logica.
 *
 * **Su contrato incluye no romper nada.** Un servidor de correo mal configurado
 * es lo mas comun de una instalacion recien puesta en marcha, y no puede hacer
 * que la deteccion falle: las incidencias ya estan abiertas y visibles en la
 * bandeja, que es lo que el registro necesita. El adaptador atrapa sus propios
 * fallos y los deja en el log.
 */
interface IncidentNotifier
{
    /**
     * Entrega el resumen. Devuelve `false` si no se pudo entregar, para que el
     * caso de uso **no selle** `notified_at` y el aviso vuelva a intentarse en la
     * pasada siguiente.
     */
    public function notify(IncidentDigest $digest): bool;
}
