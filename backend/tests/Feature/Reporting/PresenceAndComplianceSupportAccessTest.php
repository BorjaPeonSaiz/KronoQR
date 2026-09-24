<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\SupportScope;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Product\SupportGrants;
use Tests\Support\Reporting\PeriodReportFixtures;
use Tests\Support\Reporting\PresenceFixtures;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **EL FABRICANTE NO VE LA PRESENCIA EN VIVO NI EL RESUMEN DE CUMPLIMIENTO**
 * (decision del 24-09-2026; regla dura 16, ADR-020, RL-19, RF-PA-01, RF-PA-06).
 *
 * ## Por que estas dos rutas se cierran, teniendo el ambito
 *
 * Las dos cuelgan de `attendance:read`, el mismo ambito con el que
 * `SupportScope::ReadOnly` lee el registro horario de una persona, y ante las
 * policies un actor de soporte se presenta como `admin`
 * (`SupportScope::actsAs()`). Es decir: **las dos mitades del §7.3 decian que
 * si**, igual que pasaba con las ausencias hasta el cierre de la Fase 3. Y las
 * dos mitades tienen que seguir diciendo que si —el ambito, porque sin
 * `attendance:read` el alcance no serviria para nada; el rol, porque con
 * `auditor` no alcanzaria ninguna pantalla—, asi que lo que falta es la tercera
 * pregunta: **quien** es el que actua.
 *
 * Lo que decide que estas dos queden fuera no es que lleven dato personal —las
 * tres que si se conceden tambien lo llevan— sino que **no hacen falta para
 * diagnosticar un calculo de horas**, que es lo unico para lo que se concede el
 * alcance `read_only`:
 *
 * - `GET /attendance/live` es **quien esta dentro del hotel ahora mismo, con
 *   nombre y desde que hora**: vigilancia en tiempo real de la plantilla del
 *   cliente. La incidencia «a esta persona le salen ocho horas y deberian ser
 *   nueve» se mira sobre datos ya escritos, no sobre quien esta en la cocina.
 * - `GET /compliance/summary` es el **listado de incumplimientos por persona de
 *   toda la plantilla** —descanso corto, jornada excedida— y sin acotar por
 *   departamento, precisamente porque el actor de soporte se presenta como
 *   `admin`. No da una hora mas que el registro de la persona del caso: da un
 *   juicio sobre las horas de todos los demas.
 *
 * Lo que sigue concedido es la razon de ser del alcance —`GET /employees`,
 * `GET /employees/{uuid}` y `GET /employees/{uuid}/workdays`: traducir el `uuid`
 * de una incidencia a la persona y leer su registro— y lo prueba
 * `tests/Feature/Product/SupportTokenBehaviourTest.php`.
 *
 * ## Los tres alcances, aunque dos se queden en el middleware
 *
 * `diagnostics` y `configuration` no llevan `attendance:read` y no pasan de ahi.
 * Se prueban igual, por lo mismo que en `AbsenceSupportAccessTest`: lo que
 * importa no es **donde** se para cada uno, sino que **ninguno pasa**. El dia que
 * alguien amplie la lista de ambitos de un alcance, esta prueba tiene que seguir
 * siendo la que lo pare.
 *
 * ## Y sin asiento de divulgacion
 *
 * El `403` es la mitad. La otra es que no quede un `personal_data.accessed` con
 * el fabricante como actor: las dos rutas escriben uno por peticion atendida
 * (RS-05), asi que un asiento significaria que la consulta llego a ejecutarse y
 * que alguien leyo las filas antes de que nada lo impidiera.
 *
 * ## El control positivo, que es la otra mitad de la prueba negativa
 *
 * Los tres roles que el Anexo B pone en «manager+» siguen leyendo las dos
 * pantallas. Sin esto, «arreglarlo» cerrando el endpoint a todo el mundo pasaria
 * en verde, y estas dos pantallas son el trabajo diario de recepcion y de RRHH.
 */

uses(RefreshDatabase::class);

const PRESENCE_COMPLIANCE_SUPPORT_AHORA = '2026-03-14 09:12:03';

const PRESENCE_COMPLIANCE_SUPPORT_RANGO = ['from' => '2026-03-01', 'to' => '2026-03-31'];

beforeEach(function (): void {
    // La presencia en tiempo real es funcionalidad ACCESORIA (ADR-023): sin
    // licencia degrada a sondeo, y una respuesta degradada no es la que se
    // quiere comparar cuando lo que se mira es la autorizacion.
    LicenseKeys::grantAll();

    // El segundo factor de RS-06 se comprueba en el acceso, no aqui: los tokens
    // del control positivo se emiten como los emitiria `POST /auth/login`.
    config()->set('identity.two_factor.required_roles', []);
});

/**
 * Un hotel con alguien dentro **ahora** y con dos jornadas que incumplen.
 *
 * Es el peor caso que las dos pantallas pueden servir a la vez: la presencia
 * trae a una persona con nombre fichada en este momento, y el resumen trae su
 * descanso corto —nueve horas con el perfil `ES-hosteleria`, que pide doce— y su
 * jornada de nueve horas y media. Los dos son los conjuntos que tienen que
 * quedarse dentro de la instalacion del cliente.
 *
 * @return array{site: int, department: int, employee: string}
 */
