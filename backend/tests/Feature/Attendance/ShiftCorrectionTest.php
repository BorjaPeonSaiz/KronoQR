<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CorrectionMetrics;
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
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\RecordingCorrectionMetrics;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\Instants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Los tres endpoints de correccion, extremo a extremo y contra el contrato
 * (RF-PA-04, RN-13, RL-04, ADR-035).
 *
 * Cada respuesta pasa por Spectator: el cliente TypeScript de los tres frontends
 * se genera de `openapi.yaml`, asi que una desviacion aqui rompe a los tres a la
 * vez y sin aviso.
 *
 * Lo que estas pruebas cubren y las de integracion no es el **borde**: los
 * codigos de estado —y en particular el 404 frente al 409 de ADR-035—, la
 * validacion del motivo del Anexo C y la forma exacta de la respuesta.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * Centro, empleado y una sesion de RRHH, que es el rol que puede las tres cosas
 * en la Fase 1.
 *
 * @return array{token: string, site: int, employee: string}
 */
function contextoDeCorreccion(): array
{
    $site = WorkforceFixtures::site('Hotel de correcciones API');

    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        'site' => $site,
        'employee' => WorkforceFixtures::employee($site),
    ];
}

/**
 * Un tramo ya registrado por el quiosco, que es de donde parte toda correccion.
 *
 * Se escribe con el agregado y su repositorio, no con el endpoint de escaneo:
 * lo que estas pruebas ejercitan es la correccion, y montar el estado previo
 * fichando obligaria a fabricar credenciales que no vienen al caso.
 */
