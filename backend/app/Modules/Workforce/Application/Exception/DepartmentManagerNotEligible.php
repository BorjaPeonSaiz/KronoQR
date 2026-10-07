<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Exception;

use RuntimeException;

/**
 * La cuenta propuesta como responsable no puede dirigir un departamento: no
 * existe, esta dada de baja o tiene otro rol (RF-ID-10, ADR-051 §5).
 *
 * **Un solo motivo para las tres causas**, a proposito y tambien en el mensaje
 * tecnico: distinguirlas diria a quien prueba uuid si una cuenta existe. Sale
 * como `422` en `errors.manager_user_uuid` (`bootstrap/app.php`), con el texto
 * de `departments.errors.manager_not_eligible` en el idioma de la peticion.
 *
 * Sin el uuid en el mensaje: el mensaje acaba en el log y en `error_events`, y
 * ahi no hace falta para nada.
 */
final class DepartmentManagerNotEligible extends RuntimeException
{
    public const string FIELD = 'manager_user_uuid';

    public const string TRANSLATION_KEY = 'departments.errors.manager_not_eligible';

    public static function create(): self
    {
        return new self('La cuenta no es una cuenta de gestion activa con rol de responsable de departamento.');
    }
}