function escenarioDeVigilancia(): array
{
    $site = WorkforceFixtures::site('Hotel de soporte', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site, 'Cocina');
    $employee = WorkforceFixtures::employee($site, $department, 'active', 'Youssef', 'Amrani');

    // Lunes 9: 09:00 → 17:00. Martes 10: entra a las 02:00, nueve horas despues
    // de salir, y hace 9 h 30 (RN-10 y jornada ordinaria excedida).
    PeriodReportFixtures::workDay($site, $employee, '2026-03-09', '2026-03-09 09:00', '2026-03-09 17:00');
    PeriodReportFixtures::workDay($site, $employee, '2026-03-10', '2026-03-10 02:00', '2026-03-10 11:30');

    // Y hoy esta dentro: tramo abierto sin hora de salida.
    PresenceFixtures::openShift($employee, $site);

    FrozenTime::at(PRESENCE_COMPLIANCE_SUPPORT_AHORA);

    return [
        'site' => $site,
        'department' => $department,
        'employee' => $employee,
    ];
}

/** Ninguna lectura rechazada puede dejar constancia de una divulgacion que no ocurrio. */
function nadieHaDivulgadoPresenciaNiCumplimiento(): void
{
    expect(DB::table('audit_log')->where('action', 'personal_data.accessed')->count())->toBe(0);
}

it('rechaza los TRES alcances de soporte en la presencia en vivo y en el resumen de cumplimiento', function (SupportScope $alcance): void {
    // arrange
    // EL TOKEN SE CONCEDE ANTES DE DETENER EL RELOJ, y el orden no es
    // indiferente: la vigencia de una concesion se comprueba contra el reloj
    // del sistema —`time()`, porque es infraestructura de sesion y no dominio—
    // mientras que `expires_at` se calcularia con el reloj detenido. Concedida
    // despues, la concesion nace caducada para el sistema y la peticion
    // responde `401`, que es un rechazo por el motivo equivocado.
    $token = SupportGrants::tokenFor($alcance);
    escenarioDeVigilancia();

    // act / assert
    Api::as($token)->get('/api/v1/attendance/live')->assertStatus(403);
    Api::as($token)->get('/api/v1/compliance/summary', PRESENCE_COMPLIANCE_SUPPORT_RANGO)->assertStatus(403);

    nadieHaDivulgadoPresenciaNiCumplimiento();
})->with([
    // Se para en el middleware: no lleva `attendance:read`.
    'diagnostics' => [SupportScope::Diagnostics],
    // **PASA EL MIDDLEWARE.** Lo unico que lo para son las dos policies.
    'read_only' => [SupportScope::ReadOnly],
    // Se para en el middleware: lleva `settings:*`, no el registro horario.
    'configuration' => [SupportScope::Configuration],
])->group('RF-PA-01', 'RF-PA-06', 'RF-PD-11', 'RL-19', 'ADR-020');

it('rechaza un token de soporte al que alguien le hubiera puesto el ambito a mano', function (): void {
    /*
     * La otra mitad de la regla dura 18: **la policy cierra aunque el ambito
     * abra**. Con los ambitos de serie solo `read_only` llega a las policies, asi
     * que sin este caso un cambio en la lista de alcances podria dejar a los
     * otros dos apoyados unicamente en el middleware sin que nada lo dijera.
     */

    // arrange
    // Antes de detener el reloj, por lo mismo que arriba.
    $token = SupportGrants::tokenWithAbilities(['attendance:read'], SupportScope::Diagnostics);
    escenarioDeVigilancia();

    // act / assert
    Api::as($token)->get('/api/v1/attendance/live')->assertStatus(403);
    Api::as($token)->get('/api/v1/compliance/summary', PRESENCE_COMPLIANCE_SUPPORT_RANGO)->assertStatus(403);

    nadieHaDivulgadoPresenciaNiCumplimiento();
})->group('RF-PA-01', 'RF-PA-06', 'RF-PD-11', 'RL-19', 'ADR-020');

it('los tres roles de gestion del cliente siguen leyendo las dos pantallas', function (UserRole $rol): void {
    /*
     * EL CONTROL POSITIVO. `{admin, rrhh, responsable_departamento}` es el
     * «manager+» del Anexo B y son los roles que hoy alcanzan las dos rutas: la
     * presencia en vivo es la pantalla con la que recepcion sabe quien esta
     * dentro, y el resumen de cumplimiento es el trabajo pendiente de RRHH.
     *
     * El responsable entra **acotado** a su departamento (RF-ID-03) y por eso se
     * le asigna la Cocina: lo que se comprueba aqui es que lee, no que lea a
     * todo el mundo — el alcance tiene sus ficheros, `LivePresenceScopeTest` y
     * `ComplianceSummaryScopeTest`.
     */

    // arrange
    $escenario = escenarioDeVigilancia();
    $usuario = ManagementUsers::withRole($rol);

    if ($rol === UserRole::RESPONSABLE_DEPARTAMENTO) {
        Department::query()->whereKey($escenario['department'])->update(['manager_user_id' => $usuario->id]);
    }

    $token = ManagementUsers::tokenFor($usuario);

    // act / assert
    $presencia = Api::as($token)->get('/api/v1/attendance/live');
    $presencia->assertOk();
    expect($presencia->json('data'))->toHaveCount(1);

    $cumplimiento = Api::as($token)->get('/api/v1/compliance/summary', PRESENCE_COMPLIANCE_SUPPORT_RANGO);
    $cumplimiento->assertOk();
    expect($cumplimiento->json('data'))->not->toBe([]);
})->with([
    'admin' => [UserRole::ADMIN],
    'rrhh' => [UserRole::RRHH],
    'responsable_departamento' => [UserRole::RESPONSABLE_DEPARTAMENTO],
])->group('RF-PA-01', 'RF-PA-06', 'RQ-07');
