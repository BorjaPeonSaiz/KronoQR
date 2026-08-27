<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `users` — **usuarios de gestion**, no empleados (doc 01 §5.5, RF-ID-01).
 *
 * La distincion es la que sostiene la regla dura 12: el empleado entra a su
 * portal con **codigo y PIN** y puede no tener correo (`employees.pin_hash`,
 * ADR-015); quien entra aqui es personal de gestion —administracion, RRHH,
 * responsable de departamento, auditor— con correo y contrasena. Son dos
 * poblaciones distintas y dos tablas distintas: fusionarlas obligaria a dar
 * correo a toda la plantilla, que es justo lo que el producto no puede exigir.
 *
 * El correo es `citext`: `Ana@hotel.example` y `ana@hotel.example` no pueden
 * ser dos cuentas.
 *
 * **Lo que esta tabla todavia no tiene, y por que.** El 2FA obligatorio de
 * RF-ID-01 y el ambito por departamento de RF-ID-03 llegan en la tarea 2.1
 * (Anexo A del doc 01). Sus columnas se anaden alli como *expand* —columnas
 * nuevas, nullable, sobre una tabla de decenas de filas—, que es una migracion
 * barata y sin riesgo. La anticipacion de ADR-026 y ADR-024 se aplico a
 * `shift_entries` y `scan_events` por un motivo que aqui no concurre: aquellas
 * son las tablas con valor probatorio y con millones de filas.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();

            // Identificador publico. El interno (BIGINT) no sale nunca de la
            // base de datos: en la API y en la auditoria viaja el UUID.
            $table->uuid('uuid')->unique('users_uuid_unique');

            $table->string('name', 120);
            $table->string('email', 190)->unique('users_email_unique');
            $table->string('password');
            $table->rememberToken();

            // Idioma del panel para esta persona (RF-KI-05 aplicado a gestion).
            $table->string('locale', 10)->default('es');

            // Baja logica: un usuario que deja la empresa se desactiva, no se
            // borra, porque `audit_log` y `credentials.delivered_by_user_id`
            // apuntan a el y el registro tiene que seguir siendo legible
            // (regla dura 5).
            $table->boolean('is_active')->default(true);

            $table->timestampTz('last_login_at', 6)->nullable();
            $table->timestampsTz(6);
        });

        // `citext` para que la unicidad del correo no dependa de mayusculas.
        // Se aplica despues de crear la tabla porque el constructor de esquema
        // de Laravel no conoce el tipo; PostgreSQL reconstruye el indice unico
        // con el operador correcto al cambiar el tipo de la columna.
        DB::statement('ALTER TABLE users ALTER COLUMN email TYPE citext USING email::citext');
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists('users');
    }
};
