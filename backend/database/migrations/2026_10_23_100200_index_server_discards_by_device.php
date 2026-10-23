<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;

/**
 * El cupo de descartes del servidor por quiosco (**RN-22**, ADR-061 §3, tarea
 * 17.9 de la 2.3.0).
 *
 * Antes de descartar, el servidor cuenta cuantos descartes **propios** lleva ese
 * quiosco en las ultimas 24 h. Es una consulta del camino del fallo —nunca del
 * fichaje que sale bien—, pero corre justo cuando algo va mal y no debe recorrer
 * los avisos de todos los quioscos. Indice **parcial** sobre `source = 'server'`:
 * los descartes del servidor son una minoria acotada por el propio cupo, y el
 * indice no cuesta nada al aviso del quiosco, que no entra en el.
 *
 * ## `CREATE INDEX CONCURRENTLY`, fuera de transaccion
 *
 * `discarded_scan_reports` ya tiene filas en una instalacion de la 2.2.x, y un
 * `CREATE INDEX` normal tomaria `SHARE` y bloquearia los avisos mientras dura.
 * Con `CONCURRENTLY` no se bloquea la escritura; a cambio no puede ir en una
 * transaccion, de ahi `$withinTransaction = false` y el `lock_timeout` de
 * {@see LimitsMigrationLocks::createIndexConcurrently()}.
 *
 * ## Patron `/migracion-segura`: expand puro. `down()` verificado
 *
 * Un indice nuevo no cambia ninguna fila. `down()` lo quita con
 * `DROP INDEX CONCURRENTLY`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /** Ver el docblock: `CREATE INDEX CONCURRENTLY` no puede ir en una transaccion. */
    public $withinTransaction = false;

    private const string INDEX = 'discarded_scan_reports_server_device_recorded_at_index';

    public function up(): void
    {
        $this->createIndexConcurrently(
            self::INDEX,
            "ON discarded_scan_reports (device_id, recorded_at) WHERE source = 'server'",
        );
    }

    public function down(): void
    {
        $this->dropIndexConcurrently(self::INDEX);
    }
};
