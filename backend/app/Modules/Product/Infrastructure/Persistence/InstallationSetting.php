<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

/**
 * Modelo Eloquent de `installation_settings` (RF-PD-01).
 *
 * **Sin una sola regla dentro** (doc 02 §3.5: nada de logica de negocio en los
 * modelos). Que valores admite cada clave, cual es el valor de serie y que pasa
 * cuando cambia lo dice `Product/Domain/ValueObject/SettingDefinition`, que es
 * dominio puro y se prueba sin base de datos. Aqui solo esta la tabla, sus tipos
 * y el hecho de que no tiene `created_at`.
 *
 * **No sale de esta capa.** Lo usa {@see EloquentSettingsRepository} y nadie
 * mas: el resto del sistema habla en `SettingValue` y en `ResolvedSettings`.
 * Deptrac lo verifica —`ProductApplication` no alcanza `ProductPersistence`—,
 * pero el motivo no es la herramienta: un modelo Eloquent que viaja hacia arriba
 * arrastra el ORM a los casos de uso y convierte cada prueba de una regla en una
 * prueba con base de datos.
 *
 * **Una fila por clave desde la tarea 5.1**: `scope` y `scope_id` se retiraron
 * con la migracion de contraccion `2026_09_05_100000`, porque hay un centro por
 * instalacion (ADR-040) y un ambito que siempre resuelve al mismo sitio no es
 * una cascada. La unicidad la garantiza `one_setting_per_key`.
 */
final class InstallationSetting extends Model
{
    protected $table = 'installation_settings';

    /**
     * La tabla tiene `updated_at` y **no** `created_at`: una clave de
     * configuracion no tiene fecha de nacimiento interesante —la interesante es
     * la del ultimo cambio, y quien lo hizo—, y el historico completo esta en
     * `audit_log`, que es donde tiene valor probatorio.
     */
    public const UPDATED_AT = 'updated_at';

    public const CREATED_AT = null;

    protected $guarded = [];

    /**
     * **`value` no se castea a `array` a proposito.** La columna es `JSONB` y lo
     * que hay dentro puede ser un entero (`12`), una cadena (`"es"`) o una lista
     * (`["es","en"]`): el cast `array` de Eloquent devolveria un `int` cuando el
     * tipo declarado dice `array`, y esa mentira de tipo se propaga a todo lo que
     * lo lea. La codificacion y la decodificacion son explicitas y estan en un
     * solo sitio, {@see EloquentSettingsRepository}, con `JSON_THROW_ON_ERROR`.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'updated_by_user_id' => 'integer',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
