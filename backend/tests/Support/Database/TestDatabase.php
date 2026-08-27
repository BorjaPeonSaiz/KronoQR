<?php

declare(strict_types=1);

namespace Tests\Support\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * La base de datos de la suite de integracion, creada si no existe.
 *
 * **Por que una base aparte y no la de desarrollo.** Las pruebas de integracion
 * usan `RefreshDatabase`, que empieza con un `migrate:fresh`. Contra `fichaje`,
 * cada `make test` se llevaria por delante las 220.000 filas del `VolumeSeeder`
 * —y cualquier caso que alguien estuviera reproduciendo a mano— sin decir nada.
 * Una suite que destruye el entorno de quien la ejecuta se acaba ejecutando
 * poco.
 *
 * **Por que se crea aqui y no solo en `initdb`.** El script de arranque del
 * cluster (`infra/docker/postgres/initdb/01-test-database.sql`) solo corre
 * sobre un volumen vacio, asi que quien ya tuviera el entorno levantado no lo
 * veria nunca y la suite fallaria con un «database does not exist» que no dice
 * que hacer. Esto es una comprobacion y elimina el escalon.
 *
 * **Por que sin facades ni `config()`.** Se llama desde el `beforeAll` de Pest,
 * que es `setUpBeforeClass`: ahi la aplicacion todavia no esta arrancada y no
 * hay contenedor de servicios. Se lee del entorno —que es de donde lo lee
 * tambien `config/database.php`— y se abre una conexion PDO propia.
 *
 * Las dos guardas —solo sobre un nombre acabado en `_test` y solo en la
 * conexion `pgsql`— no son ceremonia: `CREATE DATABASE` desde una suite de
 * pruebas solo es aceptable si no puede tocar nada que importe.
 */
final class TestDatabase
{
    private static bool $checked = false;

    public static function ensureExists(): void
    {
        if (self::$checked) {
            return;
        }

        self::$checked = true;

        if (self::fromEnvironment('DB_CONNECTION', 'pgsql') !== 'pgsql') {
            return;
        }

        $database = self::fromEnvironment('DB_DATABASE', 'fichaje_test');

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                'La suite de integracion apunta a la base «'.$database.'», que no acaba en «_test». '
                .'Revisa DB_DATABASE en phpunit.xml antes de seguir: RefreshDatabase la vaciaria entera.',
            );
        }

        self::createIfMissing($database);
    }

    private static function createIfMissing(string $database): void
    {
        $host = self::fromEnvironment('DB_HOST', '127.0.0.1');
        $port = self::fromEnvironment('DB_PORT', '5432');

        try {
            // Se pregunta desde la base de mantenimiento porque no se puede
            // crear una base estando conectado a ella. Y se hace con el rol de
            // MIGRACION: desde la tarea 1.14 el de la aplicacion no tiene
            // CREATEDB (regla dura 6), asi que `CREATE DATABASE` con el rol de
            // runtime fallaria por permisos, que es justo lo que se quiere.
            $maintenance = new PDO(
                'pgsql:host='.$host.';port='.$port.';dbname=postgres',
                self::fromEnvironment('DB_MIGRATION_USERNAME', 'fichaje_migrator'),
                self::fromEnvironment('DB_MIGRATION_PASSWORD', ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );

            $statement = $maintenance->prepare('SELECT 1 FROM pg_database WHERE datname = ?');
            $statement->execute([$database]);

            if ($statement->fetchColumn() === false) {
                // El nombre no puede ir como parametro enlazado en un
                // `CREATE DATABASE`. La guarda del sufijo y el hecho de que
                // venga de phpunit.xml lo acotan.
                $maintenance->exec('CREATE DATABASE "'.$database.'"');
            }
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'La suite de integracion necesita PostgreSQL en '.$host.':'.$port.' y no ha podido conectarse. '
                .'Levanta el entorno con `make up` antes de ejecutarla. Detalle: '.$exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * `getenv()` y no el ayudante `env()` de Laravel: ese necesita la
     * aplicacion arrancada, y aqui todavia no lo esta.
     */
    private static function fromEnvironment(string $key, string $fallback): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $fallback : $value;
    }
}
