<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Policy;

use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede descargar el registro horario de la plantilla entera (RF-IN-05,
 * RF-ID-03, regla dura 18, Anexo B del doc 01).
 *
 * ## Tres roles, y cada uno por un motivo distinto
 *
 * - **`auditor`.** Es su funcion: mirar el registro sin poder tocarlo. Su token
 *   lleva `reports:legal` y **no** lleva `attendance:correct`.
 * - **`rrhh`.** Es quien atiende materialmente un requerimiento de Inspeccion en
 *   un hotel. Lleva `reports:*`, la familia entera.
 * - **`admin`.** Administrador de la instalacion; lleva las dos.
 *
 * El plan de la tarea 1.17 lo escribe como «`auditor|rrhh`» y anota que el
 * alcance propio del `auditor` es de la tarea 2.1. **Aqui se declara ya**, y no
 * se pospone: dejar el rol fuera hoy significaria que la 2.1 tiene que acordarse
 * de volver, y una autorizacion que se amplia despues es la clase de cambio que
 * nadie revisa. El catalogo de permisos ya le concede `reports:legal`, asi que
 * omitirlo aqui habria dejado a las dos mitades diciendo cosas distintas.
 *
 * ## Quien NO esta, y es lo importante
 *
 * - **`responsable_departamento`.** Su alcance es su departamento (RF-ID-03) y
 *   este endpoint no tiene alcance parcial: entrega la plantilla completa o una
 *   persona. Dejarle entrar hoy seria darle el registro horario de toda la
 *   instalacion, que es justo lo que ese requisito viene a impedir. Su
 *   exportacion acotada, si algun dia existe, sera otro endpoint con otro
 *   alcance en la consulta.
 * - **`empleado`.** Consulta **lo suyo** por el portal, con `self:read`
 *   (ADR-015). Su propio registro no es «la exportacion legal completa», y por
 *   eso la prueba negativa de esta tarea lo nombra explicitamente.
 * - **`kiosk`.** Un token colgado de una pared no descarga listas nominales
 *   (RS-04).
 *
 * ## Es la mitad de la autorizacion
 *
 * La otra es el ambito del token —`reports:legal` **o** `reports:*`—, que
 * comprueba el middleware `ability` antes de llegar aqui (doc 02 §7.3). Con las
 * dos, un token de quiosco no alcanza el endpoint aunque su portador tuviera rol,
 * y una cuenta con ambito pero sin rol tampoco.
 */
final class LegalExportPolicy
{
    /**
     * Roles que pueden generar la exportacion legal completa.
     *
     * @return list<UserRole>
     */
    private static function exporters(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH, UserRole::AUDITOR];
    }

    /**
     * `GET /api/v1/reports/legal-export`.
     */
    public function generate(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::exporters());
    }
}
