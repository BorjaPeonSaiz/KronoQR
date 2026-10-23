<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La recogida repetida del emparejamiento (**V6-KI-02**, 2.3.0): si la tablet
 * pierde la respuesta del `claim`, puede repetirlo durante una ventana corta
 * desde el mismo origen y recibir otro token para el mismo quiosco.
 *
 * Dos columnas en `device_pairing_requests`:
 *
 * - **`claimed_origin`**: la huella opaca del origen de la primera recogida —un
 *   HMAC de la direccion con la clave de la aplicacion, nunca la direccion—. La
 *   fila se purga a las 24 h, como toda solicitud.
 * - **`reclaims`**: cuantas recogidas repetidas lleva. Es el arbitro de dos
 *   recogidas simultaneas: `UPDATE ... WHERE reclaims = <leido>`, y solo una
 *   cambia la fila.
 *
 * `device_pairing_requests_chk_reclaim`: solo una solicitud consumida tiene
 * origen o recogidas repetidas.
 *
 * ## Patron `/migracion-segura`: expand puro
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Dos columnas con valor por defecto constante (solo catalogo en PostgreSQL 11+) y un `CHECK` `NOT VALID` validado fuera de la transaccion. | La version anterior no las escribe: sus solicitudes consumidas quedan sin origen y, por tanto, sin recogida repetida, que es el comportamiento de antes. |
 * | **2 (migrate)** | *No aplica*: las solicitudes vivas se purgan a las 24 h. | — |
 * | **3 (contract)** | *No aplica.* | — |
 *
 * Sin `GRANT`: la tabla ya los tiene, y las columnas nuevas los heredan.
 *
 * ## `down()` verificado
 *
 * Quita la restriccion y las dos columnas. No hay nada que conservar: son datos
 * tecnicos de un emparejamiento de un solo uso que se purga solo.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /** La validacion va fuera de la transaccion que crea la restriccion. */
    public $withinTransaction = false;

    private const string CHECK = 'device_pairing_requests_chk_reclaim';

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement(<<<'SQL'
                ALTER TABLE device_pairing_requests
                    ADD COLUMN IF NOT EXISTS claimed_origin varchar(64) NULL,
                    ADD COLUMN IF NOT EXISTS reclaims smallint NOT NULL DEFAULT 0
            SQL);

            DB::statement('ALTER TABLE device_pairing_requests DROP CONSTRAINT IF EXISTS '.self::CHECK);
            DB::statement(<<<'SQL'
                ALTER TABLE device_pairing_requests
                    ADD CONSTRAINT device_pairing_requests_chk_reclaim
                    CHECK (
                        reclaims >= 0
                        AND (status = 'claimed' OR (claimed_origin IS NULL AND reclaims = 0))
                    )
                    NOT VALID
            SQL);
        });

        $this->validateConstraint('device_pairing_requests', self::CHECK);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('ALTER TABLE device_pairing_requests DROP CONSTRAINT IF EXISTS '.self::CHECK);
            DB::statement(<<<'SQL'
                ALTER TABLE device_pairing_requests
                    DROP COLUMN IF EXISTS reclaims,
                    DROP COLUMN IF EXISTS claimed_origin
            SQL);
        });
    }
};
