<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuando se toco por ultima vez el perfil de cumplimiento y quien lo hizo
 * (RF-PD-07, RL-04, tarea 5.2 — revision).
 *
 * ## Por que hacen falta si `audit_log` ya lo guarda
 *
 * Porque responden a preguntas distintas y una de ellas no tiene respuesta hoy.
 * `audit_log` contesta «¿que paso?» con todo el detalle —quien, cuando, de que a
 * que, campo a campo— y es la prueba. Estas dos columnas contestan «¿esta fila
 * es la de serie o alguien la ha ajustado?», que es lo primero que mira quien
 * abre la pantalla, lo que el diagnostico (RF-PD-09) tiene que poder decir sin
 * llevarse el trail entero, y lo que permite a `doctor` avisar de una
 * instalacion que nunca reviso su convenio. Buscarlo en `audit_log` obliga a
 * recorrer una tabla particionada por año para contestar algo que es un atributo
 * de la fila.
 *
 * La revision encontro ademas el sintoma: `UpdateComplianceProfileCommand`
 * llevaba un `actorUserId` que **nadie escribia**, y su docblock describia un
 * mecanismo que no existia. Habia dos salidas —borrar el campo o hacerlo real— y
 * se elige la segunda porque la primera deja al cliente sin poder saber, mirando
 * su propio perfil, si alguien lo ha tocado.
 *
 * ## `NULL` significa «nadie lo ha tocado desde la instalacion»
 *
 * Las dos columnas nacen **nullable y sin valor por defecto**, y eso es una
 * afirmacion, no una comodidad: la fila `ES-hosteleria` que siembra la migracion
 * de la tarea 1.3 la escribio el producto, no una persona. Rellenarlas con la
 * fecha de la migracion diria que alguien reviso el convenio ese dia, que es
 * justo lo contrario de lo que ocurrio.
 *
 * `updated_by_user_id` tambien queda a `NULL` cuando el cambio llega por
 * consola o por el instalador: no hay sesion detras y no se inventa una.
 *
 * ## Patron `/migracion-segura`: paso EXPAND puro
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Esta migracion: dos columnas nullable y una clave ajena `nullOnDelete`. Nada se renombra, nada se borra, ninguna columna cambia de tipo. | La version anterior no las nombra y sigue funcionando. |
 * | **2 (migrate)** | *No aplica.* No hay *backfill*: `NULL` es el valor correcto para lo que ya existe. | — |
 * | **3 (contract)** | *No aplica.* | — |
 *
 * `ADD COLUMN ... NULL` sin valor por defecto **no reescribe la tabla** en
 * PostgreSQL: solo toca el catalogo. El orden respecto al codigo no importa,
 * que es lo que distingue a un paso expand.
 *
 * **`nullOnDelete` y no `restrict`**: si algun dia se borra la cuenta que
 * cambio un umbral, lo que no puede pasar es que el perfil deje de poder
 * leerse —el fichaje y la revision diaria dependen de el—. Quien hizo el cambio
 * sigue estando en `audit_log`, que es la prueba y no se borra.
 *
 * ## `down()`
 *
 * Suelta las dos columnas. Es la excepcion legitima al «nunca se borra una
 * columna» de la skill: es la vuelta atras de la migracion que las creo, no un
 * paso de contraccion sobre datos de otra version, y lo unico que se pierde es
 * una marca que `audit_log` conserva igual.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::table('compliance_profiles', function (Blueprint $table): void {
            // Sin `useCurrent()` ni `nullable()->default(...)`: NULL significa
            // «tal como se instalo», y eso hay que poder distinguirlo.
            $table->timestampTz('updated_at', 6)->nullable();

            $table->foreignId('updated_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::table('compliance_profiles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('updated_by_user_id');
            $table->dropColumn('updated_at');
        });
    }
};
