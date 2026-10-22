<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Las exportaciones largas en diferido (2.3.0, bloque 14, V5-PA-4, RC2).
 *
 * `report_exports` gana dos clases —`legal`, la exportacion para la Inspeccion,
 * y `adoption`, el cuadro de impacto—, un estado —`cancelled`— y la columna
 * que lo sella, `cancelled_at`.
 *
 * ## Solo EXPAND
 *
 * Una columna nueva **nula y sin valor por omision** (cambio de catalogo, sin
 * reescribir la tabla) y tres `CHECK` sustituidos por otros **mas anchos**:
 * todo lo que la version anterior escribia sigue cumpliendolos. Una version
 * anterior que lea la tabla despues de esta migracion no encuentra nada que no
 * sepa leer hasta que alguien pide una exportacion `legal` o cancela una, y eso
 * solo lo puede hacer la version nueva. No hay fase de contraccion.
 *
 * ## Los `CHECK` se añaden `NOT VALID` y se validan fuera de la transaccion
 *
 * El patron de la casa (`LimitsMigrationLocks`): el `ADD CONSTRAINT` toma el
 * candado un instante con `lock_timeout` bajo, y el `VALIDATE` recorre la tabla
 * con un candado que no bloquea las escrituras. `report_exports` es pequeña —una
 * fila por informe pedido—, pero el patron no depende del tamaño.
 *
 * ## Las invariantes van en la base de datos, no solo en PHP
 *
 * - `report_exports_chk_cancelled_is_stamped`: `cancelled` si y solo si
 *   `cancelled_at`. Una fila cancelada sin fecha no diria cuando se detuvo; una
 *   fecha sin estado seria una cancelacion que no cancelo nada.
 * - `report_exports_chk_legal_format`: la exportacion para la Inspeccion solo
 *   en CSV (RL-06), como `report_exports_chk_payroll_format` hace con la nomina.
 *
 * ## Catalogos escritos aqui, no leidos de los `enum`
 *
 * Al contrario que la migracion que creo la tabla. Una migracion tiene que
 * hacer siempre lo mismo, y el `down()` tiene que reponer **el catalogo de
 * antes**, que ya no existe en ningun `enum`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public $withinTransaction = false;

    private const string CHECK_STATUS = 'report_exports_chk_status';

    private const string CHECK_KIND = 'report_exports_chk_kind';

    private const string CHECK_FORMAT = 'report_exports_chk_format';

    private const string CHECK_CANCELLED = 'report_exports_chk_cancelled_is_stamped';

    private const string CHECK_LEGAL_FORMAT = 'report_exports_chk_legal_format';

    private const string STATUSES = "'pending', 'running', 'completed', 'failed', 'cancelled', 'purged'";

    private const string KINDS = "'period', 'payroll', 'legal', 'adoption'";

    private const string FORMATS = "'csv', 'xlsx', 'pdf'";

    private const string PREVIOUS_STATUSES = "'pending', 'running', 'completed', 'failed', 'purged'";

    private const string PREVIOUS_KINDS = "'period', 'payroll'";

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('ALTER TABLE report_exports ADD COLUMN IF NOT EXISTS cancelled_at timestamptz(6) NULL');

            // Idempotente: si un `VALIDATE` se interrumpe, el siguiente
            // `migrate` vuelve a entrar aqui.
            $this->replaceCheck(self::CHECK_STATUS, 'status IN ('.self::STATUSES.')');
            $this->replaceCheck(self::CHECK_KIND, 'kind IN ('.self::KINDS.')');
            $this->replaceCheck(self::CHECK_FORMAT, 'format IN ('.self::FORMATS.')');
            $this->replaceCheck(self::CHECK_CANCELLED, "(status = 'cancelled') = (cancelled_at IS NOT NULL)");
            $this->replaceCheck(self::CHECK_LEGAL_FORMAT, "kind <> 'legal' OR format = 'csv'");
        });

        foreach ([self::CHECK_STATUS, self::CHECK_KIND, self::CHECK_FORMAT, self::CHECK_CANCELLED, self::CHECK_LEGAL_FORMAT] as $check) {
            $this->validateConstraint('report_exports', $check);
        }
    }

    public function down(): void
    {
        /*
         * Antes del primer `ALTER` y sin borrar nada (regla dura 5): una fila
         * `legal`, `adoption` o `cancelled` no cabe en el catalogo de antes, y
         * la unica forma de volver seria borrarla o reescribirla. Se para y se
         * dice.
         */
        $blocking = DB::table('report_exports')
            ->whereIn('kind', ['legal', 'adoption'])
            ->orWhere('status', 'cancelled')
            ->exists();

        if ($blocking) {
            throw new RuntimeException(
                'No se puede deshacer: hay exportaciones legal, adoption o cancelled en report_exports '
                .'y el catalogo anterior no las admite. Ninguna fila se borra para poder volver.'
            );
        }

        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('ALTER TABLE report_exports DROP CONSTRAINT IF EXISTS '.self::CHECK_LEGAL_FORMAT);
            DB::statement('ALTER TABLE report_exports DROP CONSTRAINT IF EXISTS '.self::CHECK_CANCELLED);
            $this->replaceCheck(self::CHECK_KIND, 'kind IN ('.self::PREVIOUS_KINDS.')');
            $this->replaceCheck(self::CHECK_STATUS, 'status IN ('.self::PREVIOUS_STATUSES.')');
            DB::statement('ALTER TABLE report_exports DROP COLUMN IF EXISTS cancelled_at');
        });

        $this->validateConstraint('report_exports', self::CHECK_KIND);
        $this->validateConstraint('report_exports', self::CHECK_STATUS);
    }

    /** `DROP` + `ADD … NOT VALID`, dentro de la transaccion de quien llama. */
    private function replaceCheck(string $name, string $condition): void
    {
        DB::statement('ALTER TABLE report_exports DROP CONSTRAINT IF EXISTS '.$name);
        DB::statement('ALTER TABLE report_exports ADD CONSTRAINT '.$name.' CHECK ('.$condition.') NOT VALID');
    }
};
