<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Identity\Domain\Model\Credential;
use App\Modules\Shared\Application\Port\LocalePolicyProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * `GET /api/v1/credentials/instructions-sheet` — la hoja que se entrega con la
 * tarjeta (tarea 5.11b, **RL-05**).
 *
 * **Un solo campo, y opcional.** `locale` tiene que ser uno de los idiomas
 * **activos de la instalacion** (`LOCALE_AVAILABLE`); sin el, se usa
 * `LOCALE_DEFAULT`. Cualquier otra cosa es `422` y no una hoja en un idioma
 * aproximado: una hoja con la mitad de las frases sin traducir es peor que un
 * error, porque se imprime y se entrega sin que nadie la lea entera.
 *
 * **Las dos comprobaciones del idioma son distintas y las dos hacen falta.** El
 * patron `^[a-z]{2}$` es el del contrato —lo que el cliente generado puede
 * enviar— y la lista cerrada es la de ESTA instalacion, que cambia desde el
 * panel sin desplegar nada (regla dura 13). Sin la primera, un `locale` con
 * cualquier basura llegaria a comparar contra la lista; sin la segunda, un
 * idioma bien formado que la instalacion no ofrece imprimiria una hoja vacia.
 *
 * **Es un `GET`, al reves que `print`**, y por eso este `FormRequest` no tiene
 * `toCommand()`: no hay nada que mandar hacer. La hoja no acuña ningun QR, no
 * cambia el estado de nada y es el mismo documento para toda la plantilla
 * (ficha 5.11b, decision 1). Lo que si comparte con `print` es **quien puede
 * pedirla**: la misma policy y el mismo ambito `credentials:*`.
 */
final class InstructionsSheetRequest extends FormRequest
{
    use RejectsUnknownInput;

    public function authorize(): bool
    {
        return Gate::allows('printInstructionsSheet', Credential::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'locale' => [
                'sometimes',
                'string',
                // El mismo patron que declara el contrato: dos letras minusculas.
                'regex:/^[a-z]{2}$/',
                Rule::in($this->availableLocales()),
            ],
        ];
    }

    public function requestedLocale(): ?string
    {
        $locale = $this->query('locale');

        return \is_string($locale) && $locale !== '' ? $locale : null;
    }

    /**
     * Los idiomas activos de la instalacion.
     *
     * Se resuelve del contenedor —como hace `UpdateSettingsRequest` con el
     * inspector de logotipos— y no por inyeccion en el constructor: el trait que
     * rechaza campos desconocidos llama a `rules()` por su cuenta, asi que este
     * metodo tiene que poder ejecutarse sin argumentos.
     *
     * @return list<string>
     */
    private function availableLocales(): array
    {
        return app(LocalePolicyProvider::class)->current()->available;
    }
}
