<?php

declare(strict_types=1);

namespace App\Http\Rules;

use App\Support\Locale\NegotiableLocales;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Un idioma que **la instalacion tiene activo** (`LOCALE_AVAILABLE`; RF-KI-05,
 * regla dura 13).
 *
 * ## Una sola regla para todo lo que fija un idioma
 *
 * El alta y la modificacion de un empleado, el primer administrador, el alta de
 * una cuenta de gestion, la hoja de instrucciones y `identity:create-user`
 * aceptan un idioma. Antes cada uno lo validaba a su manera —`min:2|max:10` en
 * unos, `in:` contra la lista en otros— y un `es` fijo hacia de valor por
 * defecto en tres de ellos. De serie solo esta activo el español, y con dos
 * criterios distintos una ficha podia quedarse con un idioma que la instalacion
 * no ofrece por un camino y no por otro. Aqui esta el criterio una vez, y
 * {@see self::defaultLocale()} es el valor por defecto de todos.
 *
 * ## Por que se apoya en `NegotiableLocales` y no en `LocalePolicyProvider`
 *
 * Porque vive en el armazon (`App\Http`), que Deptrac no deja alcanzar ningun
 * modulo, y `NegotiableLocales` es el contrato que el armazon ya usa para lo
 * mismo: el middleware que negocia el idioma de cada respuesta. Lo implementa el
 * mismo adaptador de `Product` y con la misma memoria por peticion, asi que la
 * lista que valida y la lista con la que se responde no pueden divergir.
 *
 * **Sin forma de la etiqueta.** La lista es cerrada (`es`, `en`): lo que no esta
 * en ella es `422` con el mismo mensaje que `in:`. Quien declare ademas un
 * patron en el contrato —la hoja de instrucciones— lo añade a sus reglas.
 */
final readonly class ActiveLocale implements ValidationRule
{
    public function __construct(private NegotiableLocales $locales) {}

    /** La regla con los idiomas de la instalacion resueltos del contenedor. */
    public static function rule(): self
    {
        return new self(app(NegotiableLocales::class));
    }

    /** El idioma de la instalacion (`LOCALE_DEFAULT`), para quien no envia ninguno. */
    public static function defaultLocale(): string
    {
        return app(NegotiableLocales::class)->defaultLocale();
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! \is_string($value) || ! \in_array($value, $this->locales->availableLocales(), true)) {
            $fail('validation.in')->translate();
        }
    }
}
