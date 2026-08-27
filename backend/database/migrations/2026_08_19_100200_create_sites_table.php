<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `sites` — centros de trabajo (doc 01 §5.5).
 *
 * La columna que sostiene el dominio es `timezone`. RN-05 define la jornada
 * como «la fecha civil, **en la zona del centro**, del `clocked_in_at` del
 * tramo que abre la jornada»: sin la zona correcta aqui, un turno 22:00→06:00
 * se atribuye al dia equivocado y nadie lo nota hasta que alguien revisa una
 * nomina. Se guarda el identificador IANA (`Europe/Madrid`), nunca un desfase
 * en horas: un desfase no sabe de cambios de hora (RN-09, regla dura 3).
 *
 * `compliance_profile_id` es **nullable** y significa «usa el perfil por
 * defecto de la instalacion» (`compliance_profiles.is_default`). Es lo que
 * permite que un cliente con un solo convenio no tenga que asignarlo centro a
 * centro, y que el asistente de puesta en marcha (RF-PD-03) pueda crear centros
 * antes de decidir el perfil.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('sites', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique('sites_name_unique');

            // Zona IANA. El valor de serie es el del mercado inicial, pero es
            // un dato editable: nada especifico de un cliente en el codigo.
            $table->string('timezone', 64)->default('Europe/Madrid');

            // El nombre que genera Laravel —`sites_compliance_profile_id_foreign`—
            // ya es el explicito y descriptivo que pide el §3.5; solo las
            // restricciones del dominio necesitan uno propio.
            $table->foreignId('compliance_profile_id')
                ->nullable()
                ->constrained('compliance_profiles')
                // Un perfil con centros asignados no se borra por descuido: se
                // reasignan los centros primero.
                ->restrictOnDelete();

            // Ajustes propios del centro. JSONB por el §3.2; sin indice GIN
            // porque se lee entero con el centro y no se consulta por clave.
            $table->jsonb('settings')->default(DB::raw("'{}'::jsonb"));

            // El doc 01 §5.5 solo lista `created_at` para esta tabla.
            $table->timestampTz('created_at', 6)->useCurrent();
        });
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists('sites');
    }
};
