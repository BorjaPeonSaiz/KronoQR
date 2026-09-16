<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

/**
 * El codigo de servicio de la instalacion, ya resuelto (**RF-KI-08**, tarea
 * 3.3, regla dura 13).
 *
 * Es el codigo numerico con el que se abre la pantalla de diagnostico de la
 * tablet. Vive en `installation_settings` bajo la clave `KIOSK_SERVICE_CODE`
 * (ADR-017: nada especifico de un cliente en el codigo) y lo consume `Kiosk`,
 * que no puede importar nada de `Product` (doc 02 §1.6). Por eso el puerto esta
 * aqui y su adaptador en `Product/Infrastructure/Adapter` (ADR-025,
 * restriccion 3), igual que {@see OperationalSettingsProvider}.
 *
 * ## Puerto propio y no un campo mas de `OperationalSettings`
 *
 * Aquel objeto son **umbrales del calculo** que `Attendance` pide en cada
 * escaneo —duracion anomala, anti-rebote, desfase, transito— y este es un
 * secreto compartido de una pantalla de mantenimiento. Meterlo alli lo pondria
 * en la ruta caliente del fichaje, en un objeto que se pasa al nucleo, y
 * convertiria cualquier volcado de esa estructura en una fuga del codigo.
 *
 * ## El codigo **nunca** sale de aqui hacia el cliente
 *
 * Lo unico que llega a la tablet es su huella
 * (`KioskHeartbeat.service_code_hash`, `ServiceCodeFingerprint`).
 * Este valor no entra en ningun log, ni en el asiento de auditoria de su propio
 * cambio, ni en el paquete de diagnostico.
 *
 * ## Nunca falla, y por eso devuelve `null`
 *
 * Corre dentro del latido, que es la unica senal de que una tablet sigue viva
 * (regla dura 19). Una instalacion sin codigo —el estado de serie— y una en la
 * que la configuracion no se pudo leer devuelven lo mismo: sin huella, la
 * pantalla se abre sin codigo, que es peor que tenerlo y mucho mejor que dejar
 * una tablet sin diagnostico.
 */
interface KioskServiceCodeProvider
{
    /** El codigo configurado, o `null` si la instalacion no tiene ninguno. */
    public function serviceCode(): ?string;
}
