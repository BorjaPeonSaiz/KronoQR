<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `rejected_out_of_order` se suma a los resultados que **no** guardan acumulado
 * (**RN-18**, doc 01 §5.5, tarea ad hoc del 18-09-2026).
 *
 * ## Que problema resuelve
 *
 * `scan_events_chk_worked_minutes` no es una lista de permitidos sino una
 * **equivalencia**: `(result IN (...)) = (worked_minutes IS NULL)`. Dice dos
 * cosas a la vez —los rechazos de verdad no llevan acumulado y todo lo demas si,
 * el anti-rebote incluido (ADR-031)— y por eso añadir un rechazo nuevo sin
 * tocarla no la deja «igual de estricta»: la vuelve **imposible de cumplir**. El
 * fichaje irreconciliable responde el `422` generico de RS-03, que no lleva
 * acumulado que reconstruir, asi que su fila nace con `worked_minutes` nulo y
 * chocaria contra la mitad derecha de la equivalencia.
 *
 * Va en una migracion propia y no junto a la de `scan_events_chk_result` porque
 * son dos afirmaciones distintas sobre el esquema —«que valores existen» y «que
 * forma tiene la fila de cada uno»— y revertir una no tiene por que implicar
 * revertir la otra.
 *
 * ## Patron `/migracion-segura`: expand, en tres pasos
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Esta migracion: un valor mas en la lista de los que exigen `worked_minutes IS NULL`. Ninguna fila existente cambia de veredicto: el valor nuevo no lo ha escrito nadie. | La version anterior no escribe ese resultado y no nota nada. |
 * | **2 (migrate)** | *No aplica.* | — |
 * | **3 (contract)** | *No aplica.* | — |
 *
 * Se despliega **antes** que el codigo, junto con la ampliacion del catalogo: las
 * dos son condicion para que el primer escaneo de RN-18 se pueda escribir.
 *
 * ## `DROP` + `ADD ... NOT VALID` + `VALIDATE`
 *
 * El mismo patron y por los mismos motivos que en la migracion anterior:
 * `DROP CONSTRAINT` solo toca el catalogo, `NOT VALID` evita el recorrido con
 * `ACCESS EXCLUSIVE` sobre una tabla que en una instalacion de cuatro años tiene
 * millones de filas, y `VALIDATE` la recorre sin bloquear escrituras. Se valida
 * aqui mismo porque **toda fila existente cumple ya la restriccion nueva**: el
 * unico valor que cambia de lado no lo ha escrito nadie todavia.
 *
 * ## `down()` verificado
 *
 * Restaura exactamente la lista de tres. Si para entonces hay filas
 * `rejected_out_of_order` —que por construccion tienen `worked_minutes` nulo—,
 * la validacion **falla**: con el `CHECK` viejo esas filas tendrian que llevar
 * acumulado. Es correcto que se detenga ahi. La reversion de verdad es la de la
 * migracion anterior, que quita el valor del catalogo, y las dos vuelven atras en
 * orden inverso.
 *
 * Se prueba en `tests/Integration/Schema/OutOfOrderScanMigrationsTest.php`,
 * ademas del ciclo completo de `MigrationsRoundTripTest`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    private const string CONSTRAINT = 'scan_events_chk_worked_minutes';

    public function up(): void
    {
        $this->limitLockWait();

        DB::statement('ALTER TABLE scan_events DROP CONSTRAINT '.self::CONSTRAINT);

        DB::statement(<<<'SQL'
            ALTER TABLE scan_events
                ADD CONSTRAINT scan_events_chk_worked_minutes
                CHECK (
                    (result IN (
                        'rejected_unknown', 'rejected_revoked', 'rejected_signature',
                        'rejected_out_of_order'
                    )) = (worked_minutes IS NULL)
                )
                NOT VALID
        SQL);

        DB::statement('ALTER TABLE scan_events VALIDATE CONSTRAINT '.self::CONSTRAINT);
    }

    public function down(): void
    {
        $this->limitLockWait();

        DB::statement('ALTER TABLE scan_events DROP CONSTRAINT '.self::CONSTRAINT);

        DB::statement(<<<'SQL'
            ALTER TABLE scan_events
                ADD CONSTRAINT scan_events_chk_worked_minutes
                CHECK (
                    (result IN ('rejected_unknown', 'rejected_revoked', 'rejected_signature')) = (worked_minutes IS NULL)
                )
                NOT VALID
        SQL);

        // Ver el docblock: con filas de RN-18 escritas esto falla, y detenerse es
        // lo correcto.
        DB::statement('ALTER TABLE scan_events VALIDATE CONSTRAINT '.self::CONSTRAINT);
    }
};
