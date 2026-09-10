<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Product\Infrastructure\Capture\ExecutionContext;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Compliance\IncidentFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Observability\CapturedLog;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **LA REGLA DURA 21, COMPROBADA SOBRE EL LOG TECNICO ENTERO** (RF-PD-15,
 * RL-08, doc 01 §9.4, decision 9 de la ficha 3.1; bloque C de
 * `/revision-cumplimiento`).
 *
 * ## Que se recorre y por que esos cuatro
 *
 * Un fichaje por QR, una correccion de tramo, una incidencia resuelta y un error
 * de servidor. Son los cuatro caminos en los que el sistema **tiene delante los
 * datos de una persona concreta** mientras escribe en el log: el fichaje conoce
 * al empleado por su credencial, la correccion lo nombra en el cuerpo de la
 * peticion, la incidencia lleva una nota escrita a mano por alguien de RRHH —que
 * es donde de verdad aparecen nombres— y el error de servidor arrastra lo que
 * hubiera en la peticion.
 *
 * ## Por que importa tanto este registro en concreto
 *
 * El log tecnico va a Loki, y Loki **no tiene control de acceso por dato ni
 * borrado selectivo**: lo que entra ahi se queda hasta que caduca el bloque
 * entero. Un nombre en una linea de log es un dato personal almacenado fuera de
 * la base que se respalda, se audita y se purga — es decir, fuera de todo lo que
 * RL-08 y la retencion de RL-11 prometen.
 *
 * La contrapartida esta en `ErrorEventsHaveNoPersonalDataTest`, que hace lo mismo
 * con la tabla `error_events`. Los dos registros son distintos (§8.2.1) y los dos
 * tienen que cumplir lo mismo.
 *
 * ## Y la otra mitad: que todo lleve `trace_id`
 *
 * Un log anonimo sin correlacion no sirve para nada. La promesa del §8.1 es que
 * se pueda reconstruir «lo que le paso al fichaje de las 07:02» **sin** el
 * nombre de nadie, y eso solo se sostiene si cada linea lleva con que unirla a su
 * peticion.
 */

uses(RefreshDatabase::class);

const NOMBRE_PROPIO = 'Maria';

const APELLIDOS = 'Gonzalez Perez';

const NOMBRE_COMPLETO = NOMBRE_PROPIO.' '.APELLIDOS;

const CORREO_PERSONAL = 'maria.gonzalez@hotelplaya.es';

/** El DNI no se almacena en claro (RL-08); entra en el sistema escrito a mano. */
const DOCUMENTO_PERSONAL = '12345678Z';

const TARJETA_DE_MARIA = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa';

const AHORA_DE_LA_JORNADA = '2026-03-14 07:02:31';

/** Una traza del cliente en cada peticion, como la envia el quiosco. */
const TRACEPARENT_DE_LA_JORNADA = '00-9f1c2d3e4a5b6c7d8e9f0a1b2c3d4e5f-00f067aa0ba902b7-01';

/**
 * Lo que no puede aparecer en ninguna linea del log tecnico.
 *
 * @return list<string>
 */
function datosDeLaPersona(): array
{
    return [NOMBRE_COMPLETO, NOMBRE_PROPIO, APELLIDOS, CORREO_PERSONAL, DOCUMENTO_PERSONAL];
}

/**
 * Un hotel, una persona con nombre, correo y documento conocidos, un quiosco y
 * una sesion de RRHH.
 *
 * @return array{site: int, employee: string, deviceToken: string, managementToken: string, userId: int}
 */
function escenarioConDatosPersonales(): array
{
    $site = WorkforceFixtures::site('Hotel de privacidad', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site, 'Cocina');
    $employee = WorkforceFixtures::employee($site, $department, 'active', NOMBRE_PROPIO, APELLIDOS, 'EMP-0042');

    // El correo es opcional en el producto (regla dura 12) y aqui se rellena a
    // proposito: lo que se afirma es que teniendolo tampoco sale en el log.
    DB::table('employees')->where('uuid', $employee)->update(['email' => CORREO_PERSONAL]);

    $device = AttendanceFixtures::device($site);
    $user = ManagementUsers::withRole(UserRole::RRHH);

    app()->instance(Clock::class, FixedClock::at(AHORA_DE_LA_JORNADA));
    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()->resolving(TARJETA_DE_MARIA, $employee),
    );

    return [
        'site' => $site,
        'employee' => $employee,
        'deviceToken' => AttendanceFixtures::tokenFor($device['id']),
        'managementToken' => ManagementUsers::tokenFor($user),
        'userId' => $user->id,
    ];
}

