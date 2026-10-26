<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Domain\Event\DailyTotalsRecalculated;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Attendance\Infrastructure\Projection\DailyTotalsProjector;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spectator\Spectator;
use Tests\Support\Compliance\AuditPayloads;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Time\Instants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `GET /api/v1/employees/{uuid}/workdays` pagina en el servidor por jornadas
 * enteras (2.3.0, bloque 14, tarea 14.5; V5-PA-2, V21-5-PA-3).
 *
 * Un año de una persona con dos tramos al dia eran 38 000 nodos en el panel y
 * quince segundos de bloqueo con un equipo lento. Lo que se afirma:
 *
 * - `page` y `per_page` recorren las jornadas **con actividad**, de la mas
 *   antigua a la mas reciente, y una jornada nunca se parte;
 * - `from` y `to` siguen siendo el rango pedido;
 * - sin `per_page`, el mes del panel cabe en una pagina;
 * - el asiento cuenta **lo que salio** en esa pagina;
 * - el coste en consultas no crece con las jornadas del rango.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * @return array{token: string, site: int, employee: string}
 */
function paginaDeJornadasContexto(): array
{
    $site = WorkforceFixtures::site('Hotel paginado');

    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        'site' => $site,
        'employee' => WorkforceFixtures::employee($site),
    ];
}

/**
 * Una jornada de ese dia con uno o dos tramos cerrados, con su proyeccion.
 */
