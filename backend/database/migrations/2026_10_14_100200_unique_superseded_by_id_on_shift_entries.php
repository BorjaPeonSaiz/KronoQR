<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;

/**
 * Dos versiones no pueden apuntar a la misma sustituta (ADR-035, ADR-057 §2,
 * bloque 7 de la 2.2.1; revision de seguridad; RL-04, RN-13).
 *
 * ## Por que
 *
 * `shift_entries_guard_transition` (`2026_10_14_100000`) comprobaba con un
 * `EXISTS` que ninguna otra fila apuntara ya a la sustituta. Un `EXISTS` no ve
 * la fila sin confirmar de otra transaccion: dos encadenados simultaneos hacia
 * la misma version pasaban los dos. Un indice unico si los serializa, y es la
 * garantia declarativa que pide el doc 02 §3.2.
 *
 * Sustituye a `shift_entries_superseded_by_id_index`
 * (`2026_10_08_100000_index_work_day_journal_lookups`), que tenia la misma
 * definicion sin `UNIQUE`: sirve las mismas consultas
 * (`DatabaseWorkDayJournalReader::clockingMarks()`, el `NOT EXISTS` de la purga
 * por generaciones) y mantener los dos seria escribir dos veces lo mismo.
 *
 * ## Patron `/migracion-segura`
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Indice unico parcial, `CONCURRENTLY`. | Ningun codigo nombra el indice. |
 * | **1 (contract)** | `DROP INDEX CONCURRENTLY` del antiguo. | El planificador pasa al nuevo, de la misma forma; ningun codigo nombra el antiguo. |
 *
 * Expand y contract en el mismo despliegue porque lo que se retira es una
 * estructura que nadie referencia por nombre, no una columna ni un
 * comportamiento: la version anterior del codigo funciona igual con el indice
 * nuevo.
 *
 * **No va dentro de `2026_10_14_100000`**, que es transaccional: `CREATE
 * INDEX CONCURRENTLY` no se ejecuta en una transaccion, y un `CREATE UNIQUE
 * INDEX` normal sobre `shift_entries` —millones de filas en un hotel con años
 * de registro— tomaria `SHARE` y pararia los fichajes durante toda la
 * construccion (regla dura 19, RNF-D-04, `MigrationSafetyTest`).
 *
 * **Si la construccion falla por un duplicado** —dos versiones ya apuntando a la
 * misma, que el dominio nunca produce pero la credencial del runtime podia
 * escribir antes del bloque 6—, la construccion falla con `23505`, la migracion
 * no queda anotada y el antiguo sigue en su sitio; el indice `INVALID` que deja
 * lo borra `createIndexConcurrently()` en el siguiente `migrate` antes de
 * reintentar. Hay que revisar esas filas a mano (runbook de discrepancias del
 * registro) antes de repetir: ninguna se toca desde aqui (regla dura 5).
 *
 * ## Reversible
 *
 * `down()` vuelve a crear el indice no unico y suelta el unico, los dos
 * `CONCURRENTLY`. Ida y vuelta: `MigrationsRoundTripTest`; el `23505`,
 * `WorkRecordTransitionsTest`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /**
     * `CREATE INDEX CONCURRENTLY` no se ejecuta dentro de una transaccion.
     *
     * @var bool
     */
    public $withinTransaction = false;

    private const string UNIQUE_INDEX = 'shift_entries_superseded_by_id_unique';

    private const string PREVIOUS_INDEX = 'shift_entries_superseded_by_id_index';

    private const string PREDICATE = 'WHERE superseded_by_id IS NOT NULL';

    public function up(): void
    {
        $this->createIndexConcurrently(
            self::UNIQUE_INDEX,
            'ON shift_entries (superseded_by_id) '.self::PREDICATE,
            unique: true,
        );

        $this->dropIndexConcurrently(self::PREVIOUS_INDEX);
    }

    public function down(): void
    {
        $this->createIndexConcurrently(
            self::PREVIOUS_INDEX,
            'ON shift_entries (superseded_by_id) '.self::PREDICATE,
        );

        $this->dropIndexConcurrently(self::UNIQUE_INDEX);
    }
};
