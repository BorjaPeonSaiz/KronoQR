<?php

declare(strict_types=1);

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Application\Port\DoctorTranslator;
use App\Modules\Product\Application\UseCase\RunDoctorHandler;
use App\Modules\Product\Domain\ValueObject\DoctorCheck;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Product\Infrastructure\Diagnostics\ServiceInspector;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `php artisan product:doctor` (Anexo C del doc 01, **RF-PD-13**).
 *
 * ## Que se prueba aqui, y por que
 *
 * **Los codigos de salida antes que nada**, porque este comando no lo consume
 * solo una persona: lo invocan `install.sh` en su fase de verificacion y
 * `update.sh` tras arrancar, y los dos traducen **solo el `2`** al `6` de su
 * tabla comun. Cambiar esos numeros rompe dos scripts en el servidor de un
 * cliente sin que nada mas se entere.
 *
 * **Que no explote con la base de datos caida**, que es literalmente el caso en
 * el que este comando existe. Si `doctor` reventara ahi, la persona de
 * informatica del hotel se quedaria con un volcado de pila en lugar de un
 * informe.
 *
 * **Que cada linea roja diga que hacer.** El fabricante no tiene acceso a este
 * servidor (ADR-016): un mensaje que solo diga que fallo obliga a una llamada.
 *
 * Se usa `Artisan::call()` y no `$this->artisan()` porque hay que afirmar sobre
 * la salida COMPLETA, y no linea a linea.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::grantAll();
    app()->instance(Clock::class, FixedClock::at('2026-06-15 09:00:00'));
});

/**
 * @param  array<string, mixed>  $parameters
 * @return array{code: int, output: string}
 */
function runDoctor(array $parameters = []): array
{
    $code = Artisan::call('product:doctor', $parameters);

    return ['code' => $code, 'output' => Artisan::output()];
}

/**
 * Sustituye las sondas por las que la prueba necesita.
 *
 * El caso de uso recibe la lista ya montada desde el proveedor, asi que fijar el
 * escenario es sustituir el enlace: no hace falta romper Postgres de verdad para
 * comprobar que el comando sobrevive a que lo este.
 *
 * @param  list<DoctorProbe>  $probes
 */
function conSondas(array $probes): void
{
    app()->bind(RunDoctorHandler::class, static fn (): RunDoctorHandler => new RunDoctorHandler(
        probes: $probes,
        translator: app(DoctorTranslator::class),
        clock: app(Clock::class),
        productVersion: 'test',
    ));
}

/** Una sonda que devuelve lo que se le diga. */
function sondaQueDevuelve(string $family, DoctorFinding ...$findings): DoctorProbe
{
    return new class($family, array_values($findings)) implements DoctorProbe
    {
        /** @param  list<DoctorFinding>  $findings */
        public function __construct(private string $family, private array $findings) {}

        public function family(): string
        {
            return $this->family;
        }

        public function run(): array
        {
            return $this->findings;
        }
    };
}

// --- Codigos de salida ------------------------------------------------------

it('devuelve 0 con la base de datos sana y nada que mirar', function (): void {
    conSondas([sondaQueDevuelve('database', DoctorFinding::ok('database.connection'))]);

    $result = runDoctor();

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('Resultado: CORRECTO')
        ->and($result['output'])->toContain('No hay nada que hacer.');
})->group('RF-PD-13');

it('devuelve 1 cuando solo hay avisos, y dice que no bloquea nada', function (): void {
    // El `1` lo enseñan `install.sh` y `update.sh` **sin abortar**. Que el propio
    // comando lo diga con esas palabras es lo que evita la llamada de quien lo
    // ve por primera vez durante una instalacion.
    conSondas([sondaQueDevuelve('tls', DoctorFinding::warning('tls.certificate', 'unreachable', ['port' => 443]))]);

    $result = runDoctor();

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('Resultado: CON AVISOS')
        ->and($result['output'])->toContain('La instalacion y la actualizacion NO se paran por esto.');
})->group('RF-PD-13');

