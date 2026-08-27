<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `shift_corrections` — **por que** cambio el registro horario de alguien
 * (doc 01 §5.5, RN-13, RL-04, RF-PA-04, tarea 1.15).
 *
 * `shift_entries` guarda **que** hay registrado; esta tabla guarda quien lo
 * cambio, cuando, desde que valor y con que motivo. Son dos hechos distintos y
 * con vidas distintas: la fila de aqui sigue siendo verdad aunque el tramo al
 * que se refiere sea sustituido tres veces mas.
 *
 * **Es un libro, no un estado.** Solo se inserta: no hay `updated_at` porque una
 * correccion no se corrige —se hace otra, y quedan las dos— y no hay borrado
 * porque nada se borra (regla dura 5). Por eso la clave del diseño esta en los
 * dos JSONB: `before` y `after` llevan las marcas **completas** de cada version,
 * no un delta, de modo que el historico se puede reconstruir sin volver a
 * consultar `shift_entries`.
 *
 * **Ninguno de los dos JSON lleva el nombre de nadie** (regla dura 21). Describen
 * horas, no personas: la persona esta en el tramo, y quien firma esta en
 * `performed_by_user_id`.
 *
 * **Cuando falta uno de los dos JSON es porque no existe**, y las restricciones
 * lo declaran: en un alta no hay `before` —antes no habia tramo— y en una
 * anulacion no hay `after` —el tramo no ocurrio—. Una fila con los dos a nulo no
 * describe ningun cambio, y no puede escribirse.
 *
 * **La regla del Anexo C tambien esta aqui y no solo en PHP** (`OTROS` obliga a
 * explicacion de veinte caracteres). El objeto de valor `CorrectionReason` la
 * hace inconstruible en el dominio; la restriccion la hace **imposible** para
 * una importacion, un script de migracion de datos o una version futura del
 * codigo. En un registro con valor probatorio, la integridad no puede depender
 * solo del codigo de aplicacion (doc 02 §3.2).
 *
 * **No hay indice GIN sobre los JSONB, y es deliberado.** Nadie consulta *dentro*
 * de `before` ni de `after`: se leen enteros al pintar el detalle de una jornada
 * (RF-PA-03) y al exportar el registro legal (RL-03), siempre llegando por
 * `shift_entry_id`. Un GIN aqui costaria escritura en cada correccion y no
 * responderia ninguna pregunta que alguien haga.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /**
     * Los cuatro verbos de RF-PA-04, en el mismo orden y con los mismos valores
     * que `CorrectionAction`.
     *
     * @var list<string>
     */
    private const array ACTIONS = ['created', 'modified', 'closed', 'voided'];

    /**
     * El catalogo del Anexo C del doc 01, literal.
     *
     * Se escribe aqui ademas de en `CorrectionReasonCode` porque el esquema de
     * una instalacion no puede depender de una clase de la aplicacion —es el
     * mismo motivo por el que el catalogo de roles se siembra en su migracion—.
     * Las dos copias las ata una prueba, no la buena fe.
     *
     * @var list<string>
     */
    private const array REASON_CODES = [
        'OLVIDO_FICHAJE_ENTRADA',
        'OLVIDO_FICHAJE_SALIDA',
        'FALLO_TECNICO_QUIOSCO',
        'TARJETA_NO_DISPONIBLE',
        'CREDENCIAL_NO_ENTREGADA',
        'ERROR_DE_ESCANEO_DUPLICADO',
        'AJUSTE_ACORDADO_CON_RRHH',
        'ALTA_RETROACTIVA',
        'OTROS',
    ];

    /** Anexo C: «`OTROS` obliga a texto libre de al menos 20 caracteres». */
    private const int MINIMUM_EXPLANATION_LENGTH = 20;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('shift_corrections', function (Blueprint $table): void {
            $table->id();

            // El tramo al que se refiere la correccion: la version que la accion
            // PRODUJO —la corregida o la creada— o la que TERMINO si fue una
            // anulacion. Lo resuelve `ShiftCorrected::correctedShiftEntryUuid()`
            // en un solo sitio, para que no haya que acordarse caso por caso.
            $table->foreignId('shift_entry_id')
                ->constrained('shift_entries')
                // Regla dura 5: el registro horario no desaparece en cascada, y
                // el libro que lo explica tampoco.
                ->restrictOnDelete();

            // RN-13: quien firma es una PERSONA, nunca «el sistema». La columna
            // es NOT NULL a proposito: una correccion sin autor no explica nada
            // ante Inspeccion, que es lo unico para lo que esta tabla existe.
            $table->foreignId('performed_by_user_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('action', 16);

            // Marcas completas de cada version, no un delta. `before` nulo en un
            // alta, `after` nulo en una anulacion.
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();

            $table->string('reason_code', 40);
            $table->text('reason_text')->nullable();

            // Momento de la CORRECCION, que no es ninguna de las dos marcas del
            // tramo: una jornada de hace tres semanas corregida hoy produce una
            // fila de hoy. Precision 6 por lo mismo que en `shift_entries`: la
            // de serie de Laravel redondea al segundo.
            $table->timestampTz('created_at', 6);

            // El acceso normal: el historico de un tramo al pintar el detalle de
            // una jornada (RF-PA-03) y al exportar el registro legal (RL-03).
            $table->index('shift_entry_id', 'shift_corrections_shift_entry_id_index');

            // «¿Cuantas correcciones por olvido de fichaje hubo en marzo?» es una
            // pregunta que hace una inspeccion y que alimenta
            // `manual_corrections_total{reason_code}` (doc 02 §8.2).
            $table->index(['reason_code', 'created_at'], 'shift_corrections_reason_code_created_at_index');

            // «¿Quien ha estado corrigiendo el registro este mes?» — la pregunta
            // de control interno, y la que una auditoria hace primero.
            $table->index(['performed_by_user_id', 'created_at'], 'shift_corrections_performed_by_created_at_index');
        });

        $this->addCatalogueConstraints();
        $this->addShapeConstraints();
    }

    public function down(): void
    {
        $this->limitLockWait();

        // Las restricciones y los indices se van con la tabla: son objetos
        // dependientes de `shift_corrections`, no globales.
        Schema::dropIfExists('shift_corrections');
    }

    /**
     * Los dos catalogos cerrados, declarados en el esquema.
     *
     * `CHECK ... IN (...)` y no un tipo `ENUM` de PostgreSQL: añadir un valor a
     * un `ENUM` no se puede deshacer y su `ALTER TYPE` no admite `IF NOT EXISTS`
     * en toda version soportada. Con un `CHECK`, ampliar el catalogo es soltar y
     * volver a crear la restriccion, que es una operacion reversible y con
     * `down()` verificable.
     */
    private function addCatalogueConstraints(): void
    {
        DB::statement(
            'ALTER TABLE shift_corrections ADD CONSTRAINT shift_corrections_chk_action '
            .'CHECK (action IN ('.$this->quotedList(self::ACTIONS).'))'
        );

        DB::statement(
            'ALTER TABLE shift_corrections ADD CONSTRAINT shift_corrections_chk_reason_code '
            .'CHECK (reason_code IN ('.$this->quotedList(self::REASON_CODES).'))'
        );
    }

    /**
     * Que forma puede tener una fila, segun la accion que describe.
     *
     * Son las tres afirmaciones que una inspeccion daria por supuestas y que sin
     * esto dependerian de que nadie escriba nunca en la tabla desde fuera de la
     * aplicacion.
     */
    private function addShapeConstraints(): void
    {
        // Una fila con los dos JSON a nulo no describe ningun cambio.
        DB::statement(<<<'SQL'
            ALTER TABLE shift_corrections
                ADD CONSTRAINT shift_corrections_chk_describes_a_change
                CHECK (before IS NOT NULL OR after IS NOT NULL)
        SQL);

        // En un alta no hay valor anterior; en una anulacion no hay posterior.
        DB::statement(<<<'SQL'
            ALTER TABLE shift_corrections
                ADD CONSTRAINT shift_corrections_chk_shape_by_action
                CHECK (
                    (action = 'created' AND before IS NULL     AND after IS NOT NULL)
                 OR (action = 'voided'  AND before IS NOT NULL AND after IS NULL)
                 OR (action IN ('modified', 'closed') AND before IS NOT NULL AND after IS NOT NULL)
                )
        SQL);

        // Anexo C. `char_length` cuenta CARACTERES y no bytes: veinte caracteres
        // con eñes y tildes son mas de veinte bytes, y contar bytes dejaria pasar
        // en castellano textos que en ingles se rechazan. Sobre el texto ya
        // recortado, para que veinte espacios no sean una explicacion.
        DB::statement(
            'ALTER TABLE shift_corrections ADD CONSTRAINT shift_corrections_chk_others_explains_itself '
            ."CHECK (reason_code <> 'OTROS' OR char_length(btrim(reason_text)) >= "
            .self::MINIMUM_EXPLANATION_LENGTH.')'
        );
    }

    /**
     * @param  list<string>  $values
     */
    private function quotedList(array $values): string
    {
        // Los valores son constantes de esta clase y no entrada externa; aun
        // asi se escapan, porque un literal SQL construido por concatenacion sin
        // escapar es una costumbre que acaba aplicandose a algo que si lo es.
        return implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $values,
        ));
    }
};
