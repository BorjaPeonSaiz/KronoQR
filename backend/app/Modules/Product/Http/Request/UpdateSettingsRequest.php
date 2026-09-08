<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Request;

use App\Http\Requests\RejectsUnknownInput;
use App\Modules\Product\Application\Command\UpdateSettingsCommand;
use App\Modules\Product\Application\Port\LogoInspector;
use App\Modules\Product\Domain\ValueObject\ResolvedSettings;
use App\Modules\Product\Domain\ValueObject\SettingDefinition;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Product\Domain\ValueObject\SettingType;
use App\Modules\Product\Domain\ValueObject\SettingValue;
use App\Modules\Shared\Application\Port\ManagementActor;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * `PATCH /api/v1/settings` (contrato `UpdateSettingsRequest`).
 *
 * ## Las reglas SE DERIVAN del catalogo, no se escriben aqui
 *
 * Cada clave tiene su tipo, su rango, su longitud y su conjunto de valores
 * admitidos en {@see SettingDefinition}, que es dominio puro. Este `FormRequest`
 * los traduce a reglas de Laravel; no decide ninguno. Es lo que evita la segunda
 * fuente de verdad que se desincroniza: añadir una clave al catalogo la valida
 * aqui sin tocar este fichero, y cambiar un maximo lo cambia en los dos sitios a
 * la vez.
 *
 * **Y no sustituye a la validacion del dominio.** {@see SettingValue::of()}
 * vuelve a comprobar lo mismo dentro del caso de uso, y debe hacerlo: hay
 * caminos que no pasan por HTTP —una consola, el instalador— y la garantia no
 * puede depender de por donde se entre. Lo que aporta esta capa es el **mensaje
 * util**: un `422` con el error colgado de `settings.<CLAVE>` y el texto en el
 * idioma negociado, en vez de una excepcion con un mensaje fijo.
 *
 * ## Una clave desconocida es `422`, no se ignora
 *
 * `RejectsUnknownInput` cubre el primer nivel —un campo suelto junto a
 * `settings`—, pero las claves van **dentro** de `settings` y ahi no llega: lo
 * hace {@see self::rejectUnknownSettingKeys()}. Aceptarlas produciria una fila
 * que nadie lee: el cliente creeria haber configurado un umbral y el sistema
 * seguiria aplicando el valor de serie. Un ajuste que se guarda y no hace nada
 * es peor que un error.
 *
 * ## Lo que NO se comprueba aqui
 *
 * Las invariantes **entre claves** —que el idioma por defecto este entre los
 * disponibles—. Dependen de lo que ya hay guardado y no solo de lo que llega:
 * un `PATCH` que solo cambia `LOCALE_AVAILABLE` puede dejar huerfano un
 * `LOCALE_DEFAULT` que no viaja en la peticion. Las comprueba
 * {@see ResolvedSettings::with()} sobre el conjunto resuelto, antes de tocar la
 * base de datos, y salen tambien como `422`.
 *
 * ## La UNICA comprobacion que mira fuera del catalogo: el fichero del logotipo
 *
 * `BRANDING_LOGO_PATH` es la excepcion, y esta razonada (tarea 5.8, RF-PD-08).
 * El catalogo puede decir que es texto de hasta 512 caracteres, y nada mas: si
 * ese texto apunta a un fichero que existe, que es una imagen y que esta dentro
 * del directorio de marca es una pregunta sobre el DISCO, y el dominio no tiene
 * —ni debe tener— forma de responderla.
 *
 * Se comprueba **al guardar** y no al leer por dos motivos que apuntan en la
 * misma direccion. El primero es de producto: aqui hay una persona delante del
 * panel a la que se le puede decir que arreglar, mientras que al imprimir una
 * tarjeta lo unico que cabe es seguir sin logotipo. El segundo es de seguridad:
 * `GET /api/v1/branding/logo` es **publico**, asi que sin esta guarda una ruta
 * guardada desde el panel convertiria ese endpoint en una lectura de cualquier
 * fichero del servidor.
 *
 * Quien decide es {@see LogoInspector}, el mismo puerto —y la misma
 * implementacion— que usa el lector del logotipo: si fueran dos comprobaciones
 * distintas, el panel aceptaria un fichero que el endpoint publico no sirve y
 * nadie sabria por que la cabecera sale vacia.
 *
 * ## El autor no se declara, se toma de la sesion
 *
 * Aceptarlo en el cuerpo permitiria firmar un cambio de umbral de calculo a
 * nombre de otra persona. `RejectsUnknownInput` ademas rechaza el intento.
 */
