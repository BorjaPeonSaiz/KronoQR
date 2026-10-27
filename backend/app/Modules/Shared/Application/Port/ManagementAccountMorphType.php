<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

/**
 * El valor que identifica a una cuenta de gestion en las columnas
 * polimorficas de las librerias (`model_has_roles.model_type`,
 * `personal_access_tokens.tokenable_type`).
 *
 * Lo publica `Identity`, que es quien tiene el modelo, para que otro modulo
 * que lee esas tablas con SQL —la exportacion integra de `Product`— no escriba
 * a mano el nombre de una clase ajena: un literal asi se desincroniza en
 * silencio el dia que la clase cambia de sitio, y los roles salen vacios.
 *
 * No hay `morphMap`: el valor guardado sigue siendo el nombre de la clase
 * (ADR-025, nota del 26-10-2026).
 */
interface ManagementAccountMorphType
{
    public function morphType(): string;
}
