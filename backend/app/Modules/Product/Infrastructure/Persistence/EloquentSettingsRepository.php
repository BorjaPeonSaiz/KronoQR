<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Persistence;

use App\Modules\Product\Application\Port\SettingsRepository;
use App\Modules\Product\Domain\ValueObject\ResolvedSettings;
use App\Modules\Product\Domain\ValueObject\SettingValue;
use App\Modules\Shared\Application\Port\Clock;
use JsonException;
use RuntimeException;

/**
 * `installation_settings` vista desde el caso de uso (RF-PD-01).
 *
 * Adaptador del puerto {@see SettingsRepository}: lee filas y escribe filas. **No
 * resuelve la cascada** —eso es {@see ResolvedSettings},
 * que es dominio puro y se prueba sin base de datos— y **no escribe en
 * `audit_log`** —eso lo hace el caso de uso, porque auditar desde el adaptador
 * ataria la traza legal a que nadie escriba nunca por otra via—.
 *
 * ## Devuelve todas las filas, tambien las que el catalogo no conoce
 *
 * Filtrar por `SettingKey::cases()` haria invisible una fila sobrante, que es
 * justo el sintoma de una actualizacion a medias o de una edicion a mano. La
 * cascada las anota en `unknownKeys` y `GET /api/v1/settings` las publica en
 * `meta.unknown_keys`.
 *
 * ## Una fila por clave
 *
 * Desde la migracion de contraccion `2026_09_05_100000` no hay `scope`: hay un
 * centro por instalacion (ADR-040) y la unicidad la impone
 * `one_setting_per_key`. Por eso el `UPSERT` de abajo puede apoyarse en `key` a
 * secas y no necesita razonar sobre indices parciales con `NULL`.
 *
 * ## El `UPSERT` es la garantia, no un `SELECT` previo
 *
 * Dos administradores guardando la misma pantalla a la vez es un caso normal, y
 * un `SELECT` seguido de `INSERT` los deja pasar a los dos: el segundo choca con
 * el indice unico y responde `500`. `ON CONFLICT (key) DO UPDATE` lo resuelve en
 * el motor, que es donde esta la restriccion.
 *
 * **La transaccion la abre el caso de uso, no este adaptador**: el asiento de
 * auditoria tiene que caer dentro de ella (regla dura 6, ADR-027). Un adaptador
 * que abriera la suya propia dejaria el cambio confirmado antes de que la traza
 * legal existiera.
 */
final readonly class EloquentSettingsRepository implements SettingsRepository
{
    public function __construct(private Clock $clock) {}

    /**
     * @return array<string, mixed>
     */
    public function storedValues(): array
    {
        $values = [];

        foreach (InstallationSetting::query()->get(['key', 'value']) as $row) {
            $key = $row->getAttribute('key');
            $json = $row->getAttribute('value');

            if (! is_string($key) || ! is_string($json)) {
                // Solo puede ocurrir si alguien cambia el tipo de las columnas
                // por debajo. Se dice en voz alta en lugar de seguir con un
                // valor que no es el que hay guardado.
                throw new RuntimeException(
                    'Una fila de `installation_settings` no tiene `key` y `value` como texto. '
                    .'Revisa el esquema: la columna `value` es JSONB y `key` es varchar(128).',
                );
            }

            $values[$key] = $this->decode($key, $json);
        }

        return $values;
    }

    /**
     * Sin cache que saltarse: esta clase no tiene ninguna.
     *
     * La distincion entre lectura normal y lectura de escritura vive entera en
     * el decorador de cache; aqui las dos consultas son la misma. Se implementa
     * igualmente porque el puerto la declara, y delegar es mas honesto que
     * duplicar la consulta.
     *
     * @return array<string, mixed>
     */
    public function storedValuesForWrite(): array
    {
        return $this->storedValues();
    }

    /**
     * @param  list<SettingValue>  $values
     */
    public function save(array $values, int $actorUserId): void
    {
        if ($values === []) {
            return;
        }

        // El instante entra por el puerto y no por `now()` (regla dura 2): sin
        // eso, «cuando se cambio este umbral» no se puede probar de forma
        // determinista, y esa fecha es la que se contrasta con `audit_log`.
        // Formateado aqui porque `upsert()` escribe en crudo y no pasa por los
        // casts del modelo; el desplazamiento explicito fija que es UTC
        // (regla dura 3).
        $updatedAt = $this->clock->now()->format('Y-m-d H:i:s.uP');

        $rows = [];

        foreach ($values as $value) {
            $rows[] = [
                'key' => $value->key->value,
                'value' => $this->encode($value),
                'updated_by_user_id' => $actorUserId,
                'updated_at' => $updatedAt,
            ];
        }

        InstallationSetting::query()->upsert(
            $rows,
            ['key'],
            ['value', 'updated_by_user_id', 'updated_at'],
        );
    }

    private function encode(SettingValue $value): string
    {
        try {
            return json_encode(
                $value->value(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $exception) {
            // Inalcanzable con el catalogo actual —enteros, cadenas UTF-8 y
            // listas de cadenas—, y aun asi se traduce: un `JsonException` suelto
            // en el camino de guardar la configuracion no le dice a nadie que
            // clave lo provoco.
            throw new RuntimeException(
                'No se ha podido serializar el valor de la clave de configuracion «'.$value->key->value.'».',
                previous: $exception,
            );
        }
    }

    /**
     * El JSONB de una fila, decodificado.
     *
     * **Un JSON corrupto falla y no se ignora.** Una fila ilegible puede estar
     * gobernando el calculo con algo que nadie ha visto; devolver el valor de
     * serie en su lugar convertiria un dato roto en una discrepancia de nomina
     * sin causa visible. El mensaje dice la clave, nunca el contenido.
     */
    private function decode(string $key, string $json): mixed
    {
        try {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'La fila de `installation_settings` con clave «'.$key.'» no contiene JSON valido. '
                .'Revisa si se ha editado a mano; el valor de serie de cada clave esta en '
                .'docs/cliente/configuracion.md.',
                previous: $exception,
            );
        }
    }
}