final class UpdateSettingsRequest extends FormRequest
{
    use RejectsUnknownInput {
        withValidator as private rejectUnknownInput;
    }

    public function authorize(): bool
    {
        return Gate::allows('update', ResolvedSettings::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = ['settings' => ['required', 'array', 'min:1']];

        foreach ($this->submittedKeys() as $name) {
            $key = SettingKey::tryFrom($name);

            if (! $key instanceof SettingKey) {
                // Sin reglas: lo rechaza `rejectUnknownSettingKeys()` con un
                // mensaje que dice cual es la clave y que el catalogo es cerrado.
                continue;
            }

            $rules += $this->rulesFor($key);
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectUnknownInput($validator);

        $validator->after(function (Validator $validator): void {
            $this->rejectUnknownSettingKeys($validator);
            $this->rejectUnusableLogo($validator);
        });
    }

    /**
     * Comprueba contra el disco la ruta del logotipo, si es que viene una.
     *
     * **La cadena vacia no se comprueba**: significa «el logotipo del producto»
     * y es la forma de volver al valor de serie. Comprobarla daria un `422` a
     * quien esta quitando su logotipo, que es justo lo contrario de lo que
     * quiere.
     *
     * Va en un `after` y no en una regla de la clave porque necesita el valor ya
     * validado como texto: sin eso, un `BRANDING_LOGO_PATH` numerico llegaria al
     * inspector antes de que nadie hubiera dicho que ni siquiera es una cadena, y
     * el `422` hablaria del fichero en vez de hablar del tipo.
     */
    private function rejectUnusableLogo(Validator $validator): void
    {
        if ($validator->errors()->has('settings.'.SettingKey::BRANDING_LOGO_PATH->value)) {
            // Ya hay un error de forma sobre esta clave. Añadir el del fichero
            // encima solo enseña dos mensajes para un unico problema.
            return;
        }

        $path = $this->input('settings.'.SettingKey::BRANDING_LOGO_PATH->value);

        if (! is_string($path) || trim($path) === '') {
            return;
        }

        $inspection = $this->logoInspector()->inspect($path);

        if ($inspection->isAccepted()) {
            return;
        }

        $message = __('settings.logo.'.$inspection->rejection()->value, [
            'root' => Config::string('branding.logo_root'),
            'max_kib' => (int) round(Config::integer('branding.logo_max_bytes') / 1024),
            'max_pixels' => Config::integer('branding.logo_max_dimension'),
        ]);

        $validator->errors()->add(
            'settings.'.SettingKey::BRANDING_LOGO_PATH->value,
            is_string($message) ? $message : 'The configured logo file cannot be used.',
        );
    }

    /**
     * El inspector, resuelto por el contenedor.
     *
     * Se pide aqui y no por el constructor porque un `FormRequest` se construye
     * en cada peticion —incluidas las que no tocan la marca— y su firma la fija
     * Laravel. Los tres limites los inyecta el proveedor del modulo, que es donde
     * se lee la configuracion.
     */
    private function logoInspector(): LogoInspector
    {
        return app(LogoInspector::class);
    }

    /**
     * Nombres legibles para los mensajes, en el idioma negociado.
     *
     * Sin esto, un `422` diria «El campo settings.ATTENDANCE_DEBOUNCE_SECONDS
     * debe ser un numero entero», que es el nombre de la columna y no lo que el
     * administrador ve en la pantalla.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $attributes = [];

        foreach (SettingKey::cases() as $key) {
            $label = __('settings.attributes.'.$key->value);

            if (is_string($label)) {
                $attributes['settings.'.$key->value] = $label;
                $attributes['settings.'.$key->value.'.*'] = $label;
            }
        }

        return $attributes;
    }

    public function toCommand(): UpdateSettingsCommand
    {
        /** @var array<string, mixed> $values */
        $values = $this->array('settings');

        return new UpdateSettingsCommand(
            values: $values,
            actorUserId: $this->actorUserId(),
        );
    }

    /**
     * Las claves que el catalogo no declara, señaladas una a una.
     */
    private function rejectUnknownSettingKeys(Validator $validator): void
    {
        foreach ($this->submittedKeys() as $name) {
            if (SettingKey::tryFrom($name) instanceof SettingKey) {
                continue;
            }

            $message = __('settings.unknown_key', ['key' => $name]);

            $validator->errors()->add(
                'settings.'.$name,
                is_string($message) ? $message : 'Unknown setting key: '.$name.'.',
            );
        }
    }

    /**
     * Las reglas de una clave, derivadas de su definicion.
     *
     * @return array<string, list<mixed>>
     */
    private function rulesFor(SettingKey $key): array
    {
        $definition = $key->definition();
        $field = 'settings.'.$key->value;

        return match ($definition->type) {
            SettingType::INTEGER => [$field => $this->integerRules($definition)],
            SettingType::TEXT => [$field => $this->textRules($definition)],
            SettingType::TEXT_LIST => [
                $field => ['required', 'array', 'min:1'],
                $field.'.*' => $this->itemRules($definition),
            ],
        };
    }

    /**
     * @return list<mixed>
     */
    private function integerRules(SettingDefinition $definition): array
    {
        // `integer` de Laravel acepta «12» entrecomillado, porque valida con
        // `filter_var` y ahi una cadena numerica es un entero. El dominio no lo
        // acepta —y hace bien: un JSON escrito a mano con comillas convertiria
        // un error de configuracion en un umbral silenciosamente distinto del que
        // se cree haber puesto—, asi que la comprobacion estricta va aqui tambien
        // para que el `422` señale la clave en vez de salir por la excepcion
        // generica del dominio.
        $rules = [
            'required',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_int($value)) {
                    $fail('settings.strict_integer')->translate();
                }
            },
            'integer',
        ];

