<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\SupportScope;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La tabla `support_grants` (doc 01 §5, **RF-PD-11**, RL-18, ADR-020, tarea
 * 5.9).
 *
 * ## Lo primero que hay que saber de esta migracion
 *
 * **Al aplicarla, el fabricante no gana ningun acceso.** La tabla nace vacia y
 * «sin ninguna concesion» es el estado normal y permanente de una instalacion:
 * el soporte se presta con el paquete de diagnostico y el acceso directo es la
 * excepcion (ADR-020, regla dura 16). Lo que esta tabla añade no es una puerta:
 * es la cerradura, el registro de quien abrio y el temporizador que la cierra
 * sola.
 *
 * ## Patron `/migracion-segura`: creacion pura
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Esta migracion: una tabla nueva. Nada se renombra, nada se borra, ninguna tabla existente se toca. | La version anterior no la nombra y sigue funcionando. |
 * | **2 (migrate)** | *No aplica.* No habia concesiones antes. | — |
 * | **3 (contract)** | *No aplica.* | — |
 *
 * Al ser una tabla nueva y vacia, sus columnas nacen con su forma definitiva y
 * sus `CHECK` validados: nada de esto bloquea a nadie porque no hay nadie
 * leyendola todavia.
 *
 * ## Las dos invariantes que declara el esquema, y no solo PHP
 *
 * 1. **`scope` es uno de tres.** El catalogo esta en
 *    {@see SupportScope} y decide que
 *    puede hacer el token que se emite. Una fila con `scope = 'todo'` insertada
 *    a mano en una madrugada de incidencia seria una concesion cuyo alcance no
 *    sabria resolver nadie, y el lado seguro de ese error es que la base de
 *    datos la rechace.
 * 2. **`expires_at > granted_at`.** Una concesion que nace caducada, o que
 *    caduca antes de existir, no es un acceso: es una fila que contradice el
 *    requisito de caducidad efectiva de RF-PD-11.
 *
 * ## Lo que NO esta aqui
 *
 * - **El token en claro.** Solo su `token_hash` (SHA-256 del secreto de
 *   Sanctum), exactamente como `devices.token_hash`: la fila se explica sola
 *   —«¿esta concesion llego a emitir token?»— sin unirse a
 *   `personal_access_tokens`, y el valor que autentica no esta en ninguno de los
 *   dos sitios.
 * - **Ningun dato de empleado.** Aqui no los hay y no puede haberlos: lo que se
 *   concede es una potestad sobre la instalacion, no sobre una persona (regla
 *   dura 21).
 * - **`deleted_at` ni nada que borre.** Revocar **marca**, nunca elimina (regla
 *   dura 5): una concesion revocada o caducada sigue en la lista con sus fechas,
 *   porque la pregunta que responde esta tabla —«¿entro el fabricante en mi
 *   instalacion, cuando y por que?»— hay que poder contestarla años despues.
 *
 * ## Los dos indices, y por que solo dos
 *
 * `GET /api/v1/support/grants` devuelve las 100 mas recientes ordenadas por
 * `granted_at DESC`, y el resto de consultas de la tabla son «¿que concesiones
 * siguen vivas?» —`revoked_at IS NULL AND expires_at > now()`—, que es la que
 * hace `support:revoke --all` y la que resuelve el panel. Los dos indices
 * parciales cubren exactamente eso. La tabla tendra decenas de filas en la vida
 * de una instalacion, asi que cualquier indice de mas seria mantenimiento sin
 * lectura que lo justifique.
 *
 * ## Sin ningun `GRANT` aqui, y es lo correcto
 *
 * La migracion `099000` declara `ALTER DEFAULT PRIVILEGES ... GRANT SELECT,
 * INSERT, UPDATE, DELETE ON TABLES` para el rol de la aplicacion (ADR-033), asi
 * que esta tabla nace con sus permisos puestos. Repetirlo aqui daria dos sitios
 * donde mirar el dia que alguien se pregunte quien puede escribir en ella.
 *
 * **Y a diferencia de `audit_log`, aqui el rol de la aplicacion SI tiene
 * `UPDATE`**: revocar es un `UPDATE` y anotar un uso tambien. Lo que protege la
 * evidencia no es el permiso sobre esta tabla —es que cada concesion, cada uso y
 * cada revocacion dejan ademas su asiento en `audit_log`, donde ese mismo rol no
 * puede ni actualizar ni borrar (regla dura 6).
 *
 * ## `down()`
 *
 * Suelta la tabla, y es legitimo: es la reversion de la migracion que la creo y
 * **no se pierde ninguna evidencia con obligacion de conservacion**. Cada
 * concesion, cada uso y cada revocacion constan ademas en `audit_log`, que es
 * solo-apendice, encadenado por hash y con cuatro años de retencion (RL-02). Lo
 * que desaparece al revertir es la cerradura, no el registro de quien abrio — y
 * sin cerradura no hay acceso, que es el lado seguro.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('support_grants', function (Blueprint $table): void {
            $table->id();

            /*
             * El identificador PUBLICO, el que viaja en la URL del `DELETE` y en
             * la respuesta.
             *
             * Por lo mismo que el del quiosco y el de la credencial: la clave
             * interna no sale de la base de datos, y un numero secuencial en la
             * ruta diria cuantas veces ha entrado soporte en este hotel — que es
             * justo el dato que el cliente no tiene por que publicar.
             */
            $table->uuid('uuid')->unique();

            /*
             * Quien la concedio. **Siempre una cuenta de gestion**, nunca nulo:
             * conceder acceso al fabricante es un acto de una persona con nombre
             * y apellidos, y una concesion sin autor no se puede defender ante
             * el cliente ni ante una inspeccion (RL-18).
             *
             * `restrict` y no `nullOnDelete`: la cuenta que concedio el acceso no
             * se puede borrar dejando la concesion huerfana. En este producto las
             * cuentas se desactivan (`is_active`), no se borran, asi que la
             * restriccion no estorba a ninguna operacion real.
             */
            $table->foreignId('granted_by_user_id')->constrained('users')->restrictOnDelete();

            /*
             * Para que incidente. **Obligatorio** (RF-PD-11) y de 200
             * caracteres: cabe «Incidencia #123: la cola del quiosco de
             * recepcion no vacia» y no cabe un volcado de log.
             *
             * Va tal cual al asiento de auditoria. Es lo que convierte «alguien
             * de soporte entro el martes» en «entro por esto», y por tanto lo
             * unico que permite ver el abuso que ADR-020 describe: usar la sesion
             * abierta para un incidente distinto del que la motivo.
             */
            $table->string('reason', 200);

            /*
             * Que puede hacer. `CHECK` mas abajo: el catalogo es cerrado.
             */
            $table->string('scope', 32);

            $table->timestampTz('granted_at', 6);

            /*
             * Cuando deja de valer, **sin que nadie haga nada** (RF-PD-11,
             * «caducidad efectiva»). El token de Sanctum se emite con esta misma
             * caducidad, asi que el acceso muere por dos caminos independientes:
             * el token expira y la comprobacion de sesion de `Identity` deja de
             * aceptarlo.
             */
            $table->timestampTz('expires_at', 6);

            /*
             * Cuando se revoco, si se revoco. Revocar borra los tokens de la fila
             * y marca aqui: el efecto es inmediato, en la peticion siguiente.
             */
            $table->timestampTz('revoked_at', 6)->nullable();

            /*
             * Quien la revoco. Nulo cuando la revoco la consola —`support:revoke`
             * lo ejecuta quien tiene acceso al servidor, y ahi no hay sesion que
             * atribuir— y nulo tambien en las filas que nunca se revocaron.
             *
             * `nullOnDelete` al contrario que el concedente: lo que no puede
             * pasar es que una concesion deje de poder leerse porque se borro una
             * cuenta. Quien revoco sigue estando en `audit_log`.
             */
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Ultimo uso EFECTIVO del token, no de la concesion.
             *
             * Lo escribe el middleware `RecordSupportAccess` en cada peticion
             * autenticada con este token. Es la mitad «visible para el cliente»
             * de RF-PD-11: el panel dice «usado por ultima vez hace dos horas»
             * sin que nadie tenga que leer `audit_log`.
             *
             * Nulo mientras no se use, y una concesion que caduca en nulo es
             * informacion util: se concedio un acceso que no hizo falta.
             */
            $table->timestampTz('accessed_at', 6)->nullable();

            /*
             * SHA-256 del secreto del token de Sanctum, igual que
             * `devices.token_hash`. Nulo solo en la ventana —inexistente en la
             * practica, porque se escribe en la misma transaccion— entre crear la
             * fila y emitir su token.
             *
             * 128 caracteres para no atarse a la longitud de un algoritmo
             * concreto: hoy son 64.
             */
            $table->string('token_hash', 128)->nullable();

            $table->timestampsTz(6);
        });

        /*
         * El catalogo de alcances, cerrado por el esquema.
         *
         * Sin `NOT VALID` porque la tabla esta vacia: no hay nada que validar
         * despues y el `CHECK` nace en vigor. El dominio comprueba lo mismo al
         * construir la concesion; esto es la red que atrapa un `INSERT` hecho a
         * mano.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE support_grants
                ADD CONSTRAINT support_grants_chk_scope
                CHECK (scope IN ('diagnostics', 'read_only', 'configuration'))
        SQL);

        /*
         * Una concesion dura algo. `>` y no `>=`: una que caduca en el instante
         * en que se concede no da acceso a nada y solo puede ser un error de
         * quien la inserto.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE support_grants
                ADD CONSTRAINT support_grants_chk_validity_ordered
                CHECK (expires_at > granted_at)
        SQL);

        /*
         * Revocar es posterior a conceder. Igual que arriba: no impide ninguna
         * operacion legitima —se puede revocar en el mismo segundo, de ahi el
         * `>=`— y atrapa una fila incoherente.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE support_grants
                ADD CONSTRAINT support_grants_chk_revocation_ordered
                CHECK (revoked_at IS NULL OR revoked_at >= granted_at)
        SQL);

        /*
         * Las concesiones VIVAS, que es la unica consulta con forma de filtro que
         * hace el producto: `support:revoke --all` y el recuento del panel.
         * Indice parcial porque las revocadas no se consultan nunca por esta via
         * y son, con el tiempo, casi todas.
         */
        DB::statement(<<<'SQL'
            CREATE INDEX support_grants_active_index
                ON support_grants (expires_at)
                WHERE revoked_at IS NULL
        SQL);

        // El orden del listado: las 100 mas recientes, de la mas nueva a la mas
        // antigua.
        DB::statement('CREATE INDEX support_grants_recent_index ON support_grants (granted_at DESC)');
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists('support_grants');
    }
};