it('devuelve 2 con APP_TIMEZONE distinto de UTC', function (): void {
    // Regla dura 3: el desplazamiento que produce **no se puede deshacer**, asi
    // que es la comprobacion que justifica que el instalador ejecute `doctor`.
    Config::set('app.timezone', 'Europe/Madrid');

    $result = runDoctor(['--lang' => 'es']);

    expect($result['code'])->toBe(2)
        ->and($result['output'])->toContain('app.timezone_utc')
        ->and($result['output'])->toContain('Europe/Madrid')
        ->and($result['output'])->toContain('APP_TIMEZONE=UTC');
})->group('RF-PD-13');

it('devuelve 2 con el modo de depuracion encendido en produccion', function (): void {
    Config::set('app.env', 'production');
    Config::set('app.debug', true);

    $result = runDoctor(['--lang' => 'es']);

    expect($result['code'])->toBe(2)
        ->and($result['output'])->toContain('app.debug_in_production')
        ->and($result['output'])->toContain('APP_DEBUG=false');
})->group('RF-PD-13', 'RS-08');

// --- No explota -------------------------------------------------------------

it('informa y no explota con la conexion a la base de datos rota', function (): void {
    // ES EL CASO EN EL QUE ESTE COMANDO EXISTE, y aqui falla de verdad: una
    // conexion nueva contra un host que no resuelve, no un doble que devuelve
    // `false`. Lo que se comprueba es que el `PDOException` real se queda dentro
    // de la sonda.
    //
    // Se construye una conexion APARTE en lugar de romper la de la aplicacion:
    // `RefreshDatabase` mantiene una transaccion abierta sobre esa, y tirarla
    // haria fallar el desmontaje de la prueba por una razon que no es la que se
    // esta comprobando.
    $roto = DB::build([
        'driver' => 'pgsql',
        'host' => 'no-existe.invalido',
        'port' => 5432,
        'database' => 'fichaje',
        'username' => 'fichaje_app',
        'password' => 'irrelevante',
    ]);

    app()->bind(ServiceInspector::class, static fn (): ServiceInspector => new ServiceInspector(
        database: $roto,
        redis: app(Redis::class),
        migrationsPath: database_path('migrations'),
        queueConnection: 'redis',
        queueName: 'default',
        realtimeEnabled: true,
        broadcastConnection: 'reverb',
    ));

    $result = runDoctor(['--lang' => 'es']);

    expect($result['code'])->toBe(2)
        ->and($result['output'])->toContain('database.connection')
        ->and($result['output'])->toContain('No se puede conectar con la base de datos')
        // Y las otras familias siguen comprobandose: un fallo de base de datos
        // no puede dejar al cliente sin saber nada del disco ni del certificado.
        ->and($result['output'])->toContain('disk.storage')
        // Que hacer, con el comando entero.
        ->and($result['output'])->toContain('docker compose ps')
        // La contraseña de la conexion no aparece por ningun lado (regla dura
        // 21): del fallo sale la CLASE de la excepcion, nunca su mensaje.
        ->and($result['output'])->not->toContain('irrelevante')
        ->and($result['output'])->not->toContain('SQLSTATE');
})->group('RF-PD-13', 'RS-08');

it('sobrevive a una sonda que revienta y lo dice sin filtrar el mensaje', function (): void {
    // El mensaje de una excepcion puede llevar el DSN, y el DSN la contraseña.
    // Solo sale la CLASE (regla dura 21, ADR-020).
    conSondas([
        new class implements DoctorProbe
        {
            public function family(): string
            {
                return 'queue';
            }

            public function run(): array
            {
                throw new RuntimeException('SQLSTATE[08006] password=superssecreta host=postgres');
            }
        },
        sondaQueDevuelve('disk', DoctorFinding::ok('disk.storage')),
    ]);

    $result = runDoctor(['--lang' => 'es']);

    expect($result['code'])->toBe(2)
        ->and($result['output'])->toContain('queue.probe')
        ->and($result['output'])->not->toContain('superssecreta')
        ->and($result['output'])->not->toContain('SQLSTATE')
        // Y las demas sondas se ejecutan igual.
        ->and($result['output'])->toContain('disk.storage');
})->group('RF-PD-13', 'RS-08');

