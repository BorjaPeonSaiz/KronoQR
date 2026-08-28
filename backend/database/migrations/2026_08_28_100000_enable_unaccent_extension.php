<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `unaccent` — la busqueda libre de la plantilla ignora los acentos (RF-GP-01).
 *
 * **Por que hace falta.** `ILIKE` distingue diacriticos: `'Garcia' ILIKE
 * '%garcía%'` es falso, y tambien lo es al reves. Quien busca en el panel
 * escribe el apellido como le sale —con tilde o sin ella— y espera encontrar a
 * la persona igual. El panel de credenciales ya se comporta asi filtrando en el
 * navegador, y los dos cuadros llevan la misma etiqueta: si uno encuentra a
 * «Núñez» escribiendo «nunez» y el otro no, el que falla parece averiado.
 *
 * **Por que en una migracion aparte y no en
 * `enable_required_postgres_extensions`.** Aquella ya se ejecuto en las
 * instalaciones existentes, asi que ampliarla no habilitaria nada en ellas
 * (patron expand del doc 02 §10.4: se añade un paso, no se reescribe uno
 * pasado).
 *
 * **Por que tampoco esta en `infra/docker/postgres/initdb/00-extensions.sql`.**
 * Las tres de ese script son requisito para *crear* el esquema —sin
 * `btree_gist` no existe la restriccion de exclusion de RN-02—, asi que tienen
 * que estar antes de la primera migracion. `unaccent` no lo es: hace falta al
 * *consultar*, y esta migracion es su unica fuente. Con una sola fuente, el
 * `down()` puede deshacer de verdad lo que el `up()` hizo.
 *
 * **El rol de migracion puede crearla.** `fichaje_migrator` es el rol de
 * arranque del cluster y por tanto superusuario (ADR-033), pero ademas
 * `unaccent` esta marcada como *trusted* desde PostgreSQL 13: hasta el
 * propietario de la base, sin superusuario, puede instalarla. Una instalacion
 * que cree la base a mano con un rol propio tampoco se queda fuera.
 *
 * **Sin indice, a proposito.** `unaccent()` no es `IMMUTABLE` —depende del
 * diccionario y del `search_path`—, asi que ni siquiera podria indexarse
 * directamente sin envolverla. No hace falta: la plantilla de una instalacion
 * son cientos de filas (doc 02, Anexo A). Si algun dia fueran millones, la
 * palanca es `pg_trgm` con un indice GIN sobre una envoltura `IMMUTABLE`, no
 * partir la consulta.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        // `IF NOT EXISTS` y no un `SELECT` previo: entre la comprobacion y el
        // `CREATE` cabe otra migracion concurrente, y ademas una instalacion
        // puede traerla ya puesta por su administrador.
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    /**
     * Deshace exactamente lo que hizo `up()`, y esto si es reversible.
     *
     * Sin `CASCADE` a proposito: si algun dia algo depende de la extension —un
     * indice, una vista— el `DROP` falla en voz alta en vez de llevarselo por
     * delante. Revertir este paso deja la busqueda libre como estaba antes
     * (sensible a acentos), que es justo el codigo al que se vuelve.
     */
    public function down(): void
    {
        $this->limitLockWait();

        DB::statement('DROP EXTENSION IF EXISTS unaccent');
    }
};
