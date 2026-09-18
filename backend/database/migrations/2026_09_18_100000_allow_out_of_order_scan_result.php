<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `scan_events.result` admite `rejected_out_of_order` (**RN-18**, doc 01 §5.5,
 * tarea ad hoc del 18-09-2026).
 *
 * ## Que problema resuelve
 *
 * Un escaneo cuya hora real **no es posterior** a la entrada del turno abierto no
 * puede producir tramo —RN-03 exige salida estrictamente posterior— y tampoco es
 * un fallo pasajero: reintentarlo da siempre lo mismo. Antes de RN-18 la
 * transaccion revertia, el lote devolvia `503` y el quiosco lo reenviaba
 * indefinidamente, de modo que **de un fichaje real de una persona no quedaba ni
 * una linea** (regla dura 19). Ahora se registra con resultado propio.
 *
 * Sin esta migracion, el primer escaneo asi chocaria contra
 * `scan_events_chk_result` y tumbaria la peticion que lo produjo.
 *
 * ## Patron `/migracion-segura`: expand puro, en tres pasos
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Esta migracion: el `CHECK` admite un valor **mas**. Ninguna fila existente deja de cumplirlo. | La version anterior nunca escribe `rejected_out_of_order`: no nota nada. |
 * | **2 (migrate)** | *No aplica.* No hay filas que trasladar: el valor nuevo no lo ha escrito nadie todavia. | — |
 * | **3 (contract)** | *No aplica.* Un `CHECK` ampliado no deja nada obsoleto detras. | — |
 *
 * **Compatible en los dos sentidos**, que es lo que la hace desplegable sin
 * parada: la version anterior del codigo funciona con el `CHECK` nuevo —solo
 * escribe los ocho valores de siempre— y la nueva no puede arrancar contra el
 * viejo, por eso la migracion va primero. Y las colas de los quioscos que
 * llevaran dias reintentando un elemento imposible se vacian solas en el
 * siguiente reintento: recibiran el `422` de RN-18 en vez del `503`.
 *
 * ## `DROP` + `ADD ... NOT VALID` + `VALIDATE`
 *
 * PostgreSQL no sabe «ampliar» un `CHECK`: hay que soltar el viejo y poner el
 * nuevo. `DROP CONSTRAINT` toma `ACCESS EXCLUSIVE` pero solo para tocar el
 * catalogo —no recorre ni una fila—, y con el `lock_timeout` de 3 s del trait la
 * migracion se rinde antes que encolar detras de una transaccion larga.
 * `ADD ... NOT VALID` tampoco recorre la tabla: rige desde ese momento para lo
 * que se escriba. `VALIDATE CONSTRAINT` si la recorre, pero con `SHARE UPDATE
 * EXCLUSIVE`, que **no bloquea escrituras**: el fichaje sigue su camino mientras
 * valida. Se valida en la misma migracion porque el `CHECK` nuevo es mas
 * permisivo que el que acaba de soltarse y **toda fila existente lo cumple por
 * construccion**; dejarlo `NOT VALID` seria dejar la restriccion a medias sin
 * ganar nada.
 *
 * ## `down()` verificado
 *
 * Vuelve al `CHECK` de ocho valores, y **puede fallar a proposito**: si para
 * entonces hay algun escaneo registrado como `rejected_out_of_order`, la
 * validacion lo rechaza y la reversion se detiene. Es lo correcto —`scan_events`
 * es un log inmutable y nada se borra (regla dura 5)—; la alternativa, dejar el
 * `CHECK` viejo `NOT VALID`, seria una restriccion que miente sobre el contenido
 * de la tabla. Quien revierta con fichajes irreconciliables ya registrados tiene
 * que decidir a mano que hace con ellos.
 *
 * Se prueba en `tests/Integration/Schema/OutOfOrderScanMigrationsTest.php`,
 * ademas del ciclo completo de `MigrationsRoundTripTest`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    private const string CONSTRAINT = 'scan_events_chk_result';

    /**
     * Los valores se escriben literalmente y no se importan de `ScanResult`: una
     * migracion describe el esquema **en el momento en que se escribio**, y si
     * leyera una clase de la aplicacion, editar esa clase cambiaria lo que hizo
     * una migracion ya ejecutada en el servidor de un cliente.
     */
    public function up(): void
    {
        $this->limitLockWait();

        DB::statement('ALTER TABLE scan_events DROP CONSTRAINT '.self::CONSTRAINT);

        DB::statement(<<<'SQL'
            ALTER TABLE scan_events
                ADD CONSTRAINT scan_events_chk_result
                CHECK (result IN (
                    'clock_in', 'clock_out', 'break_start', 'break_end',
                    'rejected_unknown', 'rejected_revoked', 'rejected_debounce', 'rejected_signature',
                    'rejected_out_of_order'
                ))
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
                ADD CONSTRAINT scan_events_chk_result
                CHECK (result IN (
                    'clock_in', 'clock_out', 'break_start', 'break_end',
                    'rejected_unknown', 'rejected_revoked', 'rejected_debounce', 'rejected_signature'
                ))
                NOT VALID
        SQL);

        // Se valida a proposito: con escaneos de RN-18 escritos, esto falla y la
        // reversion se detiene en lugar de dejar una restriccion que no describe
        // el contenido de la tabla. Ver el docblock.
        DB::statement('ALTER TABLE scan_events VALIDATE CONSTRAINT '.self::CONSTRAINT);
    }
};