// --- Informe ----------------------------------------------------------------

it('imprime primero lo que hay que mirar y despues lo correcto', function (): void {
    conSondas([
        sondaQueDevuelve('disk', DoctorFinding::ok('disk.storage')),
        sondaQueDevuelve('app', DoctorFinding::failure('app.timezone_utc', params: ['timezone' => 'Europe/Madrid'])),
    ]);

    $result = runDoctor(['--lang' => 'es']);

    expect(strpos($result['output'], '[FALLO]'))
        ->toBeLessThan((int) strpos($result['output'], 'Comprobaciones correctas'));
})->group('RF-PD-13');

it('cada hallazgo lleva su «que hacer»', function (): void {
    // La condicion que hace util el comando cuando el fabricante no puede entrar
    // al servidor (ADR-016). El contrato lo exige: `fix` no nulo salvo en `ok`.
    $report = app(RunDoctorHandler::class)->handle('es');

    foreach ($report->problems() as $check) {
        expect($check->fix)->toBeString($check->id.' no dice que hacer.')
            ->and($check->fix)->not->toStartWith('doctor.fixes.', $check->id.' no tiene texto traducido.');
    }

    foreach ($report->checks as $check) {
        // Y ninguna frase se queda sin traducir, que es como se detecta una
        // clave nueva sin su texto en el primer `doctor` que la toque.
        expect($check->summary)->not->toStartWith('doctor.checks.', $check->id.' no tiene texto traducido.');
    }
})->group('RF-PD-13');

it('imprime el informe en ingles con --lang=en', function (): void {
    Config::set('app.timezone', 'Europe/Madrid');

    $result = runDoctor(['--lang' => 'en']);

    expect($result['output'])->toContain('The application is running in the «Europe/Madrid» time zone')
        ->and($result['output'])->toContain('APP_TIMEZONE=UTC');
})->group('RF-PD-13');

it('devuelve el informe como JSON con la forma del contrato', function (): void {
    // Es la estructura que consumen `install.sh`, `update.sh` y la seccion
    // `doctor` del paquete: las tres tienen que ver lo mismo.
    $result = runDoctor(['--json' => true]);

    /** @var array<string, mixed> $report */
    $report = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);

    expect($report)->toHaveKeys(['status', 'exit_code', 'checked_at', 'product_version', 'checks'])
        ->and($report['exit_code'])->toBe($result['code'])
        ->and($report['checked_at'])->toBe('2026-06-15T09:00:00.000000Z');

    /** @var list<array<string, mixed>> $checks */
    $checks = $report['checks'];

    foreach ($checks as $check) {
        expect($check)->toHaveKeys(['id', 'status', 'summary', 'fix', 'details'])
            ->and($check['status'])->toBeIn(['ok', 'warning', 'failure']);

        if ($check['status'] === 'ok') {
            expect($check['fix'])->toBeNull();
        } else {
            expect($check['fix'])->toBeString();
        }
    }
})->group('RF-PD-13');

it('comprueba que el usuario de la aplicacion no puede tocar el registro de auditoria', function (): void {
    // Regla dura 6. Es la comprobacion mas facil de perder de todo el producto
    // —basta una restauracion con el usuario equivocado— y la que nadie echa de
    // menos hasta que una inspeccion la pregunta.
    $report = app(RunDoctorHandler::class)->handle('es');

    $privileges = array_values(array_filter(
        $report->checks,
        static fn (DoctorCheck $check): bool => $check->id === 'database.audit_log_privileges',
    ));

    expect($privileges)->toHaveCount(1)
        ->and($privileges[0]->status)->toBe(DoctorStatus::Ok)
        ->and($privileges[0]->details['can_update'])->toBeFalse()
        ->and($privileges[0]->details['can_delete'])->toBeFalse();
})->group('RF-PD-13', 'RS-07');

