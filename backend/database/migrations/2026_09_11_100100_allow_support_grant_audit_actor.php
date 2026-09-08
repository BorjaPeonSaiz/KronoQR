<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `audit_log.actor_type` admite `support_grant` (**RF-PD-11**, RL-18, ADR-020,
 * tarea 5.9).
 *
 * ## Que problema resuelve
 *
 * Conceder, usar y revocar un acceso de soporte dejan asiento (regla dura 6), y
 * el que describe un **uso** tiene un actor que no es ninguno de los cuatro que
 * el catalogo conocia: no es una cuenta de `users` —el fabricante no tiene
 * cuenta en la instalacion, y ADR-020 existe para que no la tenga—, no es un
 * quiosco, no es el scheduler y no es el rol de mantenimiento. Es **la propia
 * concesion**, con su `id` en `actor_id`, que es lo que permite responder «¿que
 * hizo el acceso que concedi el martes?» con un filtro por columna indexada.
 *
 * Sin esta migracion, el primer asiento de `support_grant.used` chocaria contra
 * `audit_log_chk_actor_type` y —por la regla dura 6, que hace sincrono el
 * asiento— tumbaria la peticion que lo produjo.
 *
 * ## Patron `/migracion-segura`: expand puro, en tres pasos
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Esta migracion: el `CHECK` pasa a admitir un valor **mas**. Ninguna fila existente deja de cumplirlo. | La version anterior nunca escribe `support_grant`, asi que no nota nada. |
 * | **2 (migrate)** | *No aplica.* No hay filas que trasladar: el valor nuevo no lo ha escrito nadie todavia. | — |
 * | **3 (contract)** | *No aplica.* Un `CHECK` ampliado no deja nada obsoleto detras. | — |
 *
 * **Es compatible en los dos sentidos**, que es lo que lo hace desplegable sin
 * parada: la version anterior del codigo sigue funcionando con el `CHECK` nuevo
 * —solo escribe los cuatro valores de siempre, todos admitidos— y la version
 * nueva no puede arrancar contra el `CHECK` viejo, por eso la migracion va
 * primero.
 *
 * ## `DROP` + `ADD ... NOT VALID` + `VALIDATE`, y no un `ADD` a secas
 *
 * PostgreSQL no sabe «ampliar» un `CHECK`: hay que soltar el viejo y poner el
 * nuevo. Las tres sentencias son deliberadas y cada una tiene su motivo:
 *
 * 1. **`DROP CONSTRAINT`** toma `ACCESS EXCLUSIVE` sobre `audit_log`, pero solo
 *    para tocar el catalogo: no recorre ni una fila y dura microsegundos. Con el
 *    `lock_timeout` de 3 s del trait, si hay una transaccion larga por delante la
 *    migracion se rinde en lugar de encolar a todo el que llegue detras.
 * 2. **`ADD CONSTRAINT ... NOT VALID`** tampoco recorre la tabla: desde ese
 *    momento el `CHECK` rige para lo que se escriba, y lo ya escrito queda sin
 *    verificar. Es lo que evita el `ACCESS EXCLUSIVE` largo sobre una tabla que
 *    en una instalacion de cuatro años tiene millones de filas particionadas.
 * 3. **`VALIDATE CONSTRAINT`** recorre la tabla, si, pero con `SHARE UPDATE
 *    EXCLUSIVE`: **no bloquea escrituras**, asi que el fichaje sigue su camino
 *    mientras valida. Se hace en la misma migracion porque el `CHECK` nuevo es
 *    mas permisivo que el que acaba de soltarse: **toda fila existente lo cumple
 *    por construccion**, y dejarlo `NOT VALID` seria dejar la restriccion a
 *    medias sin ganar nada.
 *
 * `audit_log` esta particionada por rango (ADR-027). `ALTER TABLE` sobre la
 * tabla madre propaga a sus particiones, que es el comportamiento que se quiere:
 * un `CHECK` que rigiera en la madre y no en las hijas no rige en nada.
 *
 * ## `down()`
 *
 * Vuelve al `CHECK` de cuatro valores. **Y puede fallar a proposito**: si para
 * entonces existe algun asiento con `actor_type = 'support_grant'`, la
 * validacion lo rechaza y la reversion se detiene. Es lo correcto y no un
 * defecto: `audit_log` es solo-apendice y el usuario de la aplicacion no tiene
 * `UPDATE` ni `DELETE` sobre ella (regla dura 6), asi que la alternativa —dejar
 * el `CHECK` viejo `NOT VALID` para que entre igual— seria una restriccion que
 * miente. Quien revierta con asientos de soporte ya escritos tiene que decidir
 * a mano que hace con ellos, que es exactamente la conversacion que debe ocurrir.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    private const string CONSTRAINT = 'audit_log_chk_actor_type';

    public function up(): void
    {
        $this->limitLockWait();

        DB::statement('ALTER TABLE audit_log DROP CONSTRAINT '.self::CONSTRAINT);

        DB::statement(<<<'SQL'
            ALTER TABLE audit_log
                ADD CONSTRAINT audit_log_chk_actor_type
                CHECK (actor_type IN ('user', 'device', 'system', 'maintenance', 'support_grant'))
                NOT VALID
        SQL);

        DB::statement('ALTER TABLE audit_log VALIDATE CONSTRAINT '.self::CONSTRAINT);
    }

    public function down(): void
    {
        $this->limitLockWait();

        DB::statement('ALTER TABLE audit_log DROP CONSTRAINT '.self::CONSTRAINT);

        DB::statement(<<<'SQL'
            ALTER TABLE audit_log
                ADD CONSTRAINT audit_log_chk_actor_type
                CHECK (actor_type IN ('user', 'device', 'system', 'maintenance'))
                NOT VALID
        SQL);

        // Se valida a proposito: si hay asientos de soporte escritos, esto falla
        // y la reversion se detiene en lugar de dejar una restriccion que no
        // describe el contenido de la tabla. Ver el docblock.
        DB::statement('ALTER TABLE audit_log VALIDATE CONSTRAINT '.self::CONSTRAINT);
    }
};
