<?php

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevelopmentSeeder;
use Database\Seeders\SiteSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Container\Container;
use Illuminate\Database\Seeder;

/*
 * LA SEMILLA DE DESARROLLO NO SIEMBRA PRODUCCION (SC7-03).
 *
 * `UserSeeder` crea cuentas admin, rrhh y auditor con una contraseña publicada.
 * Si `db:seed --force` corre en la instalacion de un cliente, el panel queda con
 * una puerta trasera y el registro horario con fichajes inventados. La guarda
 * vive en `DevelopmentSeeder::__invoke` —lo que ejecutan `db:seed`, `db:seed
 * --class=...` y `$this->call(...)`— y se repite al principio de
 * `DatabaseSeeder::run()`.
 *
 * Suite unitaria: sin framework ni base de datos. El entorno se le da a la
 * semilla por el contenedor, que es de donde lo lee en un `db:seed` real
 * (`$app['env']`). Que NO ESCRIBE NADA se demuestra por construccion: el
 * contenedor de estas pruebas sustituye cada semilla hija por una que solo
 * anota su nombre, y las semillas reales, sin fachadas arrancadas, fallarian con
 * otro mensaje en cuanto tocaran `DB::`.
 *
 * La otra cerradura —que la semilla ni siquiera viaje en la imagen— la vigila
 * `SeedersOutOfProductionImageTest` (Architecture).
 */

/**
 * Contenedor con el entorno dado que, en vez de construir las semillas hijas,
 * devuelve una que anota que se ha llamado.
 *
 * @param  ArrayObject<int, string>  $llamadas
 */
$contenedor = static function (?string $entorno, ArrayObject $llamadas): Container {
    $container = new class($llamadas) extends Container
    {
        /** @param ArrayObject<int, string> $llamadas */
        public function __construct(private readonly ArrayObject $llamadas) {}

        /** @param array<array-key, mixed> $parameters */
        #[Override]
        public function make($abstract, array $parameters = []): mixed
        {
            if (str_starts_with($abstract, 'Database\\Seeders\\')) {
                $llamadas = $this->llamadas;

                return new class($abstract, $llamadas) extends Seeder
                {
                    /** @param ArrayObject<int, string> $llamadas */
                    public function __construct(private readonly string $nombre, private readonly ArrayObject $llamadas) {}

                    public function run(): void
                    {
                        $this->llamadas->append($this->nombre);
                    }
                };
            }

            return parent::make($abstract, $parameters);
        }
    };

    if ($entorno !== null) {
        $container->instance('env', $entorno);
    }

    return $container;
};

it('se niega a sembrar con APP_ENV=production y no llama a ninguna semilla', function () use ($contenedor): void {
    /** @var ArrayObject<int, string> $llamadas */
    $llamadas = new ArrayObject;
    $seeder = (new DatabaseSeeder)->setContainer($contenedor('production', $llamadas));

    expect(fn () => $seeder->__invoke())
        ->toThrow(RuntimeException::class, 'refuse to run in production')
        ->and($llamadas->getArrayCopy())->toBe([]);

    // Tambien por la puerta de atras: `run()` llamado a pelo, sin `__invoke`.
    expect(fn () => $seeder->run())
        ->toThrow(RuntimeException::class, 'refuse to run in production')
        ->and($llamadas->getArrayCopy())->toBe([]);
})->group('RS-08');

it('falla cerrada: sin entorno conocido se trata como produccion', function () use ($contenedor): void {
    /** @var ArrayObject<int, string> $llamadas */
    $llamadas = new ArrayObject;

    expect(fn () => (new DatabaseSeeder)->setContainer($contenedor(null, $llamadas))->__invoke())
        ->toThrow(RuntimeException::class, 'refuse to run in production')
        ->and(fn () => (new DatabaseSeeder)->run())
        ->toThrow(RuntimeException::class, 'refuse to run in production')
        ->and($llamadas->getArrayCopy())->toBe([]);
})->group('RS-08');

it('cada semilla suelta se niega tambien (db:seed --class=...)', function (string $fichero) use ($contenedor): void {
    $clase = 'Database\\Seeders\\'.basename($fichero, '.php');
    if (! class_exists($clase)) {
        throw new LogicException("{$fichero} no declara {$clase}: el autoload PSR-4 de la semilla no la encontraria.");
    }

    if (new ReflectionClass($clase)->isAbstract()) {
        expect($clase)->toBe(DevelopmentSeeder::class);

        return;
    }

    // Si una semilla nueva extiende `Seeder` y no `DevelopmentSeeder`, aqui no
    // salta la guarda: salta `DB::` sin fachadas, con otro mensaje, y falla.
    expect(new $clase)->toBeInstanceOf(DevelopmentSeeder::class);

    /** @var DevelopmentSeeder $seeder */
    $seeder = new $clase;
    $seeder->setContainer($contenedor('production', new ArrayObject));

    expect(fn () => $seeder->__invoke())->toThrow(RuntimeException::class, 'refuse to run in production');
})->with(fn (): array => glob(dirname(__DIR__, 3).'/database/seeders/*.php') ?: [])->group('RS-08');

it('fuera de produccion siembra todo, en el orden de DatabaseSeeder', function (string $entorno) use ($contenedor): void {
    /** @var ArrayObject<int, string> $llamadas */
    $llamadas = new ArrayObject;

    (new DatabaseSeeder)->setContainer($contenedor($entorno, $llamadas))->__invoke();

    // La guarda es estrecha: `make seed` y la suite de integracion
    // (`MigrationsRoundTripTest` siembra con `db:seed --force`) siguen
    // funcionando.
    expect($llamadas->getArrayCopy())->toContain(UserSeeder::class)
        ->and($llamadas->getArrayCopy()[0] ?? null)->toBe(SiteSeeder::class);
})->with(['local', 'testing'])->group('RS-08');
