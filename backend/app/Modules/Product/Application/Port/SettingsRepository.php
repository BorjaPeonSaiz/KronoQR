<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\ValueObject\ResolvedSettings;
use App\Modules\Product\Domain\ValueObject\SettingValue;

/**
 * Lo guardado en `installation_settings`, visto desde el caso de uso
 * (RF-PD-01).
 *
 * **Es interno de `Product`** y por eso vive aqui y no en `Shared`: los demas
 * modulos no leen configuracion, reciben valores ya resueltos por un puerto
 * tipado (doc 02 §1.6, ADR-025). Si algun dia este puerto apareciera importado
 * desde otro modulo, la frontera se habria roto aunque Deptrac lo permitiera
 * por capas.
 *
 * **Habla en filas guardadas, no en configuracion resuelta.** La cascada la
 * resuelve {@see ResolvedSettings}, que es dominio puro y se prueba sin base de
 * datos. Un repositorio que devolviera
 * ya el valor efectivo se llevaria la regla a infraestructura, que es donde deja
 * de poder probarse y donde acaba duplicandose.
 *
 * Una interfaz con una sola implementacion se justifica porque es un puerto del
 * hexagono: la segunda implementacion es la de las pruebas (doc 02 §3.5).
 */
interface SettingsRepository
{
    /**
     * Todo lo guardado, con el JSONB ya decodificado, por clave.
     *
     * **Todas las filas, tambien las de claves que el catalogo no conozca**: la
     * cascada las anota como desconocidas y `product:doctor` las enseña. Un
     * repositorio que filtrara por el catalogo haria invisible una fila
     * sobrante, que es justo el sintoma de una actualizacion a medias.
     *
     * @return array<string, mixed>
     */
    public function storedValues(): array;

    /**
     * Lo mismo, **saltandose cualquier cache**.
     *
     * Existe por el camino de ESCRITURA y por nada mas. `UpdateSettings` lee el
     * estado actual dentro de la transaccion y bajo candado para poder decir el
     * valor anterior de cada clave en el asiento de auditoria y para comprobar
     * las invariantes entre claves contra lo que de verdad hay confirmado. Una
     * lectura cacheada ahi puede venir de antes del `COMMIT` del escritor
     * anterior —hay una ventana entre su confirmacion y la invalidacion de la
     * cache—, y el resultado seria un asiento que declara un valor anterior que
     * nunca rigio, o una invariante comprobada contra un estado que ya no existe.
     *
     * **No se usa en lectura.** El camino de fichaje pasa por
     * {@see self::storedValues()}, que es el que la cache protege: es el que se
     * ejecuta cincuenta veces por segundo.
     *
     * @return array<string, mixed>
     */
    public function storedValuesForWrite(): array;

    /**
     * Escribe estos valores, **todos o ninguno**.
     *
     * Un `PATCH` puede tocar varias claves cuyas invariantes se comprueban en
     * conjunto: escribir la mitad dejaria la instalacion en el estado que
     * {@see ResolvedSettings::with()} acaba de declarar imposible.
     *
     * `$actorUserId` es `users.id` —el mismo criterio que
     * `shift_corrections.performed_by_user_id`— y alimenta
     * `installation_settings.updated_by_user_id`. El asiento de `audit_log` lo
     * escribe el caso de uso, no el repositorio: auditar desde el adaptador
     * ataria la traza legal a que nadie escriba nunca por otra via.
     *
     * @param  list<SettingValue>  $values
     */
    public function save(array $values, int $actorUserId): void;
}
