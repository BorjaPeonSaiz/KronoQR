<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `device_pairing_requests` — la solicitud con la que una tablet se vincula
 * (**RF-PD-06**, tarea 5.6, doc 01 §5.5).
 *
 * ## Que guarda y por que hace falta una tabla
 *
 * El emparejamiento tiene tres pasos y dos actores: la tablet pide un codigo
 * (`POST /api/v1/kiosk/pair`), una persona con rol `admin` lo confirma
 * (`/pair/confirm`) y la tablet recoge su token (`/pair/claim`). Entre el primero
 * y el tercero pueden pasar minutos y las tres llamadas son peticiones HTTP
 * distintas, de clientes distintos y sin sesion comun: hace falta algo
 * persistente que las una. En Redis no, porque el estado «este codigo ya se uso»
 * decide si se emite o no un token de dispositivo, y esa decision no puede
 * depender de una cache que se vacia.
 *
 * ## Ni el codigo ni el secreto se guardan en claro
 *
 * `code_hash` y `secret_hash` son SHA-256, igual que `devices.token_hash` y
 * `credentials.secret_hash` (doc 01 §5). Un codigo en claro en la base de datos
 * es una credencial de alta de quiosco al alcance de cualquier volcado, y el
 * secreto de recogida es lo unico que separa a la tablet legitima de quien vio el
 * codigo por encima del hombro.
 *
 * ## El indice unico es PARCIAL, y ahi esta la invariante
 *
 * `device_pairing_requests_code_hash_pending_uidx` sobre `code_hash` **solo
 * mientras `status = 'pending'`**. Es lo que garantiza que un codigo de seis
 * digitos señale a una unica solicitud en el momento en que alguien lo teclea; y
 * es parcial y no total porque un codigo consumido tiene que poder volver a
 * salir por sorteo dentro de un año sin que la fila vieja lo impida. La
 * declaracion vive aqui y no solo en PHP (regla dura: las invariantes van en la
 * migracion): dos `POST /kiosk/pair` simultaneos que sortearan el mismo numero
 * chocan en el indice y uno reintenta, en vez de dejar dos solicitudes que un
 * `confirm` no sabria distinguir.
 *
 * ## Estados, y el que NO esta
 *
 * `pending` → `confirmed` → `claimed`. **«Caducada» no es un estado y no se
 * escribe**: se deriva de `expires_at < now`. Un estado almacenado que hay que
 * mantener al dia con el reloj necesita un proceso que lo mantenga, y el dia que
 * ese proceso no corra el sistema creeria que una solicitud de hace una hora
 * sigue viva. Lo derivable se deriva.
 *
 * ## Las dos claves ajenas son nullable, y cada una por su motivo
 *
 * `device_id` lo es porque una solicitud `pending` **todavia no ha creado ningun
 * dispositivo**: la fila de `devices` nace en el `confirm`. `confirmed_by_user_id`
 * lo es por lo mismo y ademas porque `kiosk:pairing-code` no tiene sesion (actor
 * `system`, doc 01 Anexo B) y porque la cuenta que confirmo puede darse de baja
 * despues sin que el hecho desaparezca. Ninguna de las dos es el registro legal
 * de quien hizo que: eso es `audit_log`, solo-append y encadenado (regla dura 6).
 *
 * ## Sin datos personales, y por eso se puede purgar
 *
 * Aqui no hay nada de nadie: dos hashes, una version de la PWA y unos instantes.
 * Las filas consumidas o caducadas se borran a las 24 h de forma perezosa, al
 * crear una solicitud nueva (`config/kiosk.php` → `pairing.purge_after_hours`).
 * No es retencion legal —no hay nada que retener— sino higiene: la tabla no
 * crece sin limite y el espacio de codigos no se agota.
 *
 * ## Permisos (ADR-033)
 *
 * No hay ningun `GRANT` aqui, y es lo correcto: la migracion `099000` declara
 * `ALTER DEFAULT PRIVILEGES ... GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES`
 * para el rol de la aplicacion, asi que toda tabla creada despues nace con esos
 * permisos. El rol de mantenimiento no aparece porque no tiene nada que hacer
 * aqui: su unico trabajo es soltar particiones de `audit_log` (ADR-027).
 *
 * **`DELETE` si hace falta** en esta tabla, al contrario que en casi todas las
 * demas (regla dura 5): la purga perezosa borra filas consumidas y caducadas. No
 * se contradice —aqui no hay ningun hecho con valor legal, solo dos hashes y unos
 * instantes— y el hecho que si lo tiene, el alta del quiosco, vive en `audit_log`.
 *
 * ## Plan de despliegue
 *
 * Expand puro y en un solo paso: tabla nueva, ninguna lectura ni escritura del
 * codigo anterior sobre ella. `down()` la borra entera y no pierde nada que
 * importe, porque un emparejamiento a medias se rehace pidiendo otro codigo
 * (regla dura 19: la tablet nunca queda atrapada).
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /**
     * Los tres estados que el producto sabe producir.
     *
     * En el `CHECK` ademas de en el enum de PHP: es la ultima linea de defensa
     * del §3.2. Una fila con un estado inventado —por una restauracion antigua o
     * por una version que ya no existe— dejaria al `claim` decidiendo si emite un
     * token a partir de algo que no sabe interpretar.
     *
     * @var list<string>
     */
    private const array STATUSES = ['pending', 'confirmed', 'claimed'];

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('device_pairing_requests', function (Blueprint $table): void {
            $table->id();

            // Identificador **publico**: el `pairing_id` que viaja en la
            // respuesta de `/kiosk/pair` y que la tablet reenvia en cada sondeo.
            // Nunca la clave interna: un identificador secuencial en una ruta
            // publica diria cuantas tablets se han dado de alta y en que orden
            // (regla dura 10).
            $table->uuid('uuid')->unique('device_pairing_requests_uuid_unique');

            // SHA-256 en hexadecimal: 64 caracteres. Se dimensiona a 128 como
            // `devices.token_hash` para no tener que migrar la columna si algun
            // dia el algoritmo cambia (una migracion de tipo bloquea la tabla).
            $table->string('code_hash', 128);
            $table->string('secret_hash', 128);

            $table->string('status', 16)->default('pending');

            // La version de la PWA que pidio emparejarse. Pasa a
            // `devices.app_version` al vincular, para que el panel sepa desde el
            // primer momento si la tablet llego actualizada (RF-KI-07).
            $table->string('app_version', 32)->nullable();

            $table->foreignId('device_id')
                ->nullable()
                ->constrained('devices')
                // Un dispositivo no se borra nunca (regla dura 5), asi que esto
                // no deberia dispararse. Si alguien lo borrara a mano, la
                // solicitud consumida se queda sin apuntar a nada en vez de
                // impedir la operacion: la fila es un rastro operativo de 24 h.
                ->nullOnDelete();

            $table->foreignId('confirmed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Regla dura 3: todos los instantes en UTC, con microsegundos.
            $table->timestampTz('expires_at', 6);
            $table->timestampTz('confirmed_at', 6)->nullable();
            $table->timestampTz('claimed_at', 6)->nullable();

            $table->timestampsTz(6);

            // La purga perezosa barre por `created_at`; sin indice seria un
            // recorrido completo en cada `POST /kiosk/pair`.
            $table->index('created_at', 'device_pairing_requests_created_at_idx');
        });

        DB::statement(sprintf(
            'ALTER TABLE device_pairing_requests ADD CONSTRAINT device_pairing_requests_chk_status CHECK (status IN (%s))',
            implode(', ', array_map(static fn (string $status): string => "'".$status."'", self::STATUSES)),
        ));

        // Una solicitud confirmada tiene dispositivo, y una que no lo esta no lo
        // tiene. Es la invariante que impide que el `claim` emita un token para
        // una fila que nadie confirmo: sin ella, bastaria un `UPDATE` mal escrito
        // para que el emparejamiento se saltara al administrador.
        DB::statement(<<<'SQL'
            ALTER TABLE device_pairing_requests
                ADD CONSTRAINT device_pairing_requests_chk_confirmed_has_device
                CHECK (
                    (status = 'pending' AND device_id IS NULL AND confirmed_at IS NULL)
                    OR (status <> 'pending' AND device_id IS NOT NULL AND confirmed_at IS NOT NULL)
                )
        SQL);

        // Y solo una solicitud consumida lleva instante de recogida.
        DB::statement(<<<'SQL'
            ALTER TABLE device_pairing_requests
                ADD CONSTRAINT device_pairing_requests_chk_claimed_at_matches_status
                CHECK ((status = 'claimed') = (claimed_at IS NOT NULL))
        SQL);

        // LA INVARIANTE DEL CODIGO. Parcial a proposito: ver el docblock.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX device_pairing_requests_code_hash_pending_uidx
                ON device_pairing_requests (code_hash)
                WHERE status = 'pending'
        SQL);
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists('device_pairing_requests');
    }
};
