<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La tabla `weekly_summary_deliveries` (doc 01 §5.5, **RF-PR-05**, decision 6 de
 * la ficha 3.12).
 *
 * ## Que registra y por que hace falta una tabla
 *
 * Que a una cuenta de gestion se le envio el resumen de una semana. Sirve para
 * dos cosas y la primera es la que obliga a que exista:
 *
 * 1. **Que no se envie dos veces.** El comando corre los lunes, pero tambien se
 *    ejecuta a mano con `--week=` y puede reintentarse tras un fallo. Un segundo
 *    correo con los mismos nombres es una segunda copia de datos personales
 *    fuera del sistema, y ademas es la forma mas rapida de que alguien deje de
 *    leer un aviso. Lo que lo impide no es una comprobacion en PHP —entre el
 *    `SELECT` y el `INSERT` cabe otra pasada— sino el `UNIQUE` de aqui abajo.
 *
 *    **La fila se escribe ANTES de enviar**, y por eso es una *reclamacion*: es
 *    la unica forma de que el `UNIQUE` arbitre la carrera. Si el correo no sale,
 *    quien la escribio la **borra** y la semana vuelve a estar pendiente. Es la
 *    unica fila del producto que se borra, y se borra porque nunca llego a
 *    describir un hecho: la regla dura 5 protege el registro horario y las
 *    evidencias, no una reserva de turno que no acabo en nada.
 * 2. **Saber que salio y cuando**, sin abrir `audit_log`. Aquel responde la
 *    pregunta legal —quien recibio datos de quien (RS-05, RL-15)— y tiene cuatro
 *    años de retencion; esta responde la operativa, que es la que se hace cada
 *    lunes: «¿se envio el de esta semana?».
 *
 * ## Patron `/migracion-segura`: creacion pura
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Esta migracion: una tabla nueva. Nada se renombra, nada se borra, ninguna tabla existente se toca. | La version anterior no la nombra y sigue funcionando. |
 * | **2 (migrate)** | *No aplica.* No habia resumenes semanales antes. | — |
 * | **3 (contract)** | *No aplica.* | — |
 *
 * Al nacer vacia, las columnas obligatorias y el indice unico se declaran ya en
 * vigor: no hay filas que recorrer ni nadie leyendo, asi que ninguna sentencia
 * bloquea nada (RNF-D-04). Por eso tampoco hace falta `CREATE INDEX
 * CONCURRENTLY`: no hay concurrencia sobre una tabla que se acaba de crear, y
 * `CONCURRENTLY` no puede ejecutarse dentro de la transaccion de la migracion.
 *
 * `lock_timeout` bajo igualmente, por {@see LimitsMigrationLocks}: la migracion
 * corre en el despliegue de un cliente con el producto en marcha, y una espera
 * indefinida por un candado es lo que convierte un despliegue en una parada.
 *
 * ## La invariante que declara el esquema, y no solo PHP
 *
 * **UN RESUMEN POR CUENTA Y SEMANA.** `UNIQUE (manager_user_id, week_start)`. Es
 * la unica forma de cerrar la carrera de dos pasadas simultaneas —dos `cron`
 * mal configurados, una ejecucion a mano encima de la programada— y es ademas lo
 * que hace que el caso de uso pueda escribir la fila **antes** de enviar: si
 * pierde la carrera, su transaccion entera se deshace y no sale ningun correo.
 *
 * ## Lo que NO esta aqui
 *
 * - **Ningun nombre y ningun dato de ningun empleado** (regla dura 21). Ni
 *   siquiera la lista de quienes salian en el resumen: eso es del asiento de
 *   `personal_data.accessed`, que es donde RS-05 lo pide y donde tiene su plazo
 *   de conservacion. Aqui solo hay recuentos.
 * - **El contenido del correo.** No se guarda: se puede reconstruir en cualquier
 *   momento desde el registro horario, que es la fuente (regla dura 7).
 * - **La direccion a la que se envio.** Es dato personal de esa cuenta y ya esta
 *   en `users`; guardar una copia aqui seria una segunda fuente que envejece.
 * - **`deleted_at` ni ningun borrado logico.** Una fila confirmada se queda para
 *   siempre: «¿se mando el resumen de la semana del 14?» hay que poder
 *   contestarlo despues. El unico `DELETE` que existe sobre esta tabla es el que
 *   retira una **reclamacion** cuyo correo no salio (ver arriba), y ahi no se
 *   pierde nada porque no llego a pasar nada.
 *
 * **Por eso `weekly_summary_deliveries` no entra en `RetentionScope`**, y no es
 * un olvido: una tabla necesita plazo cuando conserva datos personales de la
 * plantilla, y esta no conserva ninguno. Son un identificador de cuenta de
 * gestion, una fecha y dos numeros; el hecho completo —a quien se le fueron las
 * horas de quien— vive en `audit_log`, que si tiene plazo: cuatro años (RL-02).
 *
 * ## `manager_user_id` es NOT NULL, y con `RESTRICT`
 *
 * Siempre hay una cuenta: el resumen es suyo. El producto **no borra cuentas de
 * gestion** —las desactiva con `identity:deactivate-user`, regla dura 5—, asi
 * que la restriccion no estorba a ningun camino real; lo que hace es obligar a
 * que, si algun dia se borrara una, alguien decida antes que pasa con su
 * historial de envios en lugar de descubrirlo despues.
 *
 * ## `down()`
 *
 * Suelta la tabla. Es legitimo: **no se pierde ninguna evidencia con obligacion
 * de conservacion**, porque cada envio consta ademas en `audit_log` como
 * `personal_data.accessed` con el conjunto `weekly_summary`, que es solo-apendice
 * y se conserva cuatro años (RL-02). Lo que desaparece al revertir es el control
 * de «ya enviado», y la consecuencia de revertir es que la pasada siguiente
 * podria repetir el correo de esa semana — molesto, nunca incorrecto.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('weekly_summary_deliveries', function (Blueprint $table): void {
            $table->id();

            /*
             * La cuenta de gestion que recibio el resumen. Ver el docblock: NOT
             * NULL y `RESTRICT`.
             */
            $table->foreignId('manager_user_id')->constrained('users')->restrictOnDelete();

            /*
             * EL LUNES DE LA SEMANA RESUMIDA, en el calendario civil del centro.
             *
             * `date` y no `timestamptz`: «la semana del 14 de septiembre» no
             * tiene hora ni zona. La regla dura 3 habla de **instantes** —lo que
             * ocurre en un momento— y esto es una fecha de calendario, como
             * `work_date` en `shift_entries` o `daily_totals`. Guardarla como
             * instante la habria movido de semana en cada conversion.
             */
            $table->date('week_start');

            /*
             * El instante de la pasada, que es cuando salio el correo salvo por
             * los milisegundos que separan la reclamacion del envio. Instante, y
             * por tanto `TIMESTAMPTZ` en UTC (regla dura 3).
             */
            $table->timestampTz('sent_at', 6);

            /*
             * Recuentos, no personas (regla dura 21). Sirven para contestar «el
             * resumen salio, pero ¿iba vacio?» sin reconstruirlo: un envio con
             * cero lineas señala un alcance mal asignado, no una semana sin
             * trabajo.
             */
            $table->integer('employee_count');
            $table->integer('row_count');

            /*
             * Solo `created_at`. Una fila de esta tabla **no se actualiza
             * nunca**: se escribe entera de una vez —con sus recuentos ya
             * calculados— y despues solo puede quedarse o desaparecer.
             * `updated_at` seria una columna que siempre vale lo mismo que la de
             * al lado y que invitaria a modificar lo que no se modifica.
             */
            $table->timestampTz('created_at', 6);

            /*
             * LA INVARIANTE (ver el docblock): un resumen por cuenta y semana.
             * Sirve ademas la consulta `wasSent()`, que es la unica lectura que
             * el producto hace de esta tabla.
             */
            $table->unique(['manager_user_id', 'week_start'], 'weekly_summary_deliveries_unique');
        });
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists('weekly_summary_deliveries');
    }
};
