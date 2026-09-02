<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La tabla `license` (doc 01 §5, **RF-PD-04**, ADR-018, tarea 5.3).
 *
 * ## Lo primero que hay que saber de esta migracion
 *
 * **Al aplicarla, la instalacion no cambia de comportamiento.** La tabla nace
 * vacia, «sin licencia» es un estado normal del producto y todo —el fichaje, la
 * consulta del registro, la exportacion para la Inspeccion, el portal, las
 * copias— sigue funcionando igual (regla dura 15, ADR-019). Lo unico que ocurre
 * sin clave activada es que las funcionalidades **accesorias** de ADR-023 no
 * estan disponibles y el panel enseña un aviso que dice como activarla.
 *
 * ## Patron `/migracion-segura`: creacion pura
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Esta migracion: una tabla nueva. Nada se renombra, nada se borra, ninguna tabla existente se toca. | La version anterior no la nombra y sigue funcionando. |
 * | **2 (migrate)** | *No aplica.* No hay datos que trasladar: no existia ninguna licencia antes. | — |
 * | **3 (contract)** | *No aplica.* | — |
 *
 * Al ser una tabla nueva y vacia, sus columnas nacen ya con su forma definitiva
 * y sus `CHECK` validados: nada de esto bloquea a nadie porque no hay nadie
 * leyendola todavia.
 *
 * ## Una fila, garantizada por el esquema
 *
 * Hay una licencia por instalacion, como hay un centro (ADR-040). El indice
 * unico `one_license` va sobre una **expresion constante**: cualquier segunda
 * fila choca contra el, venga de donde venga. Es la misma tecnica con la que
 * `installation_settings` garantiza una fila por clave, y es preferible a
 * confiar en que el repositorio se acuerde de hacer `UPDATE` en vez de `INSERT`.
 *
 * ## `signed_key` es la afirmacion; el resto es proyeccion legible
 *
 * La unica columna con valor probatorio comercial es `signed_key`: es lo que el
 * fabricante firmo. `customer_name`, `plan`, `max_employees`, `max_devices`,
 * `features` y las tres fechas son la **misma informacion descompuesta**, para
 * que una consulta de diagnostico o una copia de seguridad se puedan leer sin
 * verificar nada.
 *
 * Que puedan divergir es un riesgo conocido y aceptado, y esta resuelto donde
 * toca: **el estado que gobierna el producto se recalcula siempre desde
 * `signed_key`**, nunca desde estas columnas. Si alguien edita `valid_until` con
 * `psql`, la firma deja de cuadrar, el estado pasa a `unverifiable` y el sistema
 * sigue funcionando degradado. Es el comportamiento correcto para un control
 * **comercial** (doc 01 §8.1): no se refuerza a costa de bloquear el registro.
 *
 * ## Lo que NO esta aqui
 *
 * - **`max_sites` no existe** (ADR-040 punto 5). Una licencia es un centro.
 * - **Ninguna clave privada.** En el servidor del cliente solo vive la clave
 *   publica del fabricante, que va en `config/license.php` (§7.7, RS-08).
 * - **Ningun historico de activaciones.** Cada activacion queda en `audit_log`
 *   con actor, momento, plan y limites; guardarlas tambien aqui daria dos
 *   fuentes de verdad sobre cual esta vigente.
 *
 * ## `down()`
 *
 * Suelta la tabla. Es legitimo porque es la reversion de la migracion que la
 * creo y porque **no se pierde ningun dato con obligacion de conservacion**: lo
 * unico que desaparece es la clave activada, que el cliente vuelve a pegar, y
 * cuya activacion sigue constando en `audit_log`. Tras revertir, el sistema
 * queda «sin licencia»: degradado en lo accesorio y entero en lo legal.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('license', function (Blueprint $table): void {
            $table->id();

            /*
             * La clave firmada completa, tal y como la pego quien la activo.
             *
             * `text` y no `string`: una clave lleva el nombre del cliente, el
             * plan, los limites, la lista de funcionalidades y dos fechas dentro
             * de un JSON firmado, y su longitud depende de eso. Un limite
             * arbitrario aqui produciria el peor de los fallos posibles —una
             * clave valida que no se puede activar en el hotel que la compro—
             * por ahorrar unos bytes en una tabla de una fila.
             */
            $table->text('signed_key');

            // --- Proyeccion legible de lo que dice la clave -------------------

            $table->string('license_id', 64);
            $table->string('customer_name', 200);
            $table->string('plan', 60);
            $table->integer('max_employees');
            $table->integer('max_devices');

            /*
             * Las funcionalidades ACCESORIAS habilitadas (ADR-023).
             *
             * `jsonb` y no una tabla aparte: es una lista corta y cerrada que se
             * lee entera o no se lee, y que solo cambia cuando cambia la clave.
             * El conjunto legal no aparece aqui y no puede aparecer: no tiene
             * nombre en el catalogo `Feature`, asi que no hay forma de escribir
             * su desactivacion.
             */
            $table->jsonb('features');

            $table->timestampTz('valid_from', 6);
            $table->timestampTz('valid_until', 6);
            $table->timestampTz('issued_at', 6);

            // --- Estado de la activacion --------------------------------------

            $table->timestampTz('activated_at', 6);

            /*
             * Quien la activo. `NULL` cuando la activo el instalador o la
             * consola: ahi no hay sesion detras y no se inventa una.
             *
             * `nullOnDelete` y no `restrict`: si algun dia se borra esa cuenta,
             * lo que no puede pasar es que la licencia deje de poder leerse.
             * Quien la activo sigue estando en `audit_log`, que es la prueba.
             */
            $table->foreignId('activated_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Ultima vez que la firma se comprobo con exito (ADR-018).
             *
             * `NULL` no deberia darse —la activacion la escribe— pero se admite
             * para que una restauracion parcial o una fila insertada a mano no
             * dejen la tabla sin poder leerse.
             */
            $table->timestampTz('last_verified_at', 6)->nullable();
        });

        /*
         * Una fila y solo una. El indice sobre una expresion constante es lo que
         * lo garantiza: con una segunda fila, `(true)` se repite y el `INSERT`
         * falla. Mismo criterio que `one_setting_per_key`.
         */
        DB::statement('CREATE UNIQUE INDEX one_license ON license ((true))');

        /*
         * Las cifras del plan son positivas y la vigencia no va hacia atras.
         *
         * Sin `NOT VALID` porque la tabla esta vacia: no hay nada que validar
         * despues y el `CHECK` nace en vigor. El dominio comprueba lo mismo al
         * construir la licencia; esto es la red que atrapa un `INSERT` hecho a
         * mano en una madrugada de incidencia.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE license
                ADD CONSTRAINT license_chk_limits_positive
                CHECK (max_employees > 0 AND max_devices > 0)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE license
                ADD CONSTRAINT license_chk_validity_ordered
                CHECK (valid_until >= valid_from)
        SQL);

        /*
         * `features` es una LISTA JSON, no un objeto ni un escalar.
         *
         * Lo comprueba el esquema porque el codigo que la lee es tolerante a
         * proposito —descarta lo que no conoce en vez de romperse— y esa
         * tolerancia, sin esta restriccion, convertiria un `features: "todo"`
         * escrito a mano en «ninguna funcionalidad habilitada» sin que nadie se
         * enterase.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE license
                ADD CONSTRAINT license_chk_features_is_array
                CHECK (jsonb_typeof(features) = 'array')
        SQL);
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists('license');
    }
};
