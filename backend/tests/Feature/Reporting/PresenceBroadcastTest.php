<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Reporting\Application\Port\LivePresenceReader;
use App\Modules\Reporting\Domain\ValueObject\PresenceBoard;
use App\Modules\Reporting\Domain\ValueObject\PresenceEntry;
use App\Modules\Reporting\Domain\ValueObject\PresenceStatus;
use App\Modules\Reporting\Infrastructure\Broadcasting\PresenceUpdated;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Domain\ValueObject\CredentialRejectionReason;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La difusion de la presencia en vivo (RF-PA-01, ADR-011).
 *
 * **Se comprueba sobre el EVENTO y no sobre un socket abierto.** Lo que este
 * producto tiene que garantizar es que un fichaje produce un mensaje con el
 * canal correcto y el contenido correcto; que Reverb lo entregue es
 * responsabilidad de Reverb y se verifica en el E2E del panel, con dos pestañas
 * abiertas. Una prueba que levantara un WebSocket aqui probaria la libreria.
 *
 * `Event::fake([PresenceUpdated::class])` intercepta **solo** ese evento: los de
 * dominio siguen despachandose, asi que el listener corre de verdad, consulta la
 * base de datos de verdad y compone el mensaje de verdad. Falsear el evento de
 * dominio habria dejado sin probar justo la mitad que importa.
 */

uses(RefreshDatabase::class);

const DIFUSION_TARJETA = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa';

/**
 * Un hotel con dos departamentos y una persona en cocina, lista para fichar.
 *
 * @return array{site: int, cocina: int, recepcion: int, employee: string, device: int, deviceUuid: string, token: string}
 */
function escenarioDeDifusion(string $ahora = '2026-03-14 07:02:31'): array
{
    $site = WorkforceFixtures::site('Hotel de difusion', 'Europe/Madrid');
    $cocina = WorkforceFixtures::department($site, 'Cocina');
    $recepcion = WorkforceFixtures::department($site, 'Recepcion');

    $employee = WorkforceFixtures::employee($site, $cocina, 'active', 'Youssef', 'Amrani');
    $device = AttendanceFixtures::device($site, 'Entrada de personal');

    app()->instance(Clock::class, FixedClock::at($ahora));
    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()
            ->resolving(DIFUSION_TARJETA, $employee)
            ->rejecting('FH1.a3.0000000000000000000000.0000000000000000', CredentialRejectionReason::INVALID_SIGNATURE),
    );

    return [
        'site' => $site,
        'cocina' => $cocina,
        'recepcion' => $recepcion,
        'employee' => $employee,
        'device' => $device['id'],
        'deviceUuid' => $device['uuid'],
        'token' => AttendanceFixtures::tokenFor($device['id']),
    ];
}

/**
 * @param  array{token: string, ...}  $escenario
 * @return TestResponse<Response>
 */
function ficharParaDifundir(array $escenario, string $occurredAt): TestResponse
{
    $scanId = Str::uuid7()->toString();

    return Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => $occurredAt,
            'qr_payload' => DIFUSION_TARJETA,
        ]);
}

it('difunde la entrada al canal del departamento y al global, y a ningun otro', function (): void {
    $escenario = escenarioDeDifusion();

    Event::fake([PresenceUpdated::class]);

    ficharParaDifundir($escenario, '2026-03-14T07:02:31Z')->assertOk();

    Event::assertDispatched(PresenceUpdated::class, function (PresenceUpdated $evento) use ($escenario): bool {
        $canales = array_map(
            static fn (PrivateChannel $canal): string => (string) $canal,
            $evento->broadcastOn(),
        );

        // `private-` lo pone el propio canal de Laravel: es como viaja por el
        // cable, y el producto los nombra sin prefijo.
        expect($canales)->toBe([
            'private-presence.all',
            'private-presence.department.'.$escenario['cocina'],
        ])
            ->and($canales)->not->toContain('private-presence.department.'.$escenario['recepcion']);

        return true;
    });
})->group('RF-PA-01', 'RF-ID-03');

