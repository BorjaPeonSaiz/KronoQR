<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El motivo de una concesion de soporte tiene plazo (bloque 11 de la 2.3.0,
 * tarea 11.3 C, F4; RGPD art. 5.1.e, RL-11, ADR-020).
 *
 * `support_grants.reason` es texto libre que escribe una persona del cliente y
 * que puede nombrar a alguien. El asiento `support_grant.granted` ya lo
 * conserva tal cual (ADR-020) y no se puede tocar; la copia de la fila no hace
 * falta para siempre. Una tarea diaria (`product:support-grants:prune-reasons`)
 * la vacia pasados `PRODUCT_SUPPORT_GRANT_REASON_RETENTION_DAYS` desde que la
 * concesion se cerro.
 *
 * ## Expand
 *
 * - `reason` pasa a admitir `NULL`. El codigo anterior siempre lo escribe, asi
 *   que convive con este esquema.
 * - `reason_purged_at`, nula: cuando se vacio.
 * - `support_grants_chk_reason_purged`: `reason` es nulo **exactamente** cuando
 *   consta `reason_purged_at`. Un motivo vaciado sin fecha, o una fecha con el
 *   motivo dentro, no pueden existir.
 * - `support_grants_chk_reason_purged_after_close`: no se vacia el motivo de
 *   una concesion que no se ha cerrado todavia.
 *
 * Los dos `CHECK` se añaden `NOT VALID` y se validan en otra transaccion
 * (skill `migracion-segura`): la tabla es pequeña, pero la pauta es la misma.
 *
 * ## Reversible, salvo que ya se haya vaciado algo
 *
 * `down()` se niega si hay algun motivo vaciado: devolver el `NOT NULL`
 * exigiria inventarse un texto, y el original ya no existe.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public $withinTransaction = false;

    private const string CHECK_PURGED = 'support_grants_chk_reason_purged';

    private const string CHECK_AFTER_CLOSE = 'support_grants_chk_reason_purged_after_close';

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('ALTER TABLE support_grants ALTER COLUMN reason DROP NOT NULL');
            DB::statement('ALTER TABLE support_grants ADD COLUMN IF NOT EXISTS reason_purged_at timestamptz(6) NULL');

            // Idempotente: si un `VALIDATE` se interrumpe, el siguiente
            // `migrate` vuelve a entrar aqui.
            DB::statement('ALTER TABLE support_grants DROP CONSTRAINT IF EXISTS '.self::CHECK_PURGED);
            DB::statement('ALTER TABLE support_grants ADD CONSTRAINT '.self::CHECK_PURGED
                .' CHECK ((reason IS NULL) = (reason_purged_at IS NOT NULL)) NOT VALID');

            DB::statement('ALTER TABLE support_grants DROP CONSTRAINT IF EXISTS '.self::CHECK_AFTER_CLOSE);
            DB::statement('ALTER TABLE support_grants ADD CONSTRAINT '.self::CHECK_AFTER_CLOSE
                .' CHECK (reason_purged_at IS NULL OR reason_purged_at > COALESCE(revoked_at, expires_at)) NOT VALID');
        });

        $this->validateConstraint('support_grants', self::CHECK_PURGED);
        $this->validateConstraint('support_grants', self::CHECK_AFTER_CLOSE);
    }

    public function down(): void
    {
        // Antes del primer `ALTER`: sin transaccion envolvente, un fallo a
        // mitad dejaria la tabla a medias.
        if (DB::table('support_grants')->whereNotNull('reason_purged_at')->exists()) {
            throw new RuntimeException(
                'No se puede deshacer: hay concesiones de soporte con el motivo ya vaciado y '
                .'support_grants.reason no puede volver a ser obligatorio sin inventar un texto.'
            );
        }

        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('ALTER TABLE support_grants DROP CONSTRAINT IF EXISTS '.self::CHECK_AFTER_CLOSE);
            DB::statement('ALTER TABLE support_grants DROP CONSTRAINT IF EXISTS '.self::CHECK_PURGED);
            DB::statement('ALTER TABLE support_grants DROP COLUMN IF EXISTS reason_purged_at');
            DB::statement('ALTER TABLE support_grants ALTER COLUMN reason SET NOT NULL');
        });
    }
};
