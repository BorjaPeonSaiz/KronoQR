<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Quien firmo una correccion del registro horario (RN-13).
 *
 * **Siempre una persona, nunca «el sistema»**: una correccion sin autor no
 * explica nada ante una inspeccion, y por eso `shift_corrections
 * .performed_by_user_id` es `NOT NULL`.
 *
 * Lleva `uuid` y nombre, y no el correo: el `uuid` es el identificador que
 * aparece tambien en `audit_log` —el unico admitido en un log tecnico, regla
 * dura 21— y el nombre existe porque la pantalla de detalle de jornada tiene que
 * mostrar quien cambio las horas de alguien. El correo no aporta nada ahi.
 */
final readonly class CorrectionAuthor
{
    public function __construct(
        public string $uuid,
        public string $name,
    ) {
        if ($uuid === '') {
            throw new InvalidArgumentException('Una correccion necesita el identificador de quien la firmo.');
        }

        if ($name === '') {
            throw new InvalidArgumentException('Una correccion necesita el nombre de quien la firmo.');
        }
    }
}
