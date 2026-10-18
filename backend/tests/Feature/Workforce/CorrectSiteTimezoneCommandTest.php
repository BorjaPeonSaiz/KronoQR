<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Command\RegisterScanCommand;
use App\Modules\Attendance\Application\UseCase\RegisterScanHandler;
use App\Modules\Shared\Domain\ValueObject\CredentialResolution;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Application\Command\CorrectSiteTimezoneCommand;
use App\Modules\Workforce\Application\UseCase\CorrectSiteTimezoneHandler;
use App\Modules\Workforce\Domain\Exception\InvalidSiteTimezoneCorrection;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `workforce:correct-site-timezone`: corregir la zona horaria de un centro que
 * ya tiene registro horario (RN-23, ADR-056 §3).
 *
 * Lo que estas pruebas fijan:
 *
 * - **No recalcula nada**: `shift_entries` y `daily_totals` quedan identicos,
 *   fila a fila, y la cadena de `audit_log` sigue integra.
 * - **Deja `site.updated`** con `via: console`, el motivo, el numero de tramos y
 *   la cuenta que responde, firmado por esa cuenta.
 * - **Cada negativa no escribe nada**, con el codigo de salida que promete la
 *   orden: `2` para lo que esta mal escrito, `1` para lo que no se corrige por
 *   aqui o lo que la persona no confirmo.
 */

uses(RefreshDatabase::class);

const CORRECT_SITE_TIMEZONE_MOTIVO = 'La puesta en marcha se hizo con Madrid y el hotel esta en Tenerife.';

beforeEach(function (): void {
    FrozenTime::at('2026-03-14 10:00:00');
    app()->setLocale('es');
});

/**
 * Un centro en Madrid con un turno de una persona, fichado de verdad: entrada,
 * salida, proyeccion y asientos.
 *
 * @return array{site: int, employee: string}
 */
function correccionDeZonaConRegistro(): array
{
    /** @var array{site: int, department: int, employee: string, device: int, deviceUuid: string, token: string} $escenario */
    $escenario = AttendanceFixtures::scenario('Europe/Madrid');
    $handler = app(RegisterScanHandler::class);

    foreach (['2026-03-14T06:00:00Z', '2026-03-14T09:30:00Z'] as $instante) {
        $handler->handle(new RegisterScanCommand(
            scanId: Str::uuid7()->toString(),
            qrPayload: 'FH1.a3.tarjetacorreccion00.firma',
            occurredAt: new DateTimeImmutable($instante),
            deviceId: $escenario['device'],
            deviceUuid: $escenario['deviceUuid'],
        ), CredentialResolution::resolved($escenario['employee']));
    }

    return ['site' => $escenario['site'], 'employee' => $escenario['employee']];
}

/**
 * El registro horario y su proyeccion, fila a fila, para compararlos antes y
 * despues.
 *
 * @return array{entries: array<int, array<mixed>>, totals: array<int, array<mixed>>}
 */
function correccionDeZonaRegistro(): array
{
    return [
        'entries' => DB::table('shift_entries')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
        'totals' => DB::table('daily_totals')->orderBy('employee_id')->orderBy('work_date')->get()->map(static fn (object $row): array => (array) $row)->all(),
    ];
}

function correccionDeZonaResponsable(): int
{
    return ManagementUsers::withRole(UserRole::RRHH)->id;
}

/**
 * @param  array<string, string|int|bool>  $opciones
 * @return array{code: int, output: string}
 */
function correccionDeZonaOrden(array $opciones): array
{
    $code = Artisan::call('workforce:correct-site-timezone', $opciones);

    return ['code' => $code, 'output' => Artisan::output()];
}

/**
 * La orden con una terminal que responde `$respuesta` a la confirmacion.
 *
 * Por el nucleo de consola y no con `$this->artisan()`: hay que afirmar sobre
 * la salida y el codigo a la vez, y la pregunta la contesta la entrada como lo
 * haria una persona.
 *
 * @param  array<string, string>  $opciones
 * @return array{code: int, output: string}
 */
function correccionDeZonaOrdenInteractiva(array $opciones, string $respuesta): array
{
    $stream = fopen('php://memory', 'r+');
    \assert(\is_resource($stream));
    fwrite($stream, $respuesta."\n");
    rewind($stream);

    $input = new ArrayInput(['command' => 'workforce:correct-site-timezone', ...$opciones]);
    $input->setStream($stream);
    $input->setInteractive(true);
    $output = new BufferedOutput;

    $code = app(ConsoleKernel::class)->handle($input, $output);

    return ['code' => $code, 'output' => $output->fetch()];
}

function correccionDeZonaAsientos(): int
{
    return DB::table('audit_log')->where('action', 'site.updated')->count();
}

