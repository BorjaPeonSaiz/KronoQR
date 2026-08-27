<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `scan_events` — log inmutable de **todo** escaneo, aceptado o no (doc 01
 * §5.5, RF-AT-01, RF-AT-07, RF-AT-09).
 *
 * Tres columnas hacen aqui el trabajo pesado:
 *
 * - **`scan_id` UNIQUE** (regla dura 8). El UUID v7 lo genera el cliente y es
 *   lo que hace real la idempotencia: un reenvio desde la cola offline choca
 *   contra este indice, y el caso de uso devuelve la respuesta original. **No
 *   se resuelve con un `SELECT` previo**, que tiene condicion de carrera y bajo
 *   concurrencia crea duplicados.
 * - **`occurred_at` y `recorded_at`** (regla dura 9). La primera es el momento
 *   real, que puede venir de la cola offline; la segunda, la recepcion en
 *   servidor. **El registro legal usa `occurred_at`** (RF-AT-09).
 * - **`result`** guarda el desenlace detallado. **Nunca sale por la API**: los
 *   rechazos que ve el quiosco son genericos y de tiempo constante (RS-03,
 *   regla dura 17). El detalle vive aqui y en el log; el cliente recibe un
 *   mensaje que no distingue entre codigo inexistente, revocado o mal firmado.
 *
 * **Tres columnas nacen ahora aunque su consumidor llegue despues**, y las tres
 * por el mismo motivo: sin ellas, el dato de los fichajes de la Fase 1 se
 * pierde y la funcionalidad que lo necesita nace sin historico que mostrar.
 *
 * - `intent` (ADR-024, RF-AT-12, tarea 3.5). `auto` por defecto. El servidor no
 *   puede deducir si un cierre de tramo es una pausa o un fin de jornada: son
 *   estructuralmente identicos. Ademas el mismo campo tiene que existir en la
 *   cola offline del quiosco (tarea 1.9), y **cambiar el esquema de IndexedDB
 *   con la cola cargada en produccion obliga a migrar peticiones pendientes de
 *   fichaje, que son registro legal sin escribir**.
 * - `clock_skew_seconds` (RF-AT-10, tarea 3.5). Entero con signo, en segundos,
 *   nullable. El desfase **nunca rechaza el fichaje** (regla dura 19): se
 *   registra. La incidencia `clock_skew` que lo consume es de la Fase 3, y sin
 *   esta columna no tendria con que construirse hacia atras.
 * - `flagged_for_review` (RF-AT-11, tarea 2.5). El fichaje por PIN queda
 *   marcado para revision del responsable; la bandeja que trabaja esa marca es
 *   de la Fase 2.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('scan_events', function (Blueprint $table): void {
            $table->id();

            // Regla dura 8. UUID v7 generado en el cliente.
            $table->uuid('scan_id')->unique('scan_events_scan_id_unique');

            $table->foreignId('device_id')
                ->constrained('devices')
                ->restrictOnDelete();

            // Nullable: un escaneo que no resuelve a nadie —codigo desconocido
            // o mal firmado— se registra igual. Es la fila que permite
            // investigar despues sin haber revelado nada en su momento.
            $table->foreignId('employee_id')
                ->nullable()
                ->constrained('employees')
                ->restrictOnDelete();

            // Precision 6 en las dos: la de serie de Laravel es 0 y redondea al
            // segundo. `recorded_at - occurred_at` es lo que alimenta
            // `clock_skew_seconds`, y un redondeo en cada extremo lo falsea.
            $table->timestampTz('occurred_at', 6);
            $table->timestampTz('recorded_at', 6);

            $table->string('origin', 16);
            $table->string('intent', 16)->default('auto');
            $table->string('result', 24);

            $table->foreignId('shift_entry_id')
                ->nullable()
                ->constrained('shift_entries')
                ->restrictOnDelete();

            // Regla dura 8, y no solo en el `scan_id`: el reenvio tiene que
            // devolver el MISMO acumulado que devolvio la primera vez, no el
            // que tenga la jornada hoy. Recalcularlo desde `shift_entries` en el
            // reenvio da una cifra distinta en cuanto otro escaneo posterior
            // cierra o abre un tramo de ese mismo dia, que es precisamente lo
            // que ya ha pasado cuando la cola offline reintenta. Nulo en todo
            // rechazo real (RS-03: ese desenlace no lleva acumulado); presente
            // en el anti-rebote, que es un desenlace aceptado (ADR-031).
            $table->integer('worked_minutes')->nullable();

            // Huella del payload del QR, no el payload. Sirve para agrupar
            // escaneos de la misma tarjeta sin guardar lo que se imprimio.
            $table->string('payload_fingerprint', 64)->nullable();

            // Version de la app, modelo de tablet, calidad del escaneo... Nunca
            // datos personales (regla dura 21).
            $table->jsonb('client_meta')->default(DB::raw("'{}'::jsonb"));

            // RF-AT-10. Entero CON SIGNO: el reloj del quiosco puede ir
            // adelantado o atrasado, y perder el signo perderia justo lo que se
            // quiere diagnosticar.
            $table->integer('clock_skew_seconds')->nullable();

            // RF-AT-11.
            $table->boolean('flagged_for_review')->default(false);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE scan_events
                ADD CONSTRAINT scan_events_chk_origin
                CHECK (origin IN ('qr_kiosk', 'pin_kiosk', 'manual_admin', 'import'))
        SQL);

        // ADR-024: `auto` preserva el comportamiento del cliente que no declara
        // intencion, de modo que ampliar el enum es aditivo y no rompe la v1.
        DB::statement(<<<'SQL'
            ALTER TABLE scan_events
                ADD CONSTRAINT scan_events_chk_intent
                CHECK (intent IN ('auto', 'break_start', 'break_end'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE scan_events
                ADD CONSTRAINT scan_events_chk_result
                CHECK (result IN (
                    'clock_in', 'clock_out', 'break_start', 'break_end',
                    'rejected_unknown', 'rejected_revoked', 'rejected_debounce', 'rejected_signature'
                ))
        SQL);

        // Un escaneo rechazado no produce tramo. Lo contrario —un tramo colgado
        // de un rechazo— haria imposible auditar por que existe una jornada.
        DB::statement(<<<'SQL'
            ALTER TABLE scan_events
                ADD CONSTRAINT scan_events_chk_rejected_has_no_shift_entry
                CHECK (result NOT LIKE 'rejected%' OR shift_entry_id IS NULL)
        SQL);

        // Los tres rechazos de verdad no llevan acumulado (RS-03); todo lo
        // demas —incluido el anti-rebote, que en `result` se escribe como
        // `rejected_debounce` pero es un desenlace aceptado (ADR-031)— si.
        DB::statement(<<<'SQL'
            ALTER TABLE scan_events
                ADD CONSTRAINT scan_events_chk_worked_minutes
                CHECK (
                    (result IN ('rejected_unknown', 'rejected_revoked', 'rejected_signature')) = (worked_minutes IS NULL)
                )
        SQL);

        // El anti-rebote de RF-AT-06 y los patrones de RN-16 preguntan siempre
        // lo mismo: los ultimos escaneos de este empleado. DESC porque se lee
        // por el final.
        DB::statement(<<<'SQL'
            CREATE INDEX scan_events_employee_id_occurred_at_index
                ON scan_events (employee_id, occurred_at DESC)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX scan_events_device_id_occurred_at_index
                ON scan_events (device_id, occurred_at DESC)
        SQL);

        // La bandeja de revision de la tarea 2.5 lee solo las filas marcadas,
        // que son una minoria diminuta. Indice parcial: el resto del historico
        // ni entra.
        DB::statement(<<<'SQL'
            CREATE INDEX scan_events_flagged_for_review_index
                ON scan_events (recorded_at DESC)
                WHERE flagged_for_review
        SQL);

        // JSONB con GIN porque el paquete de diagnostico y el panel consultan
        // por clave dentro de `client_meta` (doc 02 §3.2). `jsonb_path_ops` es
        // la clase de operadores para la pregunta que se hace de verdad
        // —«contiene esta clave con este valor»—: indice mas pequeno y mas
        // rapido que el completo.
        DB::statement(<<<'SQL'
            CREATE INDEX scan_events_client_meta_gin
                ON scan_events USING gin (client_meta jsonb_path_ops)
        SQL);
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists('scan_events');
    }
};
