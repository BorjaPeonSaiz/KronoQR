<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

/**
 * Decide si un uso de un acceso de soporte abre **ventana de auditoria**
 * (RF-PD-11, ADR-020).
 *
 * ## Por que existe
 *
 * Cada peticion autenticada con un token de soporte es un uso, y auditarlas
 * todas seria escribir cientos de asientos en una sesion de veinte minutos, cada
 * uno bajo el candado global de `audit_log` (ADR-010) — el mismo por el que pasa
 * cada fichaje. Auditar solo el primero perderia la sesion de la semana
 * siguiente. La respuesta es la misma palanca que ADR-037 aplica a las lecturas
 * de datos personales: **agrupar por frecuencia sin quitar el aviso**.
 *
 * ## Fallar ABIERTO, y por una vez es lo correcto
 *
 * Si el almacen de la ventana no responde, la implementacion debe decir que si.
 * El coste de equivocarse es un asiento de mas; el de fallar cerrado seria un
 * acceso del fabricante sin rastro, que es exactamente lo que ADR-020 existe
 * para impedir. Es la unica decision de este modulo en la que la duda se resuelve
 * escribiendo.
 */
interface SupportAccessRecorder
{
    /**
     * `true` si toca dejar asiento de este uso, y abre la ventana.
     *
     * La ventana es por concesion: dos sesiones de soporte simultaneas con dos
     * concesiones distintas dejan sus dos asientos.
     *
     * @param  int  $windowSeconds  Ya resuelto de la configuracion por quien
     *                              llama (regla dura 14). `0` desactiva la
     *                              agrupacion y deja un asiento por peticion.
     */
    public function shouldRecord(int $grantId, int $windowSeconds): bool;
}