function paginaDeJornadasDia(int $site, string $employee, string $workDate, int $tramos = 1): void
{
    $jornada = WorkDay::start($employee, $site, WorkDate::fromIsoDate($workDate, Instants::madrid()));

    $jornada->clockIn(Str::uuid7()->toString(), Instants::inMadrid($workDate.' 07:00'), ScanOrigin::QR_KIOSK);
    $jornada->clockOut(Instants::inMadrid($workDate.' 11:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());

    if ($tramos === 2) {
        $jornada->clockIn(Str::uuid7()->toString(), Instants::inMadrid($workDate.' 12:00'), ScanOrigin::QR_KIOSK);
        $jornada->clockOut(Instants::inMadrid($workDate.' 16:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    }

    app(WorkDayRepository::class)->save($jornada);

    foreach ($jornada->releaseEvents() as $evento) {
        if ($evento instanceof DailyTotalsRecalculated) {
            app(DailyTotalsProjector::class)->handle($evento);
        }
    }
}

/**
 * Cinco jornadas con actividad en marzo, con huecos entre ellas.
 *
 * @return array{token: string, site: int, employee: string}
 */
function paginaDeJornadasCinco(): array
{
    $contexto = paginaDeJornadasContexto();

    foreach (['2026-03-02', '2026-03-03', '2026-03-09', '2026-03-10', '2026-03-20'] as $dia) {
        paginaDeJornadasDia($contexto['site'], $contexto['employee'], $dia, $dia === '2026-03-03' ? 2 : 1);
    }

    return $contexto;
}

it('recorre las jornadas con actividad por paginas, y el rango sigue siendo el pedido', function (int $pagina, array $dias): void {
    $contexto = paginaDeJornadasCinco();

    $response = Api::as($contexto['token'])
        ->get('/api/v1/employees/'.$contexto['employee'].'/workdays', [
            'from' => '2026-03-01',
            'to' => '2026-03-31',
            'page' => $pagina,
            'per_page' => 2,
        ])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('from', '2026-03-01')
        ->assertJsonPath('to', '2026-03-31')
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.page', $pagina)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total_pages', 3);

    expect(array_column((array) $response->json('data'), 'work_date'))->toBe($dias);
})->with([
    'primera' => [1, ['2026-03-02', '2026-03-03']],
    'segunda' => [2, ['2026-03-09', '2026-03-10']],
    'ultima, incompleta' => [3, ['2026-03-20']],
    'mas alla de la ultima' => [4, []],
])->group('RF-PA-03');

it('una jornada con dos tramos nunca se parte entre paginas', function (): void {
    $contexto = paginaDeJornadasCinco();

    $response = Api::as($contexto['token'])
        ->get('/api/v1/employees/'.$contexto['employee'].'/workdays', [
            'from' => '2026-03-01', 'to' => '2026-03-31', 'page' => 1, 'per_page' => 2,
        ])
        ->assertValidResponse(200);

    expect($response->json('data.1.work_date'))->toBe('2026-03-03')
        ->and($response->json('data.1.shift_entries'))->toHaveCount(2)
        // La suma cuadra con los tramos de la jornada, enteros (RN-06).
        ->and($response->json('data.1.total_minutes'))->toBe(480);
})->group('RF-PA-03', 'RN-06');

it('sin per_page el mes del panel cabe en una pagina, como antes', function (): void {
    $contexto = paginaDeJornadasCinco();

    Api::as($contexto['token'])
        ->get('/api/v1/employees/'.$contexto['employee'].'/workdays', ['from' => '2026-03-01', 'to' => '2026-03-31'])
        ->assertValidResponse(200)
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.page', 1)
        ->assertJsonPath('meta.per_page', 31)
        ->assertJsonPath('meta.total_pages', 1);
})->group('RF-PA-03');

it('rechaza una pagina o un tamaño fuera del contrato', function (array $query, string $campo): void {
    $contexto = paginaDeJornadasContexto();

    Api::as($contexto['token'])
        ->get('/api/v1/employees/'.$contexto['employee'].'/workdays', ['from' => '2026-03-01', 'to' => '2026-03-31', ...$query])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor($campo);
})->with([
    'pagina cero' => [['page' => 0], 'page'],
    'pagina que no es un numero' => [['page' => 'ultima'], 'page'],
    'mas de cien por pagina' => [['per_page' => 101], 'per_page'],
    'cero por pagina' => [['per_page' => 0], 'per_page'],
])->group('RF-PA-03');

it('el asiento cuenta las jornadas de la pagina servida, con sus fechas y su numero', function (): void {
    $contexto = paginaDeJornadasCinco();

    Api::as($contexto['token'])
        ->get('/api/v1/employees/'.$contexto['employee'].'/workdays', [
            'from' => '2026-03-01', 'to' => '2026-03-31', 'page' => 2, 'per_page' => 2,
        ])
        ->assertValidResponse(200);

    $asientos = DB::table('audit_log')->where('action', 'personal_data.accessed');

    expect($asientos->count())->toBe(1);

    $payload = AuditPayloads::decode($asientos->value('payload'));

    expect($payload)->toMatchArray([
        'dataset' => 'employee_workdays',
        'record_count' => 2,
        'employee_uuid' => $contexto['employee'],
        'from' => '2026-03-09',
        'to' => '2026-03-10',
        'page' => 2,
    ]);
})->group('RF-PA-03', 'RS-05');

it('no cuesta mas consultas con un rango de cuarenta jornadas que con uno de seis', function (): void {
    // Lo que hace razonable paginar un año: se cuentan las fechas con una
    // consulta y se lee solo el tramo de la pagina. Ni una consulta por jornada,
    // ni una mas por tener el rango mas jornadas.
    $contexto = paginaDeJornadasContexto();

    foreach (range(0, 39) as $offset) {
        paginaDeJornadasDia($contexto['site'], $contexto['employee'], (new DateTimeImmutable('2026-01-05'))->modify('+'.$offset.' days')->format('Y-m-d'));
    }

    $consultas = static function (string $to) use ($contexto): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        Api::as($contexto['token'])
            ->get('/api/v1/employees/'.$contexto['employee'].'/workdays', ['from' => '2026-01-01', 'to' => $to, 'per_page' => 10])
            ->assertOk();

        $count = \count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    // Una primera vuelta calienta lo que se resuelve una vez por proceso o por
    // token —instalacion, licencia, ultimo uso del token—, que no es el coste de
    // la consulta.
    //
    // El reloj se para antes de contar. Sanctum escribe `last_used_at` en cada
    // peticion, pero Eloquent compara al segundo (`Y-m-d H:i:s`) y no lanza el
    // UPDATE si el segundo no ha cambiado: con el reloj corriendo, que dos
    // peticiones caigan o no en el mismo segundo es azar, y la CI veia 16
    // consultas en una vuelta y 17 en la otra sin que la paginacion costara
    // nada mas. Parado, el UPDATE sale solo en la vuelta de calentamiento.
    FrozenTime::at(gmdate('Y-m-d H:i:s'));
    $consultas('2026-01-10');

    $seis = $consultas('2026-01-10');
    $cuarenta = $consultas('2026-03-31');

    // Diez jornadas en la pagina de cuarenta y seis en la de seis: con una
    // consulta por jornada, la segunda costaria mas. No cuesta mas.
    expect($cuarenta)->toBeLessThanOrEqual($seis);
})->group('RF-PA-03', 'RNF-P-02');
