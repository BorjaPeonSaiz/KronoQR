<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `credentials` — la tarjeta fisica de cada empleado (doc 01 §5.5,
 * RF-QR-01..03, RF-QR-06).
 *
 * El QR impreso lleva `FH1.<key_id>.<token>.<sig>` (regla dura 10). De esas
 * cuatro partes, en la base de datos solo viven dos:
 *
 * - `key_id` — que clave HMAC firmo la tarjeta. Existe para poder **rotar la
 *   clave sin reimprimir**: durante el solape conviven la anterior y la nueva
 *   (doc 02 Anexo B, `QR_SIGNING_KEY_PREVIOUS_ID`).
 * - `secret_hash` — el hash del token. **Nunca el token en claro**: quien lea
 *   la tabla no puede fabricar una tarjeta valida.
 *
 * Ni PII ni identificadores secuenciales en el QR (regla dura 10): el token es
 * aleatorio y no dice de quien es hasta que esta tabla lo resuelve.
 *
 * `one_active_credential_per_employee` declara la invariante del agregado
 * `Credential` del doc 01 §5.2 —«una credencial activa por empleado»—: reemitir
 * una tarjeta obliga a revocar la anterior, no a dejar dos vivas. Es la misma
 * tecnica que RN-01 y por el mismo motivo: en un sistema con valor probatorio
 * la integridad no puede depender solo del codigo de aplicacion.
 *
 * Las tres marcas del ciclo de vida —`printed_at`, `delivered_at`,
 * `delivered_by_user_id`— existen porque RF-QR-06 exige registrar la entrega:
 * ante una discusion sobre un fichaje hay que poder decir quien entrego esa
 * tarjeta y cuando.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('credentials', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                // Nada se borra (regla dura 5): la credencial revocada de un
                // empleado de baja es parte del registro.
                ->restrictOnDelete();

            $table->string('key_id', 16);
            $table->string('secret_hash', 128);

            $table->timestampTz('issued_at', 6);
            $table->timestampTz('printed_at', 6)->nullable();
            $table->timestampTz('delivered_at', 6)->nullable();

            $table->foreignId('delivered_by_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampTz('revoked_at', 6)->nullable();
            $table->string('revoked_reason', 190)->nullable();

            // El camino caliente del fichaje: resolver una tarjeta escaneada.
            // Es una busqueda por hash, no por empleado.
            $table->unique(['key_id', 'secret_hash'], 'credentials_key_id_secret_hash_unique');
        });

        // Invariante del agregado, declarada (doc 01 §5.2). Una credencial esta
        // activa mientras no tenga fecha de revocacion.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX one_active_credential_per_employee
                ON credentials (employee_id)
                WHERE revoked_at IS NULL
        SQL);

        // Una credencial no puede entregarse antes de imprimirse ni revocarse
        // antes de emitirse. El panel de estado de RF-QR-08 se apoya en que
        // estas fechas estan ordenadas.
        DB::statement(<<<'SQL'
            ALTER TABLE credentials
                ADD CONSTRAINT credentials_chk_lifecycle_order
                CHECK (
                    (printed_at IS NULL OR printed_at >= issued_at)
                    AND (delivered_at IS NULL OR delivered_at >= issued_at)
                    AND (revoked_at IS NULL OR revoked_at >= issued_at)
                )
        SQL);

        // La revocacion se explica siempre: es una accion con relevancia legal
        // y su motivo acompana a la entrada de `audit_log`.
        DB::statement(<<<'SQL'
            ALTER TABLE credentials
                ADD CONSTRAINT credentials_chk_revocation_has_reason
                CHECK (revoked_at IS NULL OR revoked_reason IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists('credentials');
    }
};