function tramoRegistrado(int $site, string $employee, string $in = '2026-03-14 06:00', ?string $out = '2026-03-14 14:00'): string
{
    $repositorio = app(WorkDayRepository::class);
    $proyector = app(DailyTotalsProjector::class);

    $jornada = WorkDay::start($employee, $site, WorkDate::fromIsoDate('2026-03-14', Instants::madrid()));
    $entrada = $jornada->clockIn(Str::uuid7()->toString(), Instants::utc($in), ScanOrigin::QR_KIOSK);

    if ($out !== null) {
        $jornada->clockOut(Instants::utc($out), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    }

    $repositorio->save($jornada);

    foreach ($jornada->releaseEvents() as $evento) {
        if ($evento instanceof DailyTotalsRecalculated) {
            $proyector->handle($evento);
        }
    }

    return $entrada->uuid();
}

it('da de alta un tramo que nunca se ficho', function (): void {
    // RF-PA-04, accion `created`: el olvido de fichaje de entrada y el dia sin
    // tarjeta entregada. Vale para la nomina igual que uno escaneado y solo se
    // distingue por su origen y por la fila que lo explica.
    $contexto = contextoDeCorreccion();

    $respuesta = Api::as($contexto['token'])
        ->post('/api/v1/shift-entries', [
            'employee_uuid' => $contexto['employee'],
            'work_date' => '2026-03-14',
            'clocked_in_at' => '2026-03-14T06:00:00Z',
            'clocked_out_at' => '2026-03-14T14:00:00Z',
            'reason_code' => 'OLVIDO_FICHAJE_ENTRADA',
        ])
        ->assertValidRequest()
        ->assertValidResponse(201);

    $respuesta
        ->assertJsonPath('action', 'created')
        ->assertJsonPath('version', 1)
        ->assertJsonPath('status', 'closed')
        ->assertJsonPath('superseded_shift_entry_uuid', null)
        ->assertJsonPath('daily_total_minutes', 480)
        ->assertJsonPath('work_date', '2026-03-14');

    // Y queda registrado como escrito a mano, no como fichado: es la unica
    // diferencia visible en `shift_entries`, y la que ante Inspeccion distingue
    // lo que produjo el quiosco de lo que declaro una persona.
    expect(DB::table('shift_entries')->value('clock_in_source'))->toBe('manual_admin');
})->group('RF-PA-04', 'RN-13');

it('cierra un turno olvidado y devuelve los dos identificadores', function (): void {
    // Escenario «Correccion manual trazada» del doc 01 §11, al pie de la letra:
    // un tramo abierto, el responsable lo cierra con motivo, el tramo queda
    // cerrado a esa hora, existe registro de correccion con el valor anterior,
    // el autor y el motivo, el original permanece consultable y el total diario
    // se RECALCULA.
    $contexto = contextoDeCorreccion();
    $abierto = tramoRegistrado($contexto['site'], $contexto['employee'], out: null);

    $respuesta = Api::as($contexto['token'])
        ->patch('/api/v1/shift-entries/'.$abierto, [
            'clocked_out_at' => '2026-03-14T15:00:00Z',
            'reason_code' => 'OLVIDO_FICHAJE_SALIDA',
        ])
        ->assertValidRequest()
        ->assertValidResponse(200);

    // El tramo queda cerrado a las 15:00, y `closed` distingue el cierre de un
    // turno olvidado de un simple cambio de hora: es lo que permite contar
    // cuantos olvidos de salida hubo el mes pasado.
    $respuesta
        ->assertJsonPath('action', 'closed')
        ->assertJsonPath('version', 2)
        ->assertJsonPath('clocked_out_at', '2026-03-14T15:00:00.000000Z')
        ->assertJsonPath('superseded_shift_entry_uuid', $abierto)
        // 06:00 -> 15:00 son 540 minutos. Escrito como numero y no calculado:
        // una prueba que repite la aritmetica del codigo no comprueba nada.
        ->assertJsonPath('daily_total_minutes', 540);

    // La version nueva estrena identificador (ADR-035).
    $nuevo = $respuesta->json('shift_entry_uuid');
    expect($nuevo)->not->toBe($abierto);

    // El registro original permanece consultable, con sus marcas.
    $anterior = DB::table('shift_entries')->where('uuid', $abierto)->first();
    expect($anterior?->status)->toBe('superseded')
        ->and($anterior?->clocked_out_at)->toBeNull()
        ->and($anterior?->version)->toBe(1);

    // Existe el registro de correccion con el valor anterior, el autor y el
    // motivo.
    $correccion = DB::table('shift_corrections')->first();
    expect($correccion?->action)->toBe('closed')
        ->and($correccion?->reason_code)->toBe('OLVIDO_FICHAJE_SALIDA')
        ->and($correccion?->performed_by_user_id)->toBeGreaterThan(0);

    // Y el total se recalcula, no se incrementa: la proyeccion coincide con la
    // suma de los tramos vigentes (RN-06, regla dura 7).
    expect(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RN-13', 'RN-06', 'RF-PA-04');

it('baja el total del dia al anular un tramo', function (): void {
    // Es el unico camino por el que el total de una jornada puede BAJAR, y el
    // que un acumulador se equivocaria (ADR-007, regla dura 7).
    $contexto = contextoDeCorreccion();
    $tramo = tramoRegistrado($contexto['site'], $contexto['employee']);

    Api::as($contexto['token'])
        ->post('/api/v1/shift-entries/'.$tramo.'/void', [
            'reason_code' => 'ERROR_DE_ESCANEO_DUPLICADO',
        ])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('action', 'voided')
        ->assertJsonPath('status', 'voided')
        // Anular NO crea version nueva: no hay version posterior de un hecho que
        // no ocurrio (ADR-026).
        ->assertJsonPath('superseded_shift_entry_uuid', null)
        ->assertJsonPath('shift_entry_uuid', $tramo)
        ->assertJsonPath('daily_total_minutes', 0);

    // La fila sigue ahi con sus marcas: no se ha borrado nada (regla dura 5).
    expect(DB::table('shift_entries')->where('uuid', $tramo)->value('duration_minutes'))->toBe(480)
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RF-PA-04', 'RN-06', 'RN-13');

it('responde 409 a un PATCH sobre una version ya sustituida', function (): void {
    // ADR-035, la prueba que ese ADR deja pendiente por escrito. Dos responsables
    // corrigiendo la misma jornada a la vez es lo normal en un cambio de turno, y
    // un 404 les diria que el tramo no existe cuando lo que ha pasado es que el
    // otro llego antes.
    $contexto = contextoDeCorreccion();
    $tramo = tramoRegistrado($contexto['site'], $contexto['employee']);

    Api::as($contexto['token'])
        ->patch('/api/v1/shift-entries/'.$tramo, [
            'clocked_out_at' => '2026-03-14T13:30:00Z',
            'reason_code' => 'AJUSTE_ACORDADO_CON_RRHH',
        ])
        ->assertValidResponse(200);

    // El mismo identificador, otra vez.
    Api::as($contexto['token'])
        ->patch('/api/v1/shift-entries/'.$tramo, [
            'clocked_out_at' => '2026-03-14T13:00:00Z',
            'reason_code' => 'AJUSTE_ACORDADO_CON_RRHH',
        ])
        ->assertValidResponse(409)
        ->assertJsonPath('type', 'urn:kronoqr:problem:conflict');
})->group('RF-PA-04', 'RN-13');

it('responde 404 a un identificador que no existe', function (): void {
    // La otra mitad de ADR-035: un uuid inventado no es un conflicto, es un
    // error de quien llama.
    $contexto = contextoDeCorreccion();

    Api::as($contexto['token'])
        ->patch('/api/v1/shift-entries/'.Str::uuid7()->toString(), [
            'clocked_out_at' => '2026-03-14T13:30:00Z',
            'reason_code' => 'AJUSTE_ACORDADO_CON_RRHH',
        ])
        ->assertValidResponse(404);
})->group('RF-PA-04');

it('rechaza mover a otro dia la entrada que abre la jornada', function (): void {
    // ADR-035, decision 2, y regla dura 4. Mover horas de un dia a otro son dos
    // actos separados y auditados, no un efecto lateral de un PATCH: el 422 lo
    // dice y explica que hacer.
    $contexto = contextoDeCorreccion();
    // 22:00 -> 06:00 en hora de Madrid: un turno de noche, un solo tramo,
    // atribuido a la jornada del 14 (RN-05, ADR-006).
    $repositorio = app(WorkDayRepository::class);
    $jornada = WorkDay::start(
        $contexto['employee'],
        $contexto['site'],
        WorkDate::fromIsoDate('2026-03-14', Instants::madrid()),
    );
    $entrada = $jornada->clockIn(Str::uuid7()->toString(), Instants::inMadrid('2026-03-14 22:00'), ScanOrigin::QR_KIOSK);
    $jornada->clockOut(Instants::inMadrid('2026-03-15 06:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    $repositorio->save($jornada);

    // Rectificar la SALIDA no lo dispara: sigue siendo del dia 14.
    Api::as($contexto['token'])
        ->patch('/api/v1/shift-entries/'.$entrada->uuid(), [
            'clocked_out_at' => '2026-03-15T05:30:00Z',
            'reason_code' => 'AJUSTE_ACORDADO_CON_RRHH',
        ])
        ->assertValidResponse(200)
        ->assertJsonPath('work_date', '2026-03-14');

    // Mover la ENTRADA al otro lado de la medianoche local, si. El tramo vigente
    // ya no es el que se ficho: la correccion anterior lo sustituyo (ADR-035).
    $vigente = DB::table('shift_entries')->where('status', '<>', 'superseded')->value('uuid');

    expect($vigente)->toBeString();
    \assert(\is_string($vigente));

    Api::as($contexto['token'])
        ->patch('/api/v1/shift-entries/'.$vigente, [
            'clocked_in_at' => '2026-03-15T02:00:00Z',
            'reason_code' => 'AJUSTE_ACORDADO_CON_RRHH',
        ])
        ->assertValidResponse(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed');
})->group('RN-05', 'RF-AT-08', 'RF-PA-04');

it('exige explicacion de veinte caracteres cuando el motivo es OTROS', function (): void {
    // Anexo C. «error», «ajuste» o «lo dijo Marta» no explican nada ante
    // Inspeccion, y un motivo que no explica convierte RN-13 en un formulario.
    $contexto = contextoDeCorreccion();
    $tramo = tramoRegistrado($contexto['site'], $contexto['employee']);

    // Diecinueve caracteres: uno menos del minimo.
    Api::as($contexto['token'])
        ->patch('/api/v1/shift-entries/'.$tramo, [
            'clocked_out_at' => '2026-03-14T13:30:00Z',
            'reason_code' => 'OTROS',
            'reason_text' => str_repeat('a', 19),
        ])
        ->assertValidResponse(422)
        ->assertJsonPath('errors.reason_text.0', fn (string $mensaje): bool => str_contains($mensaje, '20'));

    // Veinte, si.
    Api::as($contexto['token'])
        ->patch('/api/v1/shift-entries/'.$tramo, [
            'clocked_out_at' => '2026-03-14T13:30:00Z',
            'reason_code' => 'OTROS',
            'reason_text' => str_repeat('a', 20),
        ])
        ->assertValidResponse(200);
})->group('RF-PA-04');

it('rechaza un motivo que no esta en el catalogo', function (): void {
    // El catalogo es cerrado (Anexo C): con texto libre, la misma causa acaba
    // escrita de tres formas y la consulta «cuantas correcciones por olvido de
    // fichaje hubo en marzo» no se puede escribir.
    $contexto = contextoDeCorreccion();
    $tramo = tramoRegistrado($contexto['site'], $contexto['employee']);

    Api::as($contexto['token'])
        ->patch('/api/v1/shift-entries/'.$tramo, [
            'clocked_out_at' => '2026-03-14T13:30:00Z',
            'reason_code' => 'PORQUE_SI',
        ])
        ->assertValidResponse(422);
})->group('RF-PA-04');

it('rechaza una correccion que no trae ninguna marca', function (): void {
    // Una correccion que no cambia nada es una fila de auditoria que miente.
    $contexto = contextoDeCorreccion();
    $tramo = tramoRegistrado($contexto['site'], $contexto['employee']);

    Api::as($contexto['token'])
        ->patch('/api/v1/shift-entries/'.$tramo, ['reason_code' => 'AJUSTE_ACORDADO_CON_RRHH'])
        ->assertValidResponse(422);
})->group('RF-PA-04');

it('rechaza una hora que no viene en UTC', function (): void {
    // Regla dura 3 y RN-04. Aceptar un desplazamiento explicito convertiria la
    // zona horaria en un dato del cliente, y con turnos nocturnos eso es una
    // jornada mal atribuida.
    $contexto = contextoDeCorreccion();
    $tramo = tramoRegistrado($contexto['site'], $contexto['employee']);

    Api::as($contexto['token'])
        ->patch('/api/v1/shift-entries/'.$tramo, [
            'clocked_out_at' => '2026-03-14T15:00:00+01:00',
            'reason_code' => 'AJUSTE_ACORDADO_CON_RRHH',
        ])
        ->assertValidResponse(422);
})->group('RN-04', 'RF-PA-04');

it('rechaza un campo que el endpoint no conoce', function (): void {
    // Fallar en voz alta es mejor que acertar por casualidad: quien envia
    // `work_date` en un PATCH cree haber movido la jornada, y no ha movido nada.
    $contexto = contextoDeCorreccion();
    $tramo = tramoRegistrado($contexto['site'], $contexto['employee']);

    Api::as($contexto['token'])
        ->patch('/api/v1/shift-entries/'.$tramo, [
            'clocked_out_at' => '2026-03-14T13:30:00Z',
            'reason_code' => 'AJUSTE_ACORDADO_CON_RRHH',
            'work_date' => '2026-03-15',
        ])
        ->assertValidResponse(422);
})->group('RF-PA-04', 'RN-05');

it('no deja escribir horas a nombre de quien esta de baja', function (): void {
    // RN-14. No es autorizacion sino integridad: dar de alta horas a una persona
    // cesada produce un registro que nadie sabe defender. Y aqui SI se puede
    // decir por que, al contrario que en el quiosco: quien pregunta es un
    // responsable autenticado, no una pantalla en un pasillo.
    $contexto = contextoDeCorreccion();
    $cesado = WorkforceFixtures::employee($contexto['site'], null, 'terminated');

    Api::as($contexto['token'])
        ->post('/api/v1/shift-entries', [
            'employee_uuid' => $cesado,
            'work_date' => '2026-03-14',
            'clocked_in_at' => '2026-03-14T06:00:00Z',
            'reason_code' => 'ALTA_RETROACTIVA',
        ])
        ->assertValidResponse(422);
})->group('RN-14', 'RF-PA-04');

it('devuelve 409 cuando el alta pisaria a otro tramo de la misma persona', function (): void {
    // RN-02. Es un 409 y no un 422 porque el problema no esta en los campos
    // enviados: esta en lo que hay registrado, y para arreglarlo hay que mirar la
    // jornada.
    $contexto = contextoDeCorreccion();
    tramoRegistrado($contexto['site'], $contexto['employee']);

    Api::as($contexto['token'])
        ->post('/api/v1/shift-entries', [
            'employee_uuid' => $contexto['employee'],
            'work_date' => '2026-03-14',
            'clocked_in_at' => '2026-03-14T10:00:00Z',
            'clocked_out_at' => '2026-03-14T12:00:00Z',
            'reason_code' => 'OLVIDO_FICHAJE_ENTRADA',
        ])
        ->assertValidResponse(409);
})->group('RN-02', 'RF-PA-04');

// --- Auditoria e instrumentacion ---------------------------------------------

it('deja en audit_log el antes, el despues y quien firmo, sin nombres', function (): void {
    // Regla dura 6 y RL-04. El asiento se escribe en la MISMA transaccion que la
    // correccion: si fallara, la correccion no se confirmaria (ADR-027). Es la
    // copia que no se puede tocar —`audit_log` es solo-append y encadenado por
    // hash— de lo que `shift_corrections` guarda.
    $contexto = contextoDeCorreccion();
    $tramo = tramoRegistrado($contexto['site'], $contexto['employee'], out: null);

    Api::as($contexto['token'])
        ->patch('/api/v1/shift-entries/'.$tramo, [
            'clocked_out_at' => '2026-03-14T15:00:00Z',
            'reason_code' => 'OLVIDO_FICHAJE_SALIDA',
        ])
        ->assertValidResponse(200);

    /** @var list<stdClass> $asientos */
    $asientos = DB::table('audit_log')->orderBy('id')->get()->all();

    expect($asientos)->toHaveCount(1);

    $asiento = $asientos[0];

    // `closed` y no `modified`: es lo que distingue el cierre de un turno
    // olvidado de un cambio de hora, y lo que permite contarlos por separado.
    expect($asiento->action)->toBe('shift_entry.closed')
        ->and($asiento->subject_type)->toBe('shift_entry')
        // El actor es la persona con la sesion abierta, no «el sistema» (RN-13).
        ->and($asiento->actor_type)->toBe('user');

    /** @var array{before: array{clocked_out_at: string|null}, after: array{clocked_out_at: string}, reason_code: string, superseded_shift_entry_uuid: string} $payload */
    $payload = json_decode((string) $asiento->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($payload['before']['clocked_out_at'])->toBeNull()
        ->and($payload['after']['clocked_out_at'])->toBe('2026-03-14T15:00:00.000000Z')
        ->and($payload['reason_code'])->toBe('OLVIDO_FICHAJE_SALIDA')
        ->and($payload['superseded_shift_entry_uuid'])->toBe($tramo);

    // Regla dura 21: ni un nombre en el trail. Se identifica por
    // `employee_uuid`, que la Inspeccion resuelve contra `employees` si de
    // verdad hace falta.
    expect($asiento->payload)->toContain($contexto['employee'])
        ->and($asiento->payload)->not->toContain('Persona')
        ->and($asiento->payload)->not->toContain('De Prueba');
})->group('RL-04', 'RN-13', 'RS-07');

it('no mete el texto libre del motivo en el trail', function (): void {
    // El codigo del catalogo si; el texto no. Lo escribio una persona sobre otra
    // y puede contener cualquier cosa: su sitio es `shift_corrections`, que se
    // lee con autorizacion, no un trail que viaja entero en la exportacion legal
    // y en el paquete de diagnostico (ADR-020, regla dura 21).
    $contexto = contextoDeCorreccion();
    $tramo = tramoRegistrado($contexto['site'], $contexto['employee']);
    $explicacion = 'Acordado con la interesada en la reunion del lunes';

    Api::as($contexto['token'])
        ->patch('/api/v1/shift-entries/'.$tramo, [
            'clocked_out_at' => '2026-03-14T13:30:00Z',
            'reason_code' => 'OTROS',
            'reason_text' => $explicacion,
        ])
        ->assertValidResponse(200);

    expect(DB::table('audit_log')->value('payload'))->not->toContain('interesada')
        // Y en el libro de correcciones si, integro: es donde RN-13 lo exige.
        ->and(DB::table('shift_corrections')->value('reason_text'))->toBe($explicacion);
})->group('RL-04', 'RS-06', 'RN-13');

it('cuenta la correccion en manual_corrections_total, agrupada por motivo', function (): void {
    // Doc 02 §8.2. No mide rendimiento: mide cuanto hay que corregir a mano y por
    // que. Un pico de FALLO_TECNICO_QUIOSCO en un centro es una tablet que hay
    // que ir a mirar antes de que la gente se acostumbre a no fichar.
    $metricas = new RecordingCorrectionMetrics;
    app()->instance(CorrectionMetrics::class, $metricas);

    $contexto = contextoDeCorreccion();
    $tramo = tramoRegistrado($contexto['site'], $contexto['employee']);

    Api::as($contexto['token'])
        ->patch('/api/v1/shift-entries/'.$tramo, [
            'clocked_out_at' => '2026-03-14T13:30:00Z',
            'reason_code' => 'FALLO_TECNICO_QUIOSCO',
        ])
        ->assertValidResponse(200);

    expect($metricas->counts())->toBe(['FALLO_TECNICO_QUIOSCO' => 1]);
})->group('RF-PA-04');

it('no cuenta una correccion que no se llego a aplicar', function (): void {
    // Contar los intentos haria que la respuesta a «cuanto hay que corregir a
    // mano en esta instalacion» dependiera de cuantas veces alguien se equivoco
    // de boton.
    $metricas = new RecordingCorrectionMetrics;
    app()->instance(CorrectionMetrics::class, $metricas);

    $contexto = contextoDeCorreccion();

    Api::as($contexto['token'])
        ->patch('/api/v1/shift-entries/'.Str::uuid7()->toString(), [
            'clocked_out_at' => '2026-03-14T13:30:00Z',
            'reason_code' => 'FALLO_TECNICO_QUIOSCO',
        ])
        ->assertValidResponse(404);

    expect($metricas->counts())->toBe([]);
})->group('RF-PA-04');
