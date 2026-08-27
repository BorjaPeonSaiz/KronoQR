<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `devices` — los quioscos (doc 01 §5.5, RF-ID-04).
 *
 * `last_seen_at` y `pending_queue_size` son las dos columnas del **latido**: un
 * quiosco que lleva rato sin aparecer, o que acumula fichajes sin sincronizar,
 * es una alerta operativa (doc 01 §9.3) mucho antes de que alguien reclame una
 * jornada que falta. `pending_queue_size` lo declara el propio dispositivo en
 * cada latido; es informacion, no autoridad.
 *
 * `token_hash` es nullable porque el dispositivo existe en el panel antes de
 * estar emparejado, y su unicidad es un indice parcial por lo mismo. El token
 * en si lo emite `Identity` (tarea 1.5): `Device` **no valida su propio token**
 * (doc 01 §5.2).
 *
 * `status` nace con dos valores, `active` y `revoked`, que son los que la
 * Fase 1 sabe producir. El emparejamiento por codigo de RF-PD-06 anadira el
 * suyo en la Fase 5; ampliar el CHECK de una tabla con decenas de filas es una
 * migracion trivial, al contrario que la de `shift_entries.status`, que es la
 * tabla con valor probatorio y por eso nace con los cinco estados (ADR-026).
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('devices', function (Blueprint $table): void {
            $table->id();

            // Identificador publico. Es el `device_id` que aparece en los logs
            // estructurados y en `error_events`; no es dato personal, pero
            // tampoco tiene por que ser el autoincremental interno.
            $table->uuid('uuid')->unique('devices_uuid_unique');

            $table->foreignId('site_id')
                ->constrained('sites')
                ->restrictOnDelete();

            $table->string('name', 120);
            $table->string('token_hash', 128)->nullable();
            $table->string('app_version', 32)->nullable();

            $table->timestampTz('last_seen_at', 6)->nullable();
            $table->integer('pending_queue_size')->default(0);

            $table->string('status', 16)->default('active');

            $table->timestampsTz(6);

            // Dos quioscos del mismo centro no se llaman igual: el nombre es lo
            // que ve quien atiende una incidencia.
            $table->unique(['site_id', 'name'], 'devices_site_id_name_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE devices
                ADD CONSTRAINT devices_chk_status
                CHECK (status IN ('active', 'revoked'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE devices
                ADD CONSTRAINT devices_chk_pending_queue_size_not_negative
                CHECK (pending_queue_size >= 0)
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX devices_token_hash_unique
                ON devices (token_hash)
                WHERE token_hash IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists('devices');
    }
};
