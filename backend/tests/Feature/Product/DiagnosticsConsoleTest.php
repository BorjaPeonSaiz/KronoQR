<?php

declare(strict_types=1);

use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `php artisan product:diagnostics` (Anexo C del doc 01, **RF-PD-09**, RL-19).
 *
 * Es la via que se usa cuando el panel no responde —que es la mitad de las veces
 * en que hace falta un paquete—, y por eso escribe un fichero y **dice la
 * ruta**: quien esta por SSH necesita algo que pasar a `scp`.
 *
 * Lo que se prueba: que el valor por defecto sea el anonimizado, que los datos
 * personales exijan una accion distinta y avisada, que la salida diga la ruta y
 * como inspeccionar el fichero antes de enviarlo, y que `--verify` distinga un
 * paquete intacto de uno alterado.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
    app()->instance(Clock::class, FixedClock::at('2026-06-15 09:00:00'));

    Config::set('product.diagnostics_storage_path', directorioDePaquetes());
});

/** Un directorio propio de cada prueba, para no heredar paquetes de otra. */
function directorioDePaquetes(): string
{
    static $directory = null;

    $directory ??= sys_get_temp_dir().'/kronoqr-diagnostics-'.bin2hex(random_bytes(6));

    return $directory;
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{code: int, output: string}
 */
function runDiagnostics(array $parameters = []): array
{
    $code = Artisan::call('product:diagnostics', [...$parameters, '--no-interaction' => true]);

    return ['code' => $code, 'output' => Artisan::output()];
}

/** La ruta del unico paquete escrito. */
function paqueteEscrito(): string
{
    $files = glob(directorioDePaquetes().'/*.json');

    expect($files)->toBeArray();
    expect($files === [] ? null : $files)->not->toBeNull('El comando no ha escrito ningun paquete.');

    /** @var list<string> $files */
    return $files[0];
}

afterEach(function (): void {
    foreach (glob(directorioDePaquetes().'/*.json') ?: [] as $file) {
        @unlink($file);
    }
});

it('escribe el paquete y dice la ruta exacta', function (): void {
    $result = runDiagnostics();

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('Paquete de diagnostico generado.')
        ->and($result['output'])->toContain(paqueteEscrito())
        // Y como inspeccionarlo antes de enviarlo, que es lo que la ficha 5.9
        // exige que el cliente pueda hacer.
        ->and($result['output'])->toContain('less ')
        ->and($result['output'])->toContain('--verify=');
})->group('RF-PD-09', 'RF-PD-13');

it('el paquete por defecto va anonimizado y lo dice', function (): void {
    // El valor por defecto ES el producto (ADR-020): sin banderas, sin datos
    // personales, y el comando lo afirma para que quien lo envia lo sepa.
    $result = runDiagnostics();

    /** @var array<string, mixed> $document */
    $document = json_decode((string) file_get_contents(paqueteEscrito()), true, 512, JSON_THROW_ON_ERROR);

    /** @var array<string, mixed> $manifest */
    $manifest = $document['manifest'];

    expect($manifest['anonymized'])->toBeTrue()
        ->and($manifest['generated_by'])->toBe('console')
        ->and($document)->not->toHaveKey('personal_data')
        ->and($result['output'])->toContain('Anonimo:  si')
        ->and($result['output'])->toContain('no lleva nombres, ni correos, ni fichajes');
})->group('RF-PD-09', 'RL-19');

it('--anonymized es un alias explicito y no cambia nada', function (): void {
    runDiagnostics(['--anonymized' => true]);

    /** @var array<string, mixed> $document */
    $document = json_decode((string) file_get_contents(paqueteEscrito()), true, 512, JSON_THROW_ON_ERROR);

    /** @var array<string, mixed> $manifest */
    $manifest = $document['manifest'];

    expect($manifest['anonymized'])->toBeTrue();
})->group('RF-PD-09');

it('escribe el fichero con permisos cerrados', function (): void {
    // El paquete anonimizado no lleva datos personales, pero el que se pide con
    // `--with-personal-data` si, y los dos acaban en el mismo directorio. Se
    // escribe cerrado siempre: distinguir por contenido es una decision que
    // algun dia se toma mal.
    runDiagnostics();

    expect(substr(sprintf('%o', (int) fileperms(paqueteEscrito())), -3))->toBe('600');
})->group('RF-PD-09', 'RL-19');

it('--with-personal-data avisa de lo que incluye antes de generarlo', function (): void {
    // RL-19: «aviso en la interfaz que explica que se va a incluir y que
    // implica». En consola, la interfaz es esta salida.
    $result = runDiagnostics(['--with-personal-data' => true]);

    expect($result['output'])->toContain('ATENCION: vas a generar un paquete CON DATOS PERSONALES.')
        ->and($result['output'])->toContain('La plantilla: nombre, codigo de empleado')
        ->and($result['output'])->toContain('queda registrada en el registro de auditoria')
        ->and($result['output'])->toContain('encargado del tratamiento')
        // Y la alternativa, para quien solo tenia un problema tecnico.
        ->and($result['output'])->toContain('Si solo quieres diagnosticar un problema tecnico');
})->group('RF-PD-09', 'RL-19');

it('--with-personal-data audita la inclusion como accion distinta', function (): void {
    $siteId = WorkforceFixtures::onlySiteId();
    WorkforceFixtures::employee($siteId, firstName: 'Marta', lastName: 'Lopez');

    runDiagnostics(['--with-personal-data' => true, '--period-days' => 7]);

    // DOS asientos, no uno con un booleano: es el punto entero de RL-19.
    expect(DB::table('audit_log')->where('action', 'diagnostics.bundle_generated')->count())->toBe(1)
        ->and(DB::table('audit_log')->where('action', 'diagnostics.personal_data_included')->count())->toBe(1);

    /** @var object{payload: string} $entry */
    $entry = DB::table('audit_log')->where('action', 'diagnostics.personal_data_included')->first();

    /** @var array<string, mixed> $payload */
    $payload = json_decode($entry->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($payload['period_days'])->toBe(7)
        ->and($payload['collections'])->toContain('employees')
        ->and($payload['generated_by'])->toBe('console')
        // Ni un dato de nadie en el asiento que registra la salida de datos
        // personales, que seria absurdo (regla dura 21).
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('Marta');
})->group('RF-PD-09', 'RL-19', 'RL-04');

it('rechaza un periodo fuera del maximo y dice como corregirlo', function (): void {
    $result = runDiagnostics(['--with-personal-data' => true, '--period-days' => 400]);

    expect($result['output'])->toContain('tiene que estar entre 1 y 31 dias')
        ->and($result['output'])->toContain('--period-days=7')
        ->and(glob(directorioDePaquetes().'/*.json'))->toBe([]);
})->group('RF-PD-09', 'RL-19');

// --- Retencion en disco (RL-19) ---------------------------------------------

it('borra al arrancar los paquetes caducados y lo dice', function (): void {
    // UN PAQUETE ES MATERIAL CADUCADO EN CUANTO SE ENVIA. El anonimizado ocupa
    // sitio; el que se pidio con datos personales es una copia de la plantilla y
    // de los fichajes de un periodo, en el disco del cliente. RL-19 autoriza a
    // generarlo para una incidencia, no a conservarlo indefinidamente.
    @mkdir(directorioDePaquetes(), 0o700, true);

    $viejo = directorioDePaquetes().'/kronoqr-diagnostics-2.0.0-20260101T000000Z.json';
    $reciente = directorioDePaquetes().'/kronoqr-diagnostics-2.0.0-20260614T000000Z.json';

    file_put_contents($viejo, '{}');
    file_put_contents($reciente, '{}');

    // El reloj de la prueba esta en 2026-06-15 y la retencion es de 7 dias: el
    // de enero cae, el de ayer no.
    touch($viejo, (int) (new DateTimeImmutable('2026-05-01T00:00:00Z'))->getTimestamp());
    touch($reciente, (int) (new DateTimeImmutable('2026-06-14T00:00:00Z'))->getTimestamp());

    $result = runDiagnostics();

    expect(is_file($viejo))->toBeFalse('El paquete caducado sigue en el disco.')
        ->and(is_file($reciente))->toBeTrue('Se ha borrado un paquete que aun no habia caducado.')
        // Y lo dice: un borrado silencioso en el disco de un cliente es una
        // sorpresa, aunque sea el correcto.
        ->and($result['output'])->toContain('Se han borrado 1 paquete(s) anterior(es) por antiguedad')
        ->and($result['output'])->toContain('no debe quedarse en el disco');

    @unlink($reciente);
})->group('RF-PD-09', 'RL-19');

it('respeta el plazo configurado', function (): void {
    // El plazo es configuracion y no una constante (regla dura 13): un cliente
    // con una politica mas dura lo baja sin tocar el repositorio.
    Config::set('product.diagnostics_retention_days', 1);

    @mkdir(directorioDePaquetes(), 0o700, true);
    $deAyer = directorioDePaquetes().'/kronoqr-diagnostics-2.0.0-20260614T000000Z.json';
    file_put_contents($deAyer, '{}');
    touch($deAyer, (int) (new DateTimeImmutable('2026-06-13T00:00:00Z'))->getTimestamp());

    runDiagnostics();

    expect(is_file($deAyer))->toBeFalse();
})->group('RF-PD-09', 'RL-19');

it('no dice nada cuando no hay nada caducado que borrar', function (): void {
    // Un aviso que sale siempre deja de leerse.
    $result = runDiagnostics();

    expect($result['output'])->not->toContain('Se han borrado');
})->group('RF-PD-09');
// --- Verificacion de un paquete ya escrito ----------------------------------

it('--verify devuelve 0 con un paquete intacto', function (): void {
    runDiagnostics();

    $result = runDiagnostics(['--verify' => paqueteEscrito()]);

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('Paquete integro')
        ->and($result['output'])->toContain('Anonimo:   si');
})->group('RF-PD-09');

it('--verify devuelve 2 con un solo byte cambiado', function (): void {
    // El paquete viaja SIN CIFRAR a proposito, para que el cliente pueda mirarlo
    // antes de enviarlo. La contrapartida es que se puede truncar por el camino,
    // y esta es la comprobacion que lo detecta en un segundo en lugar de tras
    // una hora de diagnostico sobre un fichero a medias.
    runDiagnostics();

    $path = paqueteEscrito();
    $content = (string) file_get_contents($path);

    file_put_contents($path, str_replace('"app_env": "', '"app_env": "X', $content));

    $result = runDiagnostics(['--verify' => $path]);

    expect($result['code'])->toBe(2)
        ->and($result['output'])->toContain('LA HUELLA NO COINCIDE')
        // Y que hacer con ello: pedir que lo vuelvan a generar y enviar de otra
        // forma.
        ->and($result['output'])->toContain('vuelva a generar');
})->group('RF-PD-09');

it('--verify devuelve 2 si el fichero no existe o no es un paquete', function (): void {
    $result = runDiagnostics(['--verify' => directorioDePaquetes().'/no-existe.json']);

    expect($result['code'])->toBe(2)
        ->and($result['output'])->toContain('No se ha podido leer un paquete de diagnostico');
})->group('RF-PD-09');

it('--verify no genera nada', function (): void {
    runDiagnostics();
    $antes = glob(directorioDePaquetes().'/*.json');

    runDiagnostics(['--verify' => paqueteEscrito()]);

    expect(glob(directorioDePaquetes().'/*.json'))->toBe($antes);
})->group('RF-PD-09');

it('--output respeta la ruta que se le da', function (): void {
    $destino = directorioDePaquetes().'/para-el-ticket-123.json';

    $result = runDiagnostics(['--output' => $destino]);

    expect(is_file($destino))->toBeTrue()
        ->and($result['output'])->toContain($destino);

    @unlink($destino);
})->group('RF-PD-09');