        if ($definition->minimum !== null) {
            $rules[] = 'min:'.$definition->minimum;
        }

        if ($definition->maximum !== null) {
            $rules[] = 'max:'.$definition->maximum;
        }

        return $rules;
    }

    /**
     * @return list<mixed>
     */
    private function textRules(SettingDefinition $definition): array
    {
        // `present` y no `required`: la ruta del logotipo admite la cadena
        // vacia, y `required` la rechazaria. Lo que no puede faltar es la clave,
        // porque quien la manda esta diciendo que quiere cambiarla.
        $rules = $definition->allowsEmpty ? ['present', 'string'] : ['required', 'string', 'filled'];

        if ($definition->maximumLength > 0) {
            $rules[] = 'max:'.$definition->maximumLength;
        }

        if ($definition->pattern !== null) {
            $rules[] = 'regex:'.$definition->pattern;
        }

        if ($definition->allowed !== null) {
            $rules[] = Rule::in($definition->allowed);
        }

        return $rules;
    }

    /**
     * @return list<mixed>
     */
    private function itemRules(SettingDefinition $definition): array
    {
        $rules = ['string', 'filled', 'distinct'];

        if ($definition->allowed !== null) {
            $rules[] = Rule::in($definition->allowed);
        }

        return $rules;
    }

    /**
     * Las claves que llegan dentro de `settings`, tal como vienen.
     *
     * @return list<string>
     */
    private function submittedKeys(): array
    {
        $settings = $this->input('settings');

        if (! is_array($settings)) {
            return [];
        }

        // `(string)` porque una clave JSON numerica —`{"12": 1}`— llega a PHP
        // como `int`. No es un caso real del catalogo, pero sin la conversion el
        // tipo se ensucia y el mensaje del `422` saldria sin nombre de clave.
        return array_map(
            static fn (int|string $name): string => (string) $name,
            array_keys($settings),
        );
    }

    /**
     * `users.id` de quien firma, tomado de la sesion autenticada, o `null` si no
     * hay ninguna persona de la organizacion detras.
     *
     * ## `null` es un caso real desde la tarea 5.9
     *
     * Un **acceso de soporte** con alcance `configuration` cambia ajustes y no
     * es una cuenta de `users`: el fabricante no tiene cuenta en la instalacion
     * y no puede tenerla (ADR-020, regla dura 16). Su identificador es el de la
     * concesion, y escribirlo en `installation_settings.updated_by_user_id`
     * apuntaria una clave ajena de `users` a una fila que no existe — que es
     * exactamente el `23503` con el que se descubrio.
     *
     * Se escribe `null`, que es la verdad. **Quien lo hizo no se pierde**: el
     * asiento de `audit_log` lleva `actor_type = support_grant` con el
     * identificador de la concesion, y ahi es donde tiene valor probatorio.
     * Mismo criterio que `UpdateComplianceProfileRequest` y que la consola.
     */
    private function actorUserId(): ?int
    {
        $actor = $this->user();

        if ($actor instanceof ManagementActor && $actor->isSupportActor()) {
            return null;
        }

        $identifier = $actor?->getAuthIdentifier();

        return is_numeric($identifier) ? (int) $identifier : null;
    }
}
