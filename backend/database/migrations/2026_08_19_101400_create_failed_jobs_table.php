<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `failed_jobs` — fontaneria del framework, no esquema de negocio.
 *
 * Esta aqui y no en la lista de tablas del doc 01 §5.5 porque no es una tabla
 * del dominio: es donde Laravel deja un trabajo de cola que agoto sus
 * reintentos (`config/queue.php`, driver `database-uuids`). La cola vive en
 * Redis, pero **los fallos van a la base de datos** por decision del framework,
 * y sin esta tabla el fallo de un trabajo se convierte en un segundo error que
 * tapa al primero.
 *
 * Importa desde la Fase 1: la proyeccion de `daily_totals` es sincrona
 * (regla dura 7), pero los eventos de dominio que consumen `Compliance` y
 * `Reporting` no lo son. Un trabajo perdido en silencio es una incidencia que
 * nadie abre.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique('failed_jobs_uuid_unique');
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestampTz('failed_at', 6)->useCurrent();
        });
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists('failed_jobs');
    }
};
