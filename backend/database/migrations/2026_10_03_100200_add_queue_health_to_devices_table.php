<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El estado de la cola del quiosco en `devices`: `queue_storage`,
 * `unreported_discards` y `pending_queue_size` nulo (RF-PA-07, RF-KI-04,
 * ADR-047, 2.2.0).
 *
 * ## Que problema resuelve
 *
 * Cuando IndexedDB falla y no se puede reabrir, la cola de la tablet cae a
 * memoria y **no sabe cuanto quedo en el disco**. Hasta la 2.2.0 el latido
 * declaraba entonces `pending_queue_size: 0`, que apagaba la alerta de cola
 * atascada justo cuando habia fichajes que no se veian. Desde la 2.2.0 la
 * tablet declara `queue_storage` y un tamaño **desconocido** (`NULL`), y el
 * numero de fichajes descartados cuyo aviso no ha salido (`unreported_discards`,
 * RN-22). Las tres cosas las enseña el panel (`DeviceHealth`) y las publica
 * `/metrics`.
 *
 * ## Patron `/migracion-segura`: expand puro
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Dos columnas `NOT NULL` **con valor de serie** —`durable` y `0`, lo que una PWA anterior declara sin decirlo— y `pending_queue_size` pasa a admitir `NULL`. En PostgreSQL 11+ un `ADD COLUMN` con `DEFAULT` constante no reescribe la tabla, y `DROP NOT NULL` solo toca el catalogo. | La version anterior no nombra las columnas nuevas y nunca escribe `NULL` en `pending_queue_size`. |
 * | **2 (migrate)** | *No aplica.* El valor de serie es la verdad de toda fila existente: ninguna tablet anterior a la 2.2.0 declaraba otra cosa. | — |
 * | **3 (contract)** | *No aplica.* | — |
 *
 * Los `CHECK` entran `NOT VALID` y se validan aparte, **despues de confirmar**
 * la transaccion que los crea (`LimitsMigrationLocks::validateConstraint()`,
 * hallazgo DB3), que no bloquea las escrituras del latido. Dentro de ella, el
 * `ACCESS EXCLUSIVE` del `ADD` habria durado todo el `VALIDATE`.
 *
 * ## `down()` verificado
 *
 * Retira las dos columnas con sus restricciones y devuelve el `NOT NULL` a
 * `pending_queue_size`. **Un tamaño desconocido vuelve como `0`**: la version
 * anterior no sabe representar «desconocido», y el siguiente latido —un minuto—
 * lo sobrescribe. Es telemetria del aparato, no registro de jornada: no hay
 * nada que conservar, y bloquear la vuelta atras porque una tablet sigue en
 * memoria dejaria al cliente sin poder revertir una version en una emergencia.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /**
     * Los `VALIDATE` van fuera de la transaccion que crea las columnas y los
     * `CHECK`: ver {@see LimitsMigrationLocks}.
     *
     * @var bool
     */
    public $withinTransaction = false;

    /** @var array<string, string> Nombre => expresion de cada `CHECK`. */
    private const array CHECKS = [
        'devices_chk_queue_storage' => "queue_storage IN ('durable', 'memory', 'unavailable')",
        'devices_chk_unreported_discards_range' => 'unreported_discards BETWEEN 0 AND 100000',
        // El contrato, en el esquema: un tamaño desconocido solo con la cola
        // fuera de IndexedDB. Con la cola en disco la tablet siempre sabe.
        'devices_chk_unknown_queue_size_only_when_degraded' => "pending_queue_size IS NOT NULL OR queue_storage <> 'durable'",
    ];

    public function up(): void
    {
        // Idempotente: si un `VALIDATE` se interrumpe, el siguiente `migrate`
        // vuelve a entrar aqui con las columnas ya creadas.
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement("ALTER TABLE devices ADD COLUMN IF NOT EXISTS queue_storage varchar(16) NOT NULL DEFAULT 'durable'");
            DB::statement('ALTER TABLE devices ADD COLUMN IF NOT EXISTS unreported_discards integer NOT NULL DEFAULT 0');
            DB::statement('ALTER TABLE devices ALTER COLUMN pending_queue_size DROP NOT NULL');

            foreach (self::CHECKS as $name => $expression) {
                DB::statement('ALTER TABLE devices DROP CONSTRAINT IF EXISTS '.$name);
                DB::statement('ALTER TABLE devices ADD CONSTRAINT '.$name.' CHECK ('.$expression.') NOT VALID');
            }
        });

        foreach (array_keys(self::CHECKS) as $name) {
            $this->validateConstraint('devices', $name);
        }
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            foreach (array_reverse(array_keys(self::CHECKS)) as $name) {
                DB::statement('ALTER TABLE devices DROP CONSTRAINT IF EXISTS '.$name);
            }

            DB::statement('UPDATE devices SET pending_queue_size = 0 WHERE pending_queue_size IS NULL');
            DB::statement('ALTER TABLE devices ALTER COLUMN pending_queue_size SET NOT NULL');

            DB::statement('ALTER TABLE devices DROP COLUMN IF EXISTS unreported_discards');
            DB::statement('ALTER TABLE devices DROP COLUMN IF EXISTS queue_storage');
        });
    }
};