it('corrige la zona sin tocar ni un tramo ni un total, y la cadena sigue integra', function (): void {
    $escenario = correccionDeZonaConRegistro();
    $responsable = correccionDeZonaResponsable();
    $antes = correccionDeZonaRegistro();

    expect($antes['entries'])->toHaveCount(1)
        ->and($antes['totals'])->toHaveCount(1);

    $orden = correccionDeZonaOrden([
        'timezone' => 'Atlantic/Canary',
        '--reason' => CORRECT_SITE_TIMEZONE_MOTIVO,
        '--responsible' => (string) $responsable,
        '--force' => true,
    ]);

    expect($orden['code'])->toBe(0, $orden['output'])
        ->and($orden['output'])->toContain('Zona corregida de Europe/Madrid a Atlantic/Canary')
        ->and(DB::table('sites')->where('id', $escenario['site'])->value('timezone'))->toBe('Atlantic/Canary')
        // RN-13, regla dura 5: nada recalculado, nada reescrito.
        ->and(correccionDeZonaRegistro())->toBe($antes)
        ->and(Artisan::call('compliance:verify-audit-chain'))->toBe(0, Artisan::output());
})->group('RN-23', 'RN-13', 'RN-06');

it('deja site.updated con el motivo, el recuento y la cuenta que responde, firmado por ella', function (): void {
    $escenario = correccionDeZonaConRegistro();
    $responsable = correccionDeZonaResponsable();

    correccionDeZonaOrden([
        'timezone' => 'Atlantic/Canary',
        '--reason' => '  '.CORRECT_SITE_TIMEZONE_MOTIVO.'  ',
        '--responsible' => (string) $responsable,
        '--force' => true,
    ]);

    /** @var object{actor_type: string, actor_id: int, subject_id: string, payload: string} $asiento */
    $asiento = DB::table('audit_log')->where('action', 'site.updated')->sole();
    /** @var array<string, mixed> $payload */
    $payload = json_decode($asiento->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($asiento->actor_type)->toBe('user')
        ->and($asiento->actor_id)->toBe($responsable)
        ->and($payload['via'])->toBe('console')
        ->and($payload['reason'])->toBe(CORRECT_SITE_TIMEZONE_MOTIVO)
        ->and($payload['shift_entry_count'])->toBe(1)
        ->and($payload['responsible_user_id'])->toBe($responsable)
        ->and($payload['previous_value'])->toMatchArray(['timezone' => 'Europe/Madrid'])
        ->and($payload['new_value'])->toMatchArray(['timezone' => 'Atlantic/Canary'])
        // La fila que la funcion apunto en la traza: la conciliacion la
        // empareja con este asiento por identidad.
        ->and($payload['site_timezone_change_id'])->toBe(DB::table('site_timezone_changes')->max('id'))
        ->and(DB::table('sites')->where('id', $escenario['site'])->value('name'))
        ->toBe(is_array($payload['new_value']) ? $payload['new_value']['name'] : null);
})->group('RN-23', 'RL-04');

it('cuenta tambien los tramos anulados', function (): void {
    // Un tramo anulado se ficho con la zona de entonces y sigue en el registro
    // legal: el recuento del asiento dice cuanto historico pasa a presentarse
    // con la zona nueva.
    correccionDeZonaConRegistro();
    DB::table('shift_entries')->update(['status' => 'voided']);

    correccionDeZonaOrden([
        'timezone' => 'Atlantic/Canary',
        '--reason' => CORRECT_SITE_TIMEZONE_MOTIVO,
        '--responsible' => (string) correccionDeZonaResponsable(),
        '--force' => true,
    ]);

    /** @var object{payload: string} $asiento */
    $asiento = DB::table('audit_log')->where('action', 'site.updated')->sole();
    /** @var array{shift_entry_count: int} $payload */
    $payload = json_decode($asiento->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($payload['shift_entry_count'])->toBe(1);
})->group('RN-23');

it('ensena la zona actual, la nueva, los tramos, las jornadas y el aviso, y no corrige si no se confirma', function (): void {
    $escenario = correccionDeZonaConRegistro();

    $orden = correccionDeZonaOrdenInteractiva([
        'timezone' => 'Atlantic/Canary',
        '--reason' => CORRECT_SITE_TIMEZONE_MOTIVO,
        '--responsible' => (string) correccionDeZonaResponsable(),
    ], 'no');

    expect($orden['code'])->toBe(1, $orden['output'])
        ->and($orden['output'])->toContain('Zona actual del centro: Europe/Madrid')
        ->and($orden['output'])->toContain('Zona corregida:          Atlantic/Canary')
        ->and($orden['output'])->toContain('1 tramo(s), de la jornada 2026-03-14 a la 2026-03-14')
        ->and($orden['output'])->toContain('No se mueve ninguna jornada ni se recalcula nada')
        ->and($orden['output'])->toContain('¿Corregir la zona horaria del centro?')
        ->and($orden['output'])->toContain('Cancelado. No se ha cambiado nada.')
        ->and(DB::table('sites')->where('id', $escenario['site'])->value('timezone'))->toBe('Europe/Madrid')
        ->and(correccionDeZonaAsientos())->toBe(0);
})->group('RN-23');

it('corrige si se confirma', function (): void {
    $escenario = correccionDeZonaConRegistro();

    $orden = correccionDeZonaOrdenInteractiva([
        'timezone' => 'Atlantic/Canary',
        '--reason' => CORRECT_SITE_TIMEZONE_MOTIVO,
        '--responsible' => (string) correccionDeZonaResponsable(),
    ], 'yes');

    expect($orden['code'])->toBe(0, $orden['output'])
        ->and(DB::table('sites')->where('id', $escenario['site'])->value('timezone'))->toBe('Atlantic/Canary')
        ->and(correccionDeZonaAsientos())->toBe(1);
})->group('RN-23');

it('sin terminal y sin --force sale INVALID sin cambiar nada', function (): void {
    $escenario = correccionDeZonaConRegistro();

    $orden = correccionDeZonaOrden([
        'timezone' => 'Atlantic/Canary',
        '--reason' => CORRECT_SITE_TIMEZONE_MOTIVO,
        '--responsible' => (string) correccionDeZonaResponsable(),
        '--no-interaction' => true,
    ]);

    expect($orden['code'])->toBe(2)
        ->and($orden['output'])->toContain('--force')
        ->and(DB::table('sites')->where('id', $escenario['site'])->value('timezone'))->toBe('Europe/Madrid')
        ->and(correccionDeZonaAsientos())->toBe(0);
})->group('RN-23');

it('se niega sin escribir nada cuando la orden esta mal escrita', function (array $opciones, string $dice): void {
    $escenario = correccionDeZonaConRegistro();
    $responsable = correccionDeZonaResponsable();

    $orden = correccionDeZonaOrden([
        'timezone' => 'Atlantic/Canary',
        '--reason' => CORRECT_SITE_TIMEZONE_MOTIVO,
        '--responsible' => (string) $responsable,
        '--force' => true,
        ...$opciones,
    ]);

    expect($orden['code'])->toBe(2, $orden['output'])
        ->and($orden['output'])->toContain($dice)
        ->and(DB::table('sites')->where('id', $escenario['site'])->value('timezone'))->toBe('Europe/Madrid')
        ->and(correccionDeZonaAsientos())->toBe(0);
})->with([
    'sin motivo' => [['--reason' => ''], 'faltan --reason y --responsible'],
    'sin responsable' => [['--responsible' => ''], 'faltan --reason y --responsible'],
    'responsable que no es un id' => [['--responsible' => '12abc'], 'tiene que ser el id de la cuenta'],
    'motivo corto' => [['--reason' => 'Error.   '], 'al menos 10 caracteres'],
    'motivo largo' => [['--reason' => str_repeat('a', 1001)], 'como mucho 1000 caracteres'],
    'responsable que no existe' => [['--responsible' => '999999'], 'no es una cuenta de gestión activa'],
    'zona que no existe' => [['timezone' => 'Europe/Atlantida'], 'no existe'],
])->group('RN-23');

it('no acepta como responsable una cuenta dada de baja', function (): void {
    $escenario = correccionDeZonaConRegistro();
    $responsable = correccionDeZonaResponsable();
    DB::table('users')->where('id', $responsable)->update(['is_active' => false]);

    $orden = correccionDeZonaOrden([
        'timezone' => 'Atlantic/Canary',
        '--reason' => CORRECT_SITE_TIMEZONE_MOTIVO,
        '--responsible' => (string) $responsable,
        '--force' => true,
    ]);

    expect($orden['code'])->toBe(2)
        ->and(DB::table('sites')->where('id', $escenario['site'])->value('timezone'))->toBe('Europe/Madrid')
        ->and(correccionDeZonaAsientos())->toBe(0);
})->group('RN-23');

it('con la misma zona no hace nada y lo dice', function (): void {
    correccionDeZonaConRegistro();

    $orden = correccionDeZonaOrden([
        'timezone' => 'Europe/Madrid',
        '--reason' => CORRECT_SITE_TIMEZONE_MOTIVO,
        '--responsible' => (string) correccionDeZonaResponsable(),
        '--force' => true,
    ]);

    expect($orden['code'])->toBe(0)
        ->and($orden['output'])->toContain('ya tiene la zona Europe/Madrid')
        ->and(correccionDeZonaAsientos())->toBe(0);
})->group('RN-23');

it('sin registro horario remite al panel y no cambia nada', function (): void {
    $site = WorkforceFixtures::site('Hotel sin fichajes', 'Europe/Madrid');

    $orden = correccionDeZonaOrden([
        'timezone' => 'Atlantic/Canary',
        '--reason' => CORRECT_SITE_TIMEZONE_MOTIVO,
        '--responsible' => (string) correccionDeZonaResponsable(),
        '--force' => true,
    ]);

    expect($orden['code'])->toBe(1)
        ->and($orden['output'])->toContain('la zona se cambia desde el panel')
        ->and(DB::table('sites')->where('id', $site)->value('timezone'))->toBe('Europe/Madrid')
        ->and(correccionDeZonaAsientos())->toBe(0);
})->group('RN-23');

it('sin centro remite a la puesta en marcha', function (): void {
    $orden = correccionDeZonaOrden([
        'timezone' => 'Atlantic/Canary',
        '--reason' => CORRECT_SITE_TIMEZONE_MOTIVO,
        '--responsible' => (string) correccionDeZonaResponsable(),
        '--force' => true,
    ]);

    expect($orden['code'])->toBe(1)
        ->and($orden['output'])->toContain('asistente de puesta en marcha');
})->group('RN-23');

it('habla en ingles a una instalacion en ingles', function (): void {
    app()->setLocale('en');
    correccionDeZonaConRegistro();

    $orden = correccionDeZonaOrden([
        'timezone' => 'Atlantic/Canary',
        '--reason' => CORRECT_SITE_TIMEZONE_MOTIVO,
        '--responsible' => (string) correccionDeZonaResponsable(),
        '--force' => true,
    ]);

    expect($orden['code'])->toBe(0)
        ->and($orden['output'])->toContain('Time zone corrected from Europe/Madrid to Atlantic/Canary')
        ->and($orden['output'])->toContain('No work day is moved and nothing is recalculated');
})->group('RN-23');

it('no acepta como responsable a quien no puede modificar el centro por la API', function (UserRole $rol): void {
    // La consola no puede ser la via por la que alguien hace lo que el panel le
    // niega: firma quien tiene `SitePolicy::update` (admin o rrhh). Y se dice
    // antes de pedir confirmacion.
    $escenario = correccionDeZonaConRegistro();

    $orden = correccionDeZonaOrdenInteractiva([
        'timezone' => 'Atlantic/Canary',
        '--reason' => CORRECT_SITE_TIMEZONE_MOTIVO,
        '--responsible' => (string) ManagementUsers::withRole($rol)->id,
    ], 'yes');

    expect($orden['code'])->toBe(2, $orden['output'])
        ->and($orden['output'])->toContain('rol de administración o de RRHH')
        ->and($orden['output'])->not->toContain('¿Corregir la zona horaria del centro?')
        ->and(DB::table('sites')->where('id', $escenario['site'])->value('timezone'))->toBe('Europe/Madrid')
        ->and(correccionDeZonaAsientos())->toBe(0);
})->with([
    'responsable de departamento' => [UserRole::RESPONSABLE_DEPARTAMENTO],
    'auditor' => [UserRole::AUDITOR],
    'empleado' => [UserRole::EMPLEADO],
])->group('RN-23', 'RS-05');

it('acepta como responsable al administrador', function (): void {
    $escenario = correccionDeZonaConRegistro();

    $orden = correccionDeZonaOrden([
        'timezone' => 'Atlantic/Canary',
        '--reason' => CORRECT_SITE_TIMEZONE_MOTIVO,
        '--responsible' => (string) ManagementUsers::withRole(UserRole::ADMIN)->id,
        '--force' => true,
    ]);

    expect($orden['code'])->toBe(0, $orden['output'])
        ->and(DB::table('sites')->where('id', $escenario['site'])->value('timezone'))->toBe('Atlantic/Canary');
})->group('RN-23');

it('vuelve a comprobar al responsable dentro de la transaccion, sin fiarse de la vista previa', function (): void {
    // Si la cuenta se da de baja entre la confirmacion y la correccion, el
    // asiento no puede quedar firmado por ella. Se llama al caso de uso sin
    // vista previa: lo que se afirma es que la comprobacion esta tambien ahi.
    $escenario = correccionDeZonaConRegistro();
    $responsable = correccionDeZonaResponsable();
    DB::table('users')->where('id', $responsable)->update(['is_active' => false]);

    expect(fn (): mixed => app(CorrectSiteTimezoneHandler::class)->handle(
        new CorrectSiteTimezoneCommand('Atlantic/Canary', CORRECT_SITE_TIMEZONE_MOTIVO, $responsable),
    ))->toThrow(InvalidSiteTimezoneCorrection::class);

    expect(DB::table('sites')->where('id', $escenario['site'])->value('timezone'))->toBe('Europe/Madrid')
        ->and(correccionDeZonaAsientos())->toBe(0);
})->group('RN-23', 'RL-04');
