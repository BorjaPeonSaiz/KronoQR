<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Support;

/**
 * El UNICO criterio de «idioma activo» (RF-PD-01): un idioma pedido es activo
 * si coincide con uno de la lista de la instalacion **sin distinguir
 * mayusculas ni espacios alrededor**, y se guarda con la forma de la lista
 * (`ES` se guarda como `es`).
 *
 * Lo usan las dos vias por las que entra un idioma de persona: la validacion
 * del borde HTTP y de la consola (`App\Http\Rules\ActiveLocale`, a traves de
 * `NegotiableLocales`) y la importacion de plantilla (`PlanEmployeeImport`, a
 * traves de `LocalePolicyProvider`). Los dos puertos existen por una frontera
 * de Deptrac, no por dos criterios: los implementa el mismo adaptador
 * (`DbLocalePolicyProvider`), con la misma lista y el mismo respaldo, y los
 * dos comparan con esta funcion. Con dos comparaciones, la API rechazaba `ES`
 * con un `422` y la importacion lo aceptaba.
 */
final class ActiveLocaleMatch
{
    private function __construct() {}

    /**
     * La forma en la lista del idioma pedido, o `null` si no esta activo.
     *
     * @param  list<string>  $available
     */
    public static function canonical(array $available, string $requested): ?string
    {
        $wanted = mb_strtolower(trim($requested));

        if ($wanted === '') {
            return null;
        }

        foreach ($available as $locale) {
            if (mb_strtolower($locale) === $wanted) {
                return $locale;
            }
        }

        return null;
    }
}