it('sin --lang, todo el informe sale en el idioma de la instalacion', function (): void {
    // EL DEFECTO QUE ESTA PRUEBA CIERRA: el marco del informe estaba escrito en
    // español dentro del comando y las comprobaciones se traducian con
    // `APP_LOCALE`. En cuanto los dos no coincidian —el caso normal, porque el
    // idioma del panel se cambia desde el panel y `APP_LOCALE` se queda como lo
    // dejo el instalador— salia un informe con el titulo en español y los
    // «que hacer» en ingles. Nadie lee un informe a dos idiomas.
    //
    // Se fuerza la peor combinacion posible: `APP_LOCALE=en` con la instalacion
    // en `es`. Manda la instalacion (ADR-017).
    Config::set('app.locale', 'en');
    Config::set('app.timezone', 'Europe/Madrid');

    $result = runDoctor();

    expect($result['code'])->toBe(2)
        // El marco, en español.
        ->and($result['output'])->toContain('Diagnostico de KronoQR')
        ->and($result['output'])->toContain('Que hacer:')
        ->and($result['output'])->toContain('Resultado: CON FALLOS')
        // Y el `summary` y el `fix` de las comprobaciones, en el MISMO idioma.
        ->and($result['output'])->toContain('La aplicacion esta trabajando en la zona horaria')
        ->and($result['output'])->toContain('Las pantallas seguiran enseñando la hora del hotel')
        // Ni una frase del ingles se cuela.
        ->and($result['output'])->not->toContain('The application is running')
        ->and($result['output'])->not->toContain('What to do:')
        ->and($result['output'])->not->toContain('Check that');
})->group('RF-PD-13');

it('con --lang=en el marco tambien cambia de idioma', function (): void {
    // La otra mitad: si solo cambiaran las comprobaciones, el informe volveria a
    // salir a dos idiomas, esta vez al reves.
    Config::set('app.timezone', 'Europe/Madrid');

    $result = runDoctor(['--lang' => 'en']);

    expect($result['output'])->toContain('KronoQR diagnostics')
        ->and($result['output'])->toContain('What to do:')
        ->and($result['output'])->toContain('Result: WITH FAILURES')
        ->and($result['output'])->not->toContain('Diagnostico de KronoQR')
        ->and($result['output'])->not->toContain('Que hacer:');
})->group('RF-PD-13');

it('elige el idioma de la instalacion aunque la base de datos no responda', function (): void {
    // Leer `LOCALE_DEFAULT` es una consulta a la base de datos, y `doctor` se
    // ejecuta justamente cuando puede estar caida. Si eso dejara al comando sin
    // informe, la correccion del idioma habria roto lo unico que este comando
    // promete.
    $roto = DB::build([
        'driver' => 'pgsql',
        'host' => 'no-existe.invalido',
        'port' => 5432,
        'database' => 'fichaje',
        'username' => 'fichaje_app',
        'password' => 'irrelevante',
    ]);

    app()->bind(ServiceInspector::class, static fn (): ServiceInspector => new ServiceInspector(
        database: $roto,
        redis: app(Redis::class),
        migrationsPath: database_path('migrations'),
        queueConnection: 'redis',
        queueName: 'default',
        realtimeEnabled: true,
        broadcastConnection: 'reverb',
    ));

    $result = runDoctor();

    expect($result['code'])->toBe(2)
        ->and($result['output'])->toContain('Diagnostico de KronoQR')
        ->and($result['output'])->toContain('Resultado: CON FALLOS');
})->group('RF-PD-13');
