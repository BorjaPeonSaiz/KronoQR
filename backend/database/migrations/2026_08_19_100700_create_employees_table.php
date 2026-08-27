<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `employees` — la plantilla (doc 01 §5.5, RF-GP-01, RF-GP-03).
 *
 * Cuatro decisiones que no son de estilo:
 *
 * 1. **`national_id_hash`, nunca el DNI en claro** (RL-08). Es `bytea` con el
 *    digest de `pgcrypto`. El DNI solo hace falta para comprobar que dos altas
 *    no son la misma persona y para cruzar con una nomina: para eso basta el
 *    hash. Si la copia de seguridad de un cliente acaba donde no debe, no hay
 *    documentos de identidad dentro.
 * 2. **`employee_code` es `citext`, opaco y aleatorio** (doc 01 §5.5). Opaco
 *    porque viaja en el QR y en el portal: un codigo secuencial revelaria
 *    cuanta gente hay y en que orden entro, y uno derivado del nombre seria un
 *    dato personal impreso en una tarjeta.
 * 3. **`email` es opcional** (regla dura 12, ADR-015). El producto no depende
 *    del correo del empleado; el acceso al portal es codigo mas PIN. Su
 *    unicidad es un indice **parcial**: muchos empleados sin correo no pueden
 *    colisionar entre si.
 * 4. **`pin_hash` nunca guarda el PIN** (RF-ID-09). Se muestra una vez para
 *    entregarlo y despues solo se restablece.
 *
 * `photo_path` existe porque el doc 01 §5.5 lo lista, y nace **sin uso**: la
 * funcionalidad esta desactivada por defecto (RL-08). Cero biometria
 * (ADR-009): una foto de ficha no es un dato biometrico, pero el producto no la
 * activa sin decision explicita del cliente.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('employees', function (Blueprint $table): void {
            $table->id();

            // UUID v7: identificador publico y el unico con el que el empleado
            // aparece en logs, metricas y `error_events` (regla dura 21).
            $table->uuid('uuid')->unique('employees_uuid_unique');

            $table->foreignId('site_id')
                ->constrained('sites')
                ->restrictOnDelete();

            $table->foreignId('department_id')
                ->nullable()
                ->constrained('departments')
                ->nullOnDelete();

            $table->string('first_name', 100);
            $table->string('last_name', 150);

            $table->string('employee_code', 32)->unique('employees_employee_code_unique');

            // Digest del documento de identidad. `bytea` y no texto: es un
            // hash, no una cadena legible.
            $table->binary('national_id_hash')->nullable();

            $table->string('email', 190)->nullable();

            // Hash del PIN de 6 digitos (RF-AT-11, RF-ID-06). Nullable: el
            // empleado existe antes de que se le provisione.
            $table->string('pin_hash')->nullable();

            $table->string('photo_path', 255)->nullable();

            // `active` | `suspended` | `terminated`, los tres valores del
            // doc 01 §5.5. RN-14: solo el activo ficha; el de baja conserva su
            // historial y su credencial queda revocada.
            $table->string('status', 16)->default('active');

            $table->date('hired_at');
            $table->date('terminated_at')->nullable();

            $table->string('locale', 10)->default('es');

            $table->timestampsTz(6);
        });

        DB::statement('ALTER TABLE employees ALTER COLUMN employee_code TYPE citext USING employee_code::citext');
        DB::statement('ALTER TABLE employees ALTER COLUMN email TYPE citext USING email::citext');

        DB::statement(<<<'SQL'
            ALTER TABLE employees
                ADD CONSTRAINT employees_chk_status
                CHECK (status IN ('active', 'suspended', 'terminated'))
        SQL);

        // RF-GP-03: la baja es logica y lleva fecha. Un empleado en `terminated`
        // sin fecha de baja deja el historico sin el dato que la retencion de
        // RL-02 necesita para saber cuando empieza a contar.
        DB::statement(<<<'SQL'
            ALTER TABLE employees
                ADD CONSTRAINT employees_chk_terminated_has_date
                CHECK (status <> 'terminated' OR terminated_at IS NOT NULL)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE employees
                ADD CONSTRAINT employees_chk_terminated_after_hired
                CHECK (terminated_at IS NULL OR terminated_at >= hired_at)
        SQL);

        // Indices unicos parciales: la unicidad solo interesa en las filas que
        // tienen el dato. PostgreSQL ya no compara NULL con NULL, asi que un
        // indice completo tampoco daria falsos choques; lo que evita el `WHERE`
        // es cargar el indice con la mayoria de la plantilla, que no tiene
        // correo (regla dura 12) y a la que nadie busca por ahi.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX employees_email_unique
                ON employees (email)
                WHERE email IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX employees_national_id_hash_unique
                ON employees (national_id_hash)
                WHERE national_id_hash IS NOT NULL
        SQL);

        // El padron que el quiosco descarga (RF-KI-03) y el listado del panel
        // preguntan siempre lo mismo: quien esta activo en este centro. Indice
        // parcial para no arrastrar el historico de bajas, que crece y nunca se
        // consulta por aqui.
        DB::statement(<<<'SQL'
            CREATE INDEX active_employees_per_site
                ON employees (site_id, department_id)
                WHERE status = 'active'
        SQL);
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists('employees');
    }
};
