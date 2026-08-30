<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La invariante de la credencial activa pasa a ser **por empleado y por clave de
 * firma** (RF-QR-07, doc 02 §5.3, tarea 2.12).
 *
 * **Por que hay que tocarla.** La rotacion con solape necesita que la tarjeta
 * vieja siga fichando mientras se imprime y se entrega la nueva. Con
 * `one_active_credential_per_employee` —unico sobre `employee_id` con
 * `revoked_at IS NULL`— eso es imposible: reemitir obliga a revocar antes, y
 * revocar antes deja a esa persona sin poder fichar hasta que su tarjeta nueva
 * le llega a la mano, que son dias. Es la tension que ADR-034 dejo abierta por
 * escrito para esta tarea.
 *
 * **Que se declara en su lugar.** Dos indices parciales que juntos son mas
 * estrictos de lo que parecen:
 *
 * | Indice | Que impide |
 * |---|---|
 * | `one_pending_credential_per_employee` | Dos credenciales emitidas y sin imprimir a la vez. Es lo que hace que `credentials:issue` siga sin poder duplicar y que `credentials:rotate-key` sea **idempotente por construccion**: la segunda pasada choca con la fila que dejo la primera |
 * | `one_active_credential_per_key_and_employee` | Dos tarjetas **escaneables** del mismo empleado firmadas con la **misma** clave. Es la invariante del doc 01 §5.2 con el matiz que le faltaba: lo que no puede haber es dos tarjetas vivas indistinguibles en el tiempo, no dos actos de un relevo controlado |
 *
 * Como el llavero admite **como mucho dos** claves (`current` y `previous`,
 * el objeto `QrKeyring` de `Identity` — nombrado en prosa y no con `@see`,
 * porque una migracion no importa nada del dominio), el numero maximo de
 * tarjetas escaneables de una persona pasa de una a dos, y solo mientras dure
 * una rotacion: la vieja se revoca al **entregar** la nueva, que es lo que hace
 * que el recuento de la clave anterior baje hasta cero y se pueda retirar.
 *
 * **Relajacion de una restriccion, no una fase *contract*** (doc 02 §10.4). El
 * indice antiguo no se «deja de usar»: prohibe exactamente el estado que esta
 * funcionalidad necesita, asi que no puede convivir con los nuevos. Se crean
 * primero los dos que lo sustituyen y solo despues se retira el anterior, de
 * modo que en ningun instante la tabla queda sin invariante declarada. Todo lo
 * que el indice viejo aceptaba lo aceptan los nuevos, asi que ninguna fila
 * existente lo impide.
 *
 * **Sin `CONCURRENTLY`, con el mismo razonamiento que la migracion del `uuid`.**
 * `credentials` tiene una fila por empleado —cientos, no millones— y construir
 * un indice parcial sobre eso se mide en milisegundos con el `lock_timeout` bajo
 * de {@see LimitsMigrationLocks}. `CONCURRENTLY` obligaria a renunciar a la
 * transaccion y, si fallara a mitad, dejaria un indice **unico** en estado
 * `INVALID`: la invariante desaparecida sin que nada lo diga, que es el peor
 * desenlace posible para esta tabla en concreto.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        // 1. Como mucho una credencial pendiente de imprimir por empleado.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS one_pending_credential_per_employee
                ON credentials (employee_id)
                WHERE revoked_at IS NULL AND printed_at IS NULL
        SQL);

        // 2. Como mucho una tarjeta escaneable por empleado y clave de firma.
        //    `key_id` solo es NOT NULL en las impresas (ADR-034), asi que la
        //    condicion `printed_at IS NOT NULL` es redundante y se deja escrita
        //    igualmente: dice la intencion sin depender del CHECK.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS one_active_credential_per_key_and_employee
                ON credentials (employee_id, key_id)
                WHERE revoked_at IS NULL AND printed_at IS NOT NULL
        SQL);

        // 3. Y solo entonces se retira el que impedia el solape.
        DB::statement('DROP INDEX IF EXISTS one_active_credential_per_employee');
    }

    public function down(): void
    {
        $this->limitLockWait();

        // Volver atras **puede fallar, y debe**: si hay una rotacion a medias,
        // hay empleados con dos credenciales activas y el indice antiguo no las
        // admite. PostgreSQL dira exactamente eso. Ni se revoca nada por cuenta
        // propia —dejaria gente sin poder fichar— ni se borra ninguna fila
        // (regla dura 5): la decision es de quien opera, y el runbook
        // `rotacion-clave-qr.md` explica como cerrar la rotacion antes de
        // revertir.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS one_active_credential_per_employee
                ON credentials (employee_id)
                WHERE revoked_at IS NULL
        SQL);

        DB::statement('DROP INDEX IF EXISTS one_active_credential_per_key_and_employee');
        DB::statement('DROP INDEX IF EXISTS one_pending_credential_per_employee');
    }
};
