<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los privilegios del rol de aplicacion, y la comprobacion de que ese rol **no
 * es** el que ejecuta las migraciones (regla dura 6, ADR-010, ADR-027).
 *
 * **El problema que resuelve, dicho sin rodeos.** Hasta la tarea 1.14, el
 * `DB_USERNAME` del `.env` era `POSTGRES_USER`, es decir, **superusuario del
 * cluster**. Sobre un superusuario, PostgreSQL **no comprueba privilegios**: el
 * `REVOKE UPDATE, DELETE ON audit_log` que exige la regla dura 6 se podia
 * escribir, se ejecutaba sin error y **no impedia nada**. La garantia sobre la
 * que se sostiene el valor probatorio del registro era, literalmente,
 * decorativa. Lo mismo, en menor grado, con el propietario de la tabla: puede
 * volver a otorgarse lo que se le revoque.
 *
 * Por eso hay tres roles (`infra/docker/postgres/initdb/02-application-roles.sh`)
 * y por eso esta migracion **se niega a correr** si quien la ejecuta es el rol
 * de la aplicacion. Una migracion que arreglase los permisos ejecutandose con el
 * rol al que hay que limitar seria la misma trampa con otro nombre.
 *
 * **Va la primera de todas** —y su nombre la coloca ahi— porque
 * `ALTER DEFAULT PRIVILEGES` solo alcanza a los objetos creados **despues**. Con
 * ella delante, cada tabla que creen las migraciones siguientes nace con los
 * permisos del rol de aplicacion ya puestos, sin que haya que acordarse tabla
 * por tabla. Las excepciones —`audit_log` y `audit_chain_anchors`— revocan lo
 * que sobra en su propia migracion, que es donde se ve por que.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();
        $this->assertRunningWithTheMigrationRole();

        $application = $this->quote(AuditLogSchema::applicationRole());
        $maintenance = $this->quote(AuditLogSchema::maintenanceRole());

        // Ver el esquema, sin poder crear nada en el. En PostgreSQL 15+ `PUBLIC`
        // ya no tiene `CREATE` sobre `public`; se revoca igual porque una base
        // restaurada de un cluster antiguo si lo tendria.
        DB::statement('GRANT USAGE ON SCHEMA public TO '.$application.', '.$maintenance);
        DB::statement('REVOKE CREATE ON SCHEMA public FROM '.$application.', '.$maintenance);
        DB::statement('REVOKE CREATE ON SCHEMA public FROM PUBLIC');

        // Lo que necesita la aplicacion sobre el resto del esquema: las cuatro
        // operaciones sobre las tablas y las secuencias de sus claves. Ni una
        // sola forma de DDL.
        DB::statement(
            'ALTER DEFAULT PRIVILEGES IN SCHEMA public '
            .'GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO '.$application
        );
        DB::statement(
            'ALTER DEFAULT PRIVILEGES IN SCHEMA public '
            .'GRANT USAGE, SELECT ON SEQUENCES TO '.$application
        );

        // Y lo mismo sobre lo que ya existe, para una instalacion que se
        // actualiza en lugar de nacer: `ALTER DEFAULT PRIVILEGES` no mira atras.
        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO '.$application);
        DB::statement('GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO '.$application);
    }

    public function down(): void
    {
        $this->limitLockWait();

        $application = $this->quote(AuditLogSchema::applicationRole());
        $maintenance = $this->quote(AuditLogSchema::maintenanceRole());

        DB::statement(
            'ALTER DEFAULT PRIVILEGES IN SCHEMA public '
            .'REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLES FROM '.$application
        );
        DB::statement(
            'ALTER DEFAULT PRIVILEGES IN SCHEMA public '
            .'REVOKE USAGE, SELECT ON SEQUENCES FROM '.$application
        );
        DB::statement('REVOKE ALL ON ALL TABLES IN SCHEMA public FROM '.$application);
        DB::statement('REVOKE ALL ON ALL SEQUENCES IN SCHEMA public FROM '.$application);
        DB::statement('REVOKE USAGE ON SCHEMA public FROM '.$application.', '.$maintenance);
    }

    /**
     * La comprobacion que convierte la regla dura 6 en algo verificable.
     *
     * Falla ANTES de tocar nada y dice exactamente que hacer. Es preferible a
     * una migracion que «funciona» dejando el registro probatorio sin proteger:
     * ese fallo no se ve hasta que alguien borra una fila.
     */
    private function assertRunningWithTheMigrationRole(): void
    {
        $application = AuditLogSchema::applicationRole();

        /** @var object{session_role: string}|null $session */
        $session = DB::selectOne('SELECT current_user AS session_role');
        $currentUser = $session->session_role ?? '';

        if ($currentUser === $application) {
            throw new RuntimeException(
                'Las migraciones se estan ejecutando con el rol de la aplicacion ('.$application.'). '
                ."Regla dura 6: ese rol no tiene DDL y no puede ser el propietario de `audit_log`.\n"
                .'Lanzalas sobre la conexion de migracion:  php artisan migrate --database=pgsql_migrator'
            );
        }

        /** @var object{rolsuper: bool}|null $role */
        $role = DB::selectOne('SELECT rolsuper FROM pg_roles WHERE rolname = ?', [$application]);

        if ($role === null) {
            throw new RuntimeException(
                'El rol de aplicacion «'.$application.'» no existe en el cluster. '
                .'Lo provisiona infra/docker/postgres/initdb/02-application-roles.sh, que se ejecuta al '
                .'inicializar el volumen de PostgreSQL y tambien se puede lanzar a mano sobre un cluster ya creado.'
            );
        }

        if ($role->rolsuper) {
            throw new RuntimeException(
                'El rol de aplicacion «'.$application.'» es SUPERUSUARIO del cluster. '
                .'PostgreSQL no comprueba privilegios para un superusuario: el REVOKE sobre `audit_log` no impediria nada '
                ."y la regla dura 6 seria decorativa.\n"
                .'Ejecuta infra/docker/postgres/initdb/02-application-roles.sh, que le retira los atributos.'
            );
        }
    }

    private function quote(string $role): string
    {
        return '"'.AuditLogSchema::assertIdentifier($role).'"';
    }
};
