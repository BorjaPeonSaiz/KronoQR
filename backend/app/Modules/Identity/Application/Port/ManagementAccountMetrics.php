<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * La metrica del ciclo de vida de las cuentas de gestion (RF-ID-10, OWASP A09):
 * `kronoqr_management_account_changes_total{action,role}`.
 *
 * **Es la senal de la alerta de seguridad** (condicion de cierre del bloque
 * 12c): cada restablecimiento de segundo factor y cada alta con rol `admin`
 * tienen que verse sin abrir `audit_log`, porque son los dos pasos con los que
 * un `admin` comprometido se prepara el acceso a otra cuenta o se queda dentro.
 *
 * **Ninguna etiqueta identifica a nadie** (regla dura 21): la accion y el rol
 * de la cuenta afectada, del catalogo cerrado de RF-ID-02. Ni `uuid`, ni
 * correo, ni nombre.
 *
 * **Medir no puede impedir la operacion**: el adaptador traga cualquier fallo
 * del almacen, como el resto de metricas del producto.
 */
interface ManagementAccountMetrics
{
    public function changed(ManagementAccountChange $action, UserRole $role): void;
}
