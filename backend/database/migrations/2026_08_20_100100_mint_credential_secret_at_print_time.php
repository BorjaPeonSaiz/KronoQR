<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El token de la credencial se acuña **al imprimir**, no al emitir (ADR-034).
 *
 * **Por que la tabla tiene que cambiar.** `credentials` nacio con `key_id` y
 * `secret_hash` obligatorios, lo que obliga a acuñar el token en el mismo acto
 * que crea la fila. Como el token en claro no se guarda —y no debe guardarse—,
 * una credencial emitida el lunes no se podia imprimir el jueves: no quedaba
 * nada con lo que dibujar el QR. Eso deja sin base a `credentials:print`,
 * `credentials:print-batch --pending` y al estado «pendiente de imprimir» del
 * panel de RF-QR-08, que son de la tarea 1.10.
 *
 * A partir de aqui las dos columnas nacen `NULL` y las escribe la impresion,
 * junto con `printed_at`, en la misma sentencia. Una credencial pendiente de
 * imprimir **no es escaneable**: no hay hash por el que resolverla.
 *
 * **Los `NULL` no rompen `credentials_key_id_secret_hash_unique`.** Un indice
 * unico de PostgreSQL trata cada `NULL` como distinto de los demas (el
 * comportamiento por defecto, `NULLS DISTINCT`), asi que caben todas las
 * credenciales pendientes que haga falta y la unicidad sigue valiendo para las
 * impresas, que son las unicas que el fichaje busca. **No se debe declarar
 * `NULLS NOT DISTINCT` en este indice**: convertiria «pendiente de imprimir» en
 * un estado del que solo puede haber uno en toda la instalacion.
 *
 * **Expand, sin contract** (doc 02 §10.4). Relajar una restriccion `NOT NULL` no
 * invalida ninguna fila existente ni obliga a reescribir la tabla, y las
 * credenciales ya emitidas —que en toda instalacion existente estan impresas o
 * no existen— se marcan como impresas en el mismo paso para que cumplan la
 * restriccion nueva.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        // 1. Las dos columnas del QR pasan a ser opcionales: solo existen desde
        //    la impresion.
        DB::statement('ALTER TABLE credentials ALTER COLUMN key_id DROP NOT NULL');
        DB::statement('ALTER TABLE credentials ALTER COLUMN secret_hash DROP NOT NULL');

        // 2. Toda credencial que ya tuviera hash estaba, por definicion,
        //    acuñada: en el modelo anterior eso ocurria al emitirla. Se le da
        //    `printed_at` para que la restriccion nueva la acepte y para que el
        //    panel no la muestre eternamente «pendiente de imprimir» cuando su
        //    tarjeta lleva meses en un bolsillo.
        DB::statement(<<<'SQL'
            UPDATE credentials
               SET printed_at = issued_at
             WHERE secret_hash IS NOT NULL
               AND printed_at IS NULL
        SQL);

        // 3. Las tres marcas de la impresion van juntas o no van ninguna. Es la
        //    invariante que hace imposible una credencial escaneable que el
        //    panel siga contando como pendiente, y su reflejo exacto esta en el
        //    constructor del agregado `Credential`.
        DB::statement(<<<'SQL'
            ALTER TABLE credentials
                ADD CONSTRAINT credentials_chk_minted_at_print
                CHECK (
                    (key_id IS NULL AND secret_hash IS NULL AND printed_at IS NULL)
                    OR (key_id IS NOT NULL AND secret_hash IS NOT NULL AND printed_at IS NOT NULL)
                )
        SQL);

        // 4. La entrega lleva momento y responsable, y no puede preceder a la
        //    impresion: antes de imprimir no hay tarjeta que entregar (RF-QR-06).
        DB::statement(<<<'SQL'
            ALTER TABLE credentials
                ADD CONSTRAINT credentials_chk_delivery_is_signed
                CHECK (
                    (delivered_at IS NULL AND delivered_by_user_id IS NULL)
                    OR (
                        delivered_at IS NOT NULL
                        AND delivered_by_user_id IS NOT NULL
                        AND printed_at IS NOT NULL
                        AND delivered_at >= printed_at
                    )
                )
        SQL);
    }

    public function down(): void
    {
        $this->limitLockWait();

        DB::statement('ALTER TABLE credentials DROP CONSTRAINT IF EXISTS credentials_chk_delivery_is_signed');
        DB::statement('ALTER TABLE credentials DROP CONSTRAINT IF EXISTS credentials_chk_minted_at_print');

        // Volver a `NOT NULL` solo es posible si no queda ninguna credencial
        // pendiente de imprimir. **No se inventa un hash para rellenarlas ni se
        // borran**: una credencial sin token no se puede reconstruir, y borrar
        // filas de esta tabla es lo que la regla dura 5 prohibe. Si la reversion
        // falla aqui, el mensaje de PostgreSQL dice exactamente lo que pasa y la
        // decision —revocar esas credenciales o quedarse en esta version— es de
        // quien opera, no de una migracion.
        DB::statement('ALTER TABLE credentials ALTER COLUMN key_id SET NOT NULL');
        DB::statement('ALTER TABLE credentials ALTER COLUMN secret_hash SET NOT NULL');
    }
};
