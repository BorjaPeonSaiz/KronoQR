<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `employment_contracts` — **que tenia pactado cada persona y desde cuando**
 * (doc 01 §5.5, RF-GP-02, tarea 2.8).
 *
 * ## Por que es una tabla con vigencia y no cuatro columnas en `employees`
 *
 * El informe de trabajadas frente a contratadas (RF-IN-03) compara cada dia
 * contra lo que estaba pactado **ese dia**. Con las horas en la ficha, firmar un
 * anexo en julio cambiaria retroactivamente el informe de marzo: la desviacion de
 * un mes ya cerrado —y probablemente ya pagado— pasaria a calcularse contra un
 * contrato que entonces no existia. Es la misma razon por la que nada se
 * sobrescribe en este producto (regla dura 5): lo que fue verdad sigue siendolo.
 *
 * ## La invariante esta en el esquema, no solo en PHP
 *
 * `employment_contracts_no_overlap` —`EXCLUDE USING gist`, la misma tecnica que
 * `shift_entries_no_overlap` de RN-02— impide que una persona tenga dos
 * contratos vigentes el mismo dia. Sin ella, la pregunta «¿cuantas horas tenia
 * contratadas el 14 de marzo?» podria tener dos respuestas y el informe tendria
 * que elegir una por su cuenta. Una comprobacion previa desde PHP no sirve: dos
 * altas simultaneas la pasan las dos.
 *
 * `btree_gist` es lo que permite combinar la igualdad de `employee_id` (btree)
 * con el solape de `daterange` (gist) en la misma restriccion. La extension ya
 * la instala la migracion de extensiones de la Fase 1.
 *
 * **`valid_to` nulo es un contrato vigente**, y `daterange(valid_from, valid_to,
 * '[]')` lo traduce a un rango con extremo superior infinito, que es
 * exactamente lo que significa. Los dos extremos son **inclusivos**: un contrato
 * del 1 al 31 cubre el 1 y el 31, y el siguiente empieza el 1 del mes que viene.
 *
 * ## Dos columnas que el doc 01 no enumera, y por que
 *
 * `created_at` y `created_by_user_id` no estan en la lista del §5.5. Se añaden
 * —y se anotan alli— porque un cambio de contrato mueve la cifra contra la que
 * se mide la jornada de una persona: es un parametro de calculo, y el bloque E
 * de la revision de cumplimiento exige saber quien lo cambio y cuando. El
 * asiento de `audit_log` lo cuenta con su cadena de hash; estas dos columnas lo
 * dejan legible en la propia tabla sin tener que cruzar el trail para pintar la
 * ficha.
 *
 * `created_by_user_id` es NULLABLE a proposito, al contrario que en
 * `shift_corrections`: la semilla de desarrollo y una importacion inicial de
 * plantilla no tienen ninguna persona detras, y forzar un autor obligaria a
 * inventar una cuenta de sistema en la tabla de usuarios. Un contrato no es una
 * correccion del registro horario: no hay nada que explicar ante Inspeccion
 * sobre quien lo tecleo.
 *
 * ## El nombre del fichero es corto a proposito
 *
 * Se llamo primero `..._create_employment_contracts_table.php`, que es el nombre
 * canonico de Laravel. Se acorto por precaucion, siguiendo el precedente
 * documentado en `2026_08_30_100100_grant_read_ability.php`: alli, un nombre
 * largo hacia que Larastan dejara de reconocer las propiedades de
 * `PersonalAccessToken` —`name`, `abilities`, `expires_at`, `created_at`— en
 * ficheros de `Identity` que aquella tarea no tocaba, y el sintoma se aislo
 * cambiando **solo el nombre**.
 *
 * **En este caso el nombre NO era la causa, y se midio**: con la cache de
 * resultados de PHPStan borrada, esos mismos errores aparecen con el nombre
 * largo, con el corto, con este fichero fuera del arbol y con **toda** la tarea
 * 2.8 apartada del repositorio. Vienen de antes. Se deja el nombre corto porque
 * no cuesta nada y evita añadir una variable mas a un sintoma que ya ha
 * costado tiempo dos veces; que nadie lo renombre «para que se lea mejor» sin
 * volver a medirlo.
 *
 * ## Permisos
 *
 * Ninguno aqui. `ALTER DEFAULT PRIVILEGES` de la migracion de privilegios
 * (ADR-033) ya concede al rol de aplicacion `SELECT, INSERT, UPDATE, DELETE`
 * sobre toda tabla que se cree despues de ella. Las excepciones son `audit_log`
 * y `audit_chain_anchors`, que revocan lo que sobra en su propia migracion; esta
 * tabla no es una de ellas: registrar un contrato nuevo **cierra** el anterior,
 * y eso es un `UPDATE` legitimo de la columna `valid_to`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /**
     * El catalogo literal del doc 01 §5.5, en castellano porque es el valor de
     * la columna y no un identificador del codigo.
     *
     * Se escribe aqui ademas de en `ScheduleType` por lo mismo que el catalogo
     * de motivos de correccion y el de roles: el esquema de una instalacion no
     * puede depender de una clase de la aplicacion. Las dos copias las ata una
     * prueba, no la buena fe.
     *
     * @var list<string>
     */
    private const array SCHEDULE_TYPES = ['continua', 'partida', 'turnos'];

    /** Horas de una semana. No es un umbral legal —esos vienen del perfil (regla dura 14)—: es un absurdo. */
    private const int HOURS_IN_A_WEEK = 168;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('employment_contracts', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                // Regla dura 5: dar de baja a alguien no borra su ficha, y su
                // historico de contratos tampoco desaparece en cascada. El
                // informe de un periodo pasado sigue necesitandolo (RN-14).
                ->restrictOnDelete();

            // `numeric` y no coma flotante: 37,5 h semanales tiene que seguir
            // siendo 37,5 despues de multiplicarse por los dias del periodo.
            // Cinco y dos digitos porque el techo es 168,00.
            $table->decimal('weekly_hours', 5, 2);

            // El computo anual del convenio, cuando lo hay. Hasta 99999,99.
            $table->decimal('annual_hours', 7, 2)->nullable();

            $table->string('schedule_type', 16);

            // Fechas civiles y no instantes: una vigencia es calendario, igual
            // que `daily_totals.work_date` (RN-05). Un `TIMESTAMPTZ` obligaria a
            // decidir a que hora empieza un contrato, que no significa nada.
            $table->date('valid_from');
            $table->date('valid_to')->nullable();

            $table->timestampTz('created_at', 6);

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            // «Los contratos de esta persona, del mas reciente al mas antiguo»:
            // la ficha y el endpoint de listado. El indice de la restriccion de
            // exclusion es GiST y no ordena por fecha.
            $table->index(['employee_id', 'valid_from'], 'employment_contracts_employee_id_valid_from_index');
        });

        $this->addValueConstraints();
        $this->addNoOverlapConstraint();
    }

    public function down(): void
    {
        $this->limitLockWait();

        // Restricciones e indices se van con la tabla: todos son objetos
        // dependientes de `employment_contracts`, ninguno global.
        Schema::dropIfExists('employment_contracts');
    }

    /**
     * Lo que puede valer una fila, declarado donde no se puede rodear.
     *
     * Las mismas tres afirmaciones viven en `EmploymentContract`, que es donde
     * dan un mensaje con significado a quien rellena el formulario. Estas cubren
     * lo que no pasa por el dominio: una importacion de nomina, un `psql` a las
     * tres de la manana.
     */
    private function addValueConstraints(): void
    {
        DB::statement(
            'ALTER TABLE employment_contracts ADD CONSTRAINT employment_contracts_chk_schedule_type '
            .'CHECK (schedule_type IN ('.$this->quotedList(self::SCHEDULE_TYPES).'))'
        );

        DB::statement(
            'ALTER TABLE employment_contracts ADD CONSTRAINT employment_contracts_chk_weekly_hours '
            .'CHECK (weekly_hours > 0 AND weekly_hours <= '.self::HOURS_IN_A_WEEK.')'
        );

        DB::statement(<<<'SQL'
            ALTER TABLE employment_contracts
                ADD CONSTRAINT employment_contracts_chk_annual_hours
                CHECK (annual_hours IS NULL OR annual_hours > 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE employment_contracts
                ADD CONSTRAINT employment_contracts_chk_period
                CHECK (valid_to IS NULL OR valid_to >= valid_from)
        SQL);
    }

    /**
     * Una persona, un contrato vigente cada dia (RF-GP-02, RF-IN-03).
     *
     * `'[]'` hace inclusivos los dos extremos —PostgreSQL normaliza el rango a
     * `[from, to+1)` por dentro—, de modo que dos contratos consecutivos no se
     * solapan si el segundo empieza el dia siguiente al ultimo del primero, que
     * es justo como los encadena `RegisterEmploymentContract`.
     */
    private function addNoOverlapConstraint(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE employment_contracts
                ADD CONSTRAINT employment_contracts_no_overlap
                EXCLUDE USING gist (
                    employee_id WITH =,
                    daterange(valid_from, valid_to, '[]') WITH &&
                )
        SQL);
    }

    /**
     * @param  list<string>  $values
     */
    private function quotedList(array $values): string
    {
        // Constantes de esta clase, nunca entrada externa; aun asi se escapan,
        // porque un literal SQL construido por concatenacion sin escapar es una
        // costumbre que acaba aplicandose a algo que si lo es.
        return implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $values,
        ));
    }
};
