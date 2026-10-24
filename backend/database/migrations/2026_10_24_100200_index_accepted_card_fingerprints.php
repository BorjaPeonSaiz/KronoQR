<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;

/**
 * La atribucion por huella de un descarte del servidor (**RN-22**, ADR-061 A2)
 * entra por indice, no recorriendo fichajes.
 *
 * Sin el, buscar el titular de una tarjeta por `payload_fingerprint` recorria los
 * fichajes de 180 dias filtrando fila a fila (medido en la revision: ~109 000
 * filas descartadas, 322 ms), y esa duracion, muy por encima del suelo de tiempo
 * del descarte (50 ms), hablaba de cuanto se habia usado la tarjeta: un canal
 * lateral (RS-03).
 *
 * **Parcial**: solo fichajes de tarjeta **aceptados**, que son los unicos que la
 * busqueda mira. Los valores se escriben literalmente —una migracion describe el
 * esquema en el momento en que se escribio— y la consulta de
 * `DatabaseCardFingerprintOwners` repite el mismo predicado, tambien literal,
 * para que el planificador pueda usarlo. `occurred_at` va detras para servir el
 * `ORDER BY ... DESC LIMIT 1` sin ordenar.
 *
 * Coste: una entrada de indice mas por cada fichaje de tarjeta aceptado. Lo
 * decidio el propietario frente al canal lateral.
 *
 * ## Patron `/migracion-segura`: expand puro, `CONCURRENTLY`
 *
 * `scan_events` es la tabla mas grande de la instalacion: el indice se crea sin
 * bloquear el fichaje, fuera de transaccion y con el `lock_timeout` del trait.
 * `down()` lo quita con `DROP INDEX CONCURRENTLY`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /** `CREATE INDEX CONCURRENTLY` no puede ir en una transaccion. */
    public $withinTransaction = false;

    private const string INDEX = 'scan_events_accepted_card_fingerprint_index';

    public function up(): void
    {
        $this->createIndexConcurrently(
            self::INDEX,
            "ON scan_events (payload_fingerprint, occurred_at DESC) WHERE origin = 'qr_kiosk' "
            ."AND result IN ('clock_in', 'clock_out', 'break_start', 'break_end')",
        );
    }

    public function down(): void
    {
        $this->dropIndexConcurrently(self::INDEX);
    }
};
