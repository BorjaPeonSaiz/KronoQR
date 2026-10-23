<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `scan_processing_failures` — los intentos fallidos de procesar un fichaje
 * (**RN-22**, ADR-061 §1, doc 01 §5.5, tarea 17.9 de la 2.3.0). **Tecnica, no
 * legal.**
 *
 * ## Que guarda
 *
 * Una fila por intento que el servidor no consiguio procesar y que **cuenta**
 * (no es una dependencia caida ni una contencion transitoria, ADR-059 §1): el
 * `scan_id`, el quiosco, la via, la huella del contenido, el instante con el
 * reloj del servidor y **el nombre de la clase** de la excepcion. Nunca su
 * mensaje (ADR-048), nunca el payload ni el codigo de empleado (regla dura 21).
 * Con esto el servidor sabe si es el primer fallo de un fichaje o el decimo, que
 * es lo que antes de ADR-061 no sabia.
 *
 * ## Por que una fila por intento y no un contador
 *
 * Solo-append: no necesita candado —dos fallos simultaneos del mismo `scan_id`
 * suman como mucho uno de mas, que es inocuo— y deja la historia del fallo para
 * diagnosticarlo. Un `attempts = attempts + 1` perderia las dos cosas.
 *
 * ## Las invariantes, en el esquema
 *
 * - `origin` solo de quiosco: el descarte es de la cola del quiosco.
 * - **Sin `UPDATE`** para el rol de la aplicacion: un intento no se corrige.
 *   `DELETE` se conserva para la purga tecnica de 30 dias
 *   (`attendance:prune-scan-failures`), que no es la del registro legal: esta
 *   tabla no es evidencia y no pasa por la funcion de purga de RL-02.
 * - Sin `UNIQUE` en `scan_id`, a proposito: una fila por intento.
 *
 * Indices: `(scan_id, failed_at)` para leer la historia de un fichaje en el
 * camino del fallo, y `(failed_at)` para la purga.
 *
 * ## Patron `/migracion-segura`: expand puro
 *
 * Tabla nueva y vacia: no toca ninguna fila existente. La version anterior no la
 * conoce. Los indices se crean con la tabla vacia, asi que no hace falta
 * `CONCURRENTLY`.
 *
 * ## `down()` verificado
 *
 * La borra **aunque tenga filas**, y es la unica tabla del bloque que puede: no
 * es evidencia legal, no tiene datos personales y su retencion es de 30 dias.
 * Lo que si es constancia —el descarte— vive en `scan_events` y en
 * `discarded_scan_reports`, cuyas migraciones se niegan a perderlo.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    private const string TABLE = 'scan_processing_failures';

    public function up(): void
    {
        $this->limitLockWait();

        DB::statement(<<<'SQL'
            CREATE TABLE scan_processing_failures (
                id bigserial PRIMARY KEY,
                scan_id uuid NOT NULL,
                device_id bigint NOT NULL,
                origin varchar(16) NOT NULL,
                payload_fingerprint varchar(64) NULL,
                failed_at timestamptz(6) NOT NULL,
                exception_class varchar(255) NOT NULL,
                CONSTRAINT scan_processing_failures_device_id_foreign
                    FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE,
                CONSTRAINT scan_processing_failures_chk_origin
                    CHECK (origin IN ('qr_kiosk', 'pin_kiosk')),
                CONSTRAINT scan_processing_failures_chk_exception_class
                    CHECK (exception_class <> '')
            )
        SQL);

        DB::statement('CREATE INDEX scan_processing_failures_scan_id_failed_at_index ON scan_processing_failures (scan_id, failed_at)');
        DB::statement('CREATE INDEX scan_processing_failures_failed_at_index ON scan_processing_failures (failed_at)');

        // Solo-append: sin `UPDATE`. `DELETE` se queda para la purga tecnica.
        DB::statement('REVOKE UPDATE ON TABLE '.self::TABLE.' FROM '.$this->applicationRole());
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists(self::TABLE);
    }

    private function applicationRole(): string
    {
        return '"'.AuditLogSchema::applicationRole().'"';
    }
};
