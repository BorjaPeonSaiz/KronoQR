<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;

/**
 * Retira `scan_events_client_meta_gin`, que no sirve a ninguna consulta (hallazgo
 * DB7 de la verificacion; RNF-P-02).
 *
 * Se creo en `2026_08_19_101100` con la justificacion de que el paquete de
 * diagnostico y el panel consultaban por clave dentro de `client_meta`. No es
 * asi: `client_meta` solo se inserta (`EloquentScanLog`) y se exporta como texto
 * (`DatabaseDataExportSource`); el paquete de diagnostico lo excluye
 * (`PersonalDataCollector`). Ninguna consulta usa `@>`, `@?` ni `@@`, que son los
 * unicos operadores que sirve un GIN `jsonb_path_ops`, y `idx_scan` es 0 en la
 * base de desarrollo. Mientras, se mantenia en cada fichaje.
 *
 * ## Patron `/migracion-segura`
 *
 * Retirar un indice que nadie usa no es una contraccion: ninguna version
 * desplegada depende de el. `DROP INDEX CONCURRENTLY` (`dropIndexConcurrently()`)
 * no bloquea las escrituras de `scan_events` y por eso va fuera de transaccion.
 * `down()` lo reconstruye igual que se creo, con `createIndexConcurrently()`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /** `DROP` y `CREATE INDEX CONCURRENTLY` no pueden ir en una transaccion. */
    public $withinTransaction = false;

    public function up(): void
    {
        $this->dropIndexConcurrently('scan_events_client_meta_gin');
    }

    public function down(): void
    {
        $this->createIndexConcurrently('scan_events_client_meta_gin', 'ON scan_events USING gin (client_meta jsonb_path_ops)');
    }
};
