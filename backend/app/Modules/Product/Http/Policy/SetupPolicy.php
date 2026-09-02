<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Policy;

use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede tocar el asistente de puesta en marcha (RF-PD-03, regla dura 18).
 *
 * ## Solo `admin`
 *
 * El asistente decide la zona horaria con la que se atribuyen las jornadas
 * (RN-05), el perfil de umbrales con el que se calcula el cumplimiento (RL-21) y
 * el momento en que la instalacion se da por configurada. Es la misma potestad
 * que `/settings` y `/compliance-profile`, y por eso comparte su ambito
 * (`settings:*`, doc 02 §7.3), que el §7.3 concede unicamente al administrador
 * de instalacion.
 *
 * **`rrhh` no entra**, aunque sea quien mas usa lo que el asistente configura:
 * quien gestiona la plantilla no decide con que umbrales se mide su jornada. **El
 * `auditor` tampoco**: su trabajo es leer el registro, no configurarlo. Y el
 * quiosco no lleva `settings:*` en su token, asi que ni llega al middleware.
 *
 * ## Lo que esta policy NO cubre
 *
 * **La creacion del primer administrador no pasa por aqui**, y no puede: ocurre
 * cuando todavia no hay ninguna cuenta a la que autorizar. Su unica guarda es
 * que no exista ninguna cuenta de gestion, y vive en `Identity`, que es quien
 * tiene el dato. **El alta del centro tampoco**: la autoriza {@see SitePolicy}
 * de `Workforce`, porque el recurso es suyo.
 *
 * ## Tres metodos aunque el conjunto de roles sea el mismo
 *
 * Para que la autorizacion negativa pruebe cada endpoint por separado, igual que
 * en {@see LicensePolicy}: un `authorize()` unico que devolviera `true` solo en
 * uno de los caminos seria invisible desde los otros.
 *
 * Y `view()` existe **separado de `record()`** aunque hoy autoricen a los mismos:
 * `GET /setup/steps` es una lectura y `PUT /setup/steps/{step}` una escritura, y
 * autorizar la primera con el verbo de la segunda ata dos decisiones que pueden
 * separarse —el dia que el `auditor` deba poder mirar el inventario de la puesta
 * en marcha, abrirlo aqui abriria tambien el poder de marcar pasos—.
 */
final class SetupPolicy
{
    /**
     * @return list<UserRole>
     */
    private static function administrators(): array
    {
        return [UserRole::ADMIN];
    }

    /** Consultar los pasos y su estado (`GET /setup/steps`). */
    public function view(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::administrators());
    }

    /** Marcar un paso como hecho u omitido. */
    public function record(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::administrators());
    }

    /** Cerrar el asistente, que no se reabre. */
    public function complete(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::administrators());
    }
}