it('lleva en el mensaje la fila entera y nada que el contrato no declare', function (): void {
    // El evento de dominio NO lleva nombre, departamento ni quiosco (regla dura
    // 21): el listener los resuelve con la misma consulta que produce el listado.
    $escenario = escenarioDeDifusion();

    Event::fake([PresenceUpdated::class]);

    ficharParaDifundir($escenario, '2026-03-14T07:02:31Z')->assertOk();

    Event::assertDispatched(PresenceUpdated::class, function (PresenceUpdated $evento) use ($escenario): bool {
        $mensaje = $evento->broadcastWith();

        expect(array_keys($mensaje))->toBe(['entry', 'occurred_at'])
            ->and($evento->broadcastAs())->toBe('presence.updated')
            ->and(array_keys($mensaje['entry']))->toBe([
                'employee_uuid',
                'full_name',
                'department',
                'status',
                'shift_entry_uuid',
                'clocked_in_at',
                'origin',
                'device',
            ])
            ->and($mensaje['entry']['employee_uuid'])->toBe($escenario['employee'])
            ->and($mensaje['entry']['full_name'])->toBe('Youssef Amrani')
            ->and($mensaje['entry']['status'])->toBe('present')
            ->and(data_get($mensaje, 'entry.department.id'))->toBe($escenario['cocina'])
            ->and($mensaje['entry']['origin'])->toBe('qr_kiosk')
            ->and(data_get($mensaje, 'entry.device.uuid'))->toBe($escenario['deviceUuid'])
            // En UTC y con el formato que el contrato exige (regla dura 3).
            ->and($mensaje['occurred_at'])->toBe('2026-03-14T07:02:31.000000Z');

        return true;
    });
})->group('RF-PA-01');

it('difunde la salida dejando a la persona como ausente y sin datos de tramo', function (): void {
    $escenario = escenarioDeDifusion();

    ficharParaDifundir($escenario, '2026-03-14T07:02:31Z')->assertOk();

    app()->instance(Clock::class, FixedClock::at('2026-03-14 11:02:31'));

    Event::fake([PresenceUpdated::class]);

    ficharParaDifundir($escenario, '2026-03-14T11:02:31Z')->assertOk();

    Event::assertDispatched(PresenceUpdated::class, function (PresenceUpdated $evento): bool {
        $entrada = $evento->broadcastWith()['entry'];

        expect($entrada['status'])->toBe('absent')
            ->and($entrada['shift_entry_uuid'])->toBeNull()
            ->and($entrada['clocked_in_at'])->toBeNull()
            ->and($entrada['device'])->toBeNull();

        return true;
    });
})->group('RF-PA-01');

it('difunde tambien cuando se anula un tramo abierto desde el panel', function (): void {
    // Una anulacion que no difundiera dejaria el panel enseñando dentro a alguien
    // cuyo tramo se acaba de anular (RF-PA-04, RN-13).
    $escenario = escenarioDeDifusion();

    ficharParaDifundir($escenario, '2026-03-14T07:02:31Z')->assertOk();

    // El identificador del tramo se lee de la tabla y no de la respuesta del
    // quiosco: esa respuesta no lo lleva a proposito (§7.3), porque una tablet
    // colgada en un pasillo no tiene por que poder nombrar tramos.
    $tramo = DB::table('shift_entries')->value('uuid');

    expect($tramo)->toBeString();

    $gestor = ManagementUsers::withRole(UserRole::RRHH);

    Event::fake([PresenceUpdated::class]);

    Api::as(ManagementUsers::tokenFor($gestor))
        ->post('/api/v1/shift-entries/'.(is_string($tramo) ? $tramo : '').'/void', [
            'reason_code' => 'OTROS',
            'reason_text' => 'Tramo abierto por error en una prueba de difusion.',
        ])
        ->assertOk();

    Event::assertDispatched(PresenceUpdated::class, function (PresenceUpdated $evento): bool {
        expect($evento->broadcastWith()['entry']['status'])->toBe('absent');

        return true;
    });
})->group('RF-PA-01', 'RF-PA-04');

it('no rompe el fichaje aunque la difusion falle entera', function (): void {
    // Reglas duras 15 y 19: nada de la vista en vivo puede impedir un fichaje.
    // Se simula el peor caso —Reverb caido con la cola en `sync`, que es cuando
    // el listener corre dentro de la peticion— haciendo estallar la consulta que
    // compone el mensaje.
    $escenario = escenarioDeDifusion();

    app()->instance(LivePresenceReader::class, new class implements LivePresenceReader
    {
        public function board(
            AccessScope $scope,
            ?int $departmentId,
            ?string $search,
            PresenceStatus $status,
            DateTimeImmutable $generatedAt,
            string $timeZone,
        ): PresenceBoard {
            throw new RuntimeException('Reverb no contesta.');
        }

        public function stateOf(string $employeeUuid): ?PresenceEntry
        {
            throw new RuntimeException('Reverb no contesta.');
        }

        public function openShiftsByDepartment(): array
        {
            throw new RuntimeException('Reverb no contesta.');
        }
    });

    $respuesta = ficharParaDifundir($escenario, '2026-03-14T07:02:31Z');

    $respuesta->assertOk();

    expect($respuesta->json('action'))->toBe('clock_in');

    // Y el tramo esta escrito: el fichaje no se ha quedado a medias.
    expect(DB::table('shift_entries')->count())->toBe(1);
})->group('RF-PA-01', 'RF-AT-01');