it('ninguna linea del log tecnico lleva nombre, correo ni documento, y todas llevan trace_id', function (): void {
    app()->make(ExecutionContext::class)->reset();

    Route::middleware('api')->get(
        '/api/v1/__privacy/boom',
        static fn () => throw new RuntimeException('El adaptador de nomina no respondio'),
    )->name('privacy.boom');

    $escenario = escenarioConDatosPersonales();
    // Detectada ANTES de la hora del reloj detenido: una incidencia no se
    // resuelve antes de detectarse, y con el reloj en la jornada del 14 la de
    // serie —del 15— haria fallar la resolucion por una razon que no es la que
    // esta prueba mira.
    $incidente = IncidentFixtures::open(
        $escenario['employee'],
        workDate: '2026-03-13',
        detectedAt: '2026-03-13 03:30:00+00',
        assignedToUserId: $escenario['userId'],
    );

    CapturedLog::around(function (CapturedLog $log) use ($escenario, $incidente): void {
        $quiosco = Api::as($escenario['deviceToken'])
            ->withHeaders(['traceparent' => TRACEPARENT_DE_LA_JORNADA]);
        $gestion = Api::as($escenario['managementToken'])
            ->withHeaders(['traceparent' => TRACEPARENT_DE_LA_JORNADA]);

        // 1. Un fichaje por QR, que es el camino que conoce a la persona por su
        //    credencial.
        $scanId = Str::uuid7()->toString();

        $quiosco->withHeaders([
            'traceparent' => TRACEPARENT_DE_LA_JORNADA,
            'Idempotency-Key' => $scanId,
        ])->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T07:02:31Z',
            'qr_payload' => TARJETA_DE_MARIA,
        ])->assertOk();

        // 2. Una correccion, que nombra a la persona en el cuerpo de la peticion.
        $gestion->post('/api/v1/shift-entries', [
            'employee_uuid' => $escenario['employee'],
            'work_date' => '2026-03-13',
            'clocked_in_at' => '2026-03-13T06:00:00Z',
            'clocked_out_at' => '2026-03-13T14:00:00Z',
            'reason_code' => 'OLVIDO_FICHAJE_ENTRADA',
        ])->assertStatus(201);

        // 3. Una incidencia resuelta con una nota escrita como se escriben de
        //    verdad: con el nombre y el documento dentro. Es el dato que un
        //    humano introduce y que el sistema no puede reenviar al log.
        $gestion->post('/api/v1/incidents/'.$incidente.'/resolve', [
            'outcome' => 'resolved',
            'note' => 'Hablado con '.NOMBRE_COMPLETO.' ('.DOCUMENTO_PERSONAL.'): aporta el parte de turno.',
        ])->assertOk();

        // 4. Un error de servidor dentro de una sesion de gestion.
        $gestion->get('/api/v1/__privacy/boom')->assertStatus(500);

        $volcado = $log->dump();

        // La mitad que hace significativa a la otra: un log vacio tambien pasaria
        // la busqueda. Los cuatro caminos han escrito.
        expect($log->records())->not->toBe([])
            ->and($volcado)->toContain('attendance.scan_processed');

        foreach (datosDeLaPersona() as $dato) {
            expect($volcado)->not->toContain($dato);
        }

        // Y con que reconstruir la peticion sin saber de quien era.
        foreach ($log->traceIds() as $indice => $traceId) {
            expect($traceId)->toBe(
                '9f1c2d3e4a5b6c7d8e9f0a1b2c3d4e5f',
                'La linea '.$indice.' del log sale sin el trace_id de la peticion: '
                .'sin el no hay forma de reconstruir un incidente sin nombrar a nadie (doc 02 §8.1).'
            );
        }
    });
})->group('RF-PD-15', 'RL-08');

it('el fichaje se identifica por identificadores opacos, que es lo que sustituye al nombre', function (): void {
    /*
     * La contrapartida de la prueba anterior, y la que evita el arreglo facil:
     * un log que no dijera nada tambien pasaria la busqueda de nombres. Lo que
     * el §8.1 pide es que **haya** con que trabajar —`scan_id`, `device_id`,
     * `employee_uuid`, `trace_id`— y que sea opaco.
     */
    $escenario = escenarioConDatosPersonales();

    CapturedLog::around(function (CapturedLog $log) use ($escenario): void {
        $scanId = Str::uuid7()->toString();

        Api::as($escenario['deviceToken'])->withHeaders([
            'traceparent' => TRACEPARENT_DE_LA_JORNADA,
            'Idempotency-Key' => $scanId,
        ])->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => '2026-03-14T07:02:31Z',
            'qr_payload' => TARJETA_DE_MARIA,
        ])->assertOk();

        $fichaje = null;

        foreach ($log->records() as $record) {
            if ($record->message === 'attendance.scan_processed') {
                $fichaje = $record;
            }
        }

        expect($fichaje)->not->toBeNull();
        assert($fichaje !== null);

        expect($fichaje->context['scan_id'] ?? null)->toBe($scanId)
            ->and($fichaje->context['employee_uuid'] ?? null)->toBe($escenario['employee'])
            ->and($fichaje->context['device_id'] ?? null)->toBeString();
    });
})->group('RF-PD-15', 'RL-08');
