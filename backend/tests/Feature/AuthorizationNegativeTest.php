<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Port\CardRenderer;
use App\Modules\Identity\Application\Port\InstructionsSheetRenderer;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\FakeCardRenderer;
use Tests\Support\Identity\FakeInstructionsSheetRenderer;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Regla dura 18 y RQ-07: **cada endpoint tiene su prueba de autorizacion
 * negativa, por cada rol no autorizado**, sin excepciones.
 *
 * Se prueba por PAREJAS (rol x endpoint) y no con un caso representativo: una
 * policy olvidada en un solo endpoint es indistinguible de una bien puesta hasta
 * que alguien la encuentra, y lo que se encuentra en un sistema de fichaje son
 * datos de la plantilla.
 *
 * Dos controles distintos se comprueban aqui a la vez (doc 02 §7.3):
 *
 *   - **El ambito del token**, que verifica el middleware `ability`. Es lo que
 *     deja fuera al token de quiosco y al auditor: no llevan `employees:*`.
 *   - **La policy del recurso**, que verifica el rol. Es lo que dejaria fuera a
 *     un rol con ambito pero sin permiso sobre esos datos.
 *
 * Los dos devuelven 403 a proposito: desde fuera no se distingue por que se ha
 * denegado, que es lo correcto.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // El informe por periodo y la presencia en tiempo real son funcionalidad
    // ACCESORIA (ADR-023, tarea 5.3): sin una licencia que las conceda, el
    // primero responde `402` con el aviso de licencia y la segunda degrada a
    // sondeo. Aqui se prueba la funcionalidad; su degradacion tiene fichero
    // propio, `tests/Feature/Product/LicenseDegradesAccessoriesTest.php`.
    //
    // **Nada del registro legal necesita esta llamada**: el fichaje, la consulta
    // de jornadas, el portal y la exportacion para la Inspeccion funcionan sin
    // licencia por diseño, y que sus pruebas no la hagan es la comprobacion
    // silenciosa de eso (regla dura 15).
    LicenseKeys::grantAll();
});

/**
 * Los endpoints de gestion de esta tarea, con un cuerpo valido para que lo que
 * falle sea la autorizacion y no la validacion.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function managementEndpoints(): array
{
    return [
        'listar empleados' => ['GET', '/api/v1/employees', []],
        'ver un empleado' => ['GET', '/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', []],
        'dar de alta' => ['POST', '/api/v1/employees', [
            'first_name' => 'Youssef',
            'last_name' => 'Amrani',
            'hired_at' => '2026-08-14',
        ]],
        'modificar un empleado' => ['PATCH', '/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90', [
            'first_name' => 'Lucia',
        ]],
        'dar de baja' => ['POST', '/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90/offboard', [
            'terminated_at' => '2026-09-30',
        ]],
        'listar departamentos' => ['GET', '/api/v1/departments', []],
        'ver un departamento' => ['GET', '/api/v1/departments/1', []],
        'crear un departamento' => ['POST', '/api/v1/departments', ['name' => 'Recepcion']],
        'renombrar un departamento' => ['PATCH', '/api/v1/departments/1', ['name' => 'Recepcion']],
        // El centro es un recurso singular (ADR-040): ni lista ni alta.
        'ver el centro' => ['GET', '/api/v1/site', []],
        'modificar el centro' => ['PATCH', '/api/v1/site', ['name' => 'Hotel Marina']],

        // Credenciales (tarea 1.5). Su ambito es `credentials:*` y no
        // `employees:*`: emitir una tarjeta y corregir un apellido son dos
        // potestades distintas del §7.3, y compartir ambito significaria que
        // quien puede lo segundo puede tambien emitir una tarjeta a nombre de
        // cualquiera.
        'emitir una credencial' => ['POST', '/api/v1/credentials', [
            'employee_uuid' => '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        ]],
        'revocar una credencial' => ['POST', '/api/v1/credentials/0199f0d1-2a5b-7d4f-8c32-5e6f7a8b9c01/revoke', [
            'reason' => 'Perdida',
        ]],

        // Impresion y entrega (tarea 1.10, ADR-034). Comparten el ambito
        // `credentials:*` con los dos de arriba, y aun asi entran uno a uno en la
        // matriz: la policy se declara en el `FormRequest` de cada endpoint, asi
        // que un `authorize()` que devolviera `true` en uno solo de los cuatro
        // seria invisible desde los otros tres.
        //
        // `credentials/status` es el que mas datos reparte de toda la API —nombre,
        // codigo, centro y departamento de la plantilla entera— y hasta ahora era
        // el unico endpoint de gestion sin ninguna pareja rol x endpoint probada.
        'ver el panel de credenciales' => ['GET', '/api/v1/credentials/status', []],
        // El mismo endpoint con el filtro de la tarea 2.12 (RF-QR-07). Entra
        // aparte porque un parametro nuevo se valida en el `FormRequest`, y un
        // `authorize()` que se saltara la policy cuando llega `key_id` seria
        // invisible desde la fila de arriba. Ademas es la consulta que dice a
        // quien le falta la tarjeta nueva: reparte la misma nomina.
        'ver a quien le falta reimprimir' => ['GET', '/api/v1/credentials/status?key_id=a2', []],
        'imprimir el lote pendiente' => ['POST', '/api/v1/credentials/print-batch', []],
        'imprimir una credencial' => ['POST', '/api/v1/credentials/0199f0d1-2a5b-7d4f-8c32-5e6f7a8b9c01/print', []],
        'registrar la entrega' => ['POST', '/api/v1/credentials/0199f0d1-2a5b-7d4f-8c32-5e6f7a8b9c01/deliver', []],

        // La hoja de instrucciones que se entrega con la tarjeta (tarea 5.11b,
        // RL-05). Entra como pareja propia aunque comparta ambito y policy con
        // las cuatro de arriba, por lo mismo que aquellas entre si: el
        // `authorize()` esta en su propio `FormRequest`.
        //
        // **Y aqui la tentacion es mayor que en ninguna otra ruta del grupo**:
        // este documento no lleva ningun dato de nadie ni ningun secreto, asi que
        // el dia que alguien pida «que lo pueda descargar cualquiera» el
        // argumento sonara razonable. Estas filas son las que obligan a que ese
        // cambio se haga a la vista y no por descuido.
        'descargar la hoja de instrucciones' => ['GET', '/api/v1/credentials/instructions-sheet', []],

        // PIN de respaldo (tarea 1.13, RF-ID-09). Ambito `employees:*` y policy
        // propia: restablecer el PIN de otra persona es entregarle la llave de su
        // portal y de su fichaje sin tarjeta.
        'restablecer el PIN' => ['POST', '/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90/pin/reset', []],
        'registrar la entrega del PIN' => ['POST', '/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90/pin/deliver', []],

        // Presencia en tiempo real (tarea 2.4, RF-PA-01). Ambito
        // `attendance:read` y policy propia: es «manager+» del Anexo B, asi que
        // el `auditor` recibe `403` **teniendo el ambito** —es la mitad que
        // aporta la policy— y el quiosco ni siquiera lo tiene.
        //
        // La suscripcion al canal de WebSocket es el mismo recurso por otra
        // puerta y se prueba en `Tests\Feature\Reporting\PresenceChannelAuthorizationTest`:
        // aqui no cabe porque su cuerpo necesita un nombre de canal, y lo que
        // esta matriz comprueba es el par rol x endpoint.
        'ver la presencia en vivo' => ['GET', '/api/v1/attendance/live', []],

        // Vista de cumplimiento (tarea 3.4, RF-PA-06). Mismo ambito
        // `attendance:read` y policy propia: es «manager+» del Anexo B, asi que
        // el `auditor` recibe `403` **teniendo el ambito** —auditar es mirar lo
        // que quedo escrito, no la gestion del dia— y el quiosco ni siquiera lo
        // tiene.
        //
        // **Y aqui el `403` del auditor importa mas que en ninguna otra ruta del
        // grupo**: lo que esta pantalla reparte es una lista de quien ha
        // incumplido el convenio, con nombre y departamento. El dia que alguien
        // pida «que el auditor tambien la vea», este par obliga a que el cambio
        // se haga a la vista.
        'ver la vista de cumplimiento' => ['GET', '/api/v1/compliance/summary', []],

        // Bandeja de incidencias (tarea 2.5, RF-PA-05). Ambito `incidents:*` y
        // policy propia: es «manager+» del Anexo B. El `auditor` recibe `403`
        // por partida doble —no lleva ese ambito y tampoco esta en el conjunto
        // de roles—, y el quiosco ni siquiera lo tiene.
        //
        // **Los dos endpoints entran uno a uno** aunque compartan ambito, por lo
        // mismo que los cuatro de credenciales: la policy se declara en el
        // `FormRequest` de cada uno, asi que un `authorize()` que devolviera
        // `true` en uno solo seria invisible desde el otro.
        //
        // El identificador `1` no existe en estas pruebas y da igual: lo que se
        // comprueba es que la autorizacion corta **antes** de llegar a mirar si
        // existe. Si alguna vez devolviera `404` en lugar de `403`, eso ya seria
        // el fallo.
        'ver la bandeja de incidencias' => ['GET', '/api/v1/incidents', []],
        'resolver una incidencia' => ['POST', '/api/v1/incidents/1/resolve', [
            'outcome' => 'resolved',
            'note' => 'Revisado con el parte de turno.',
        ]],

        // Informe de horas por periodo (tarea 2.8, RF-IN-01..03). Ambito
        // `reports:*` y policy propia, y aqui las dos comprobaciones dejan fuera
        // a **cuatro** roles y no a tres: el `auditor` lleva `reports:legal` —el
        // estrecho, que solo abre la exportacion para Inspeccion— y el
        // `responsable_departamento` no lleva ningun ambito de informes (§7.3),
        // asi que ninguno de los dos pasa siquiera del middleware.
        //
        // **Con `from` y `to` en la URL a proposito**: son obligatorias, y sin
        // ellas la peticion moriria en el `FormRequest` con un `422` que
        // esconderia si la autorizacion funciona. Lo que esta matriz comprueba es
        // que la denegacion ocurre **antes** de mirar los datos.
        'generar el informe por periodo' => ['GET', '/api/v1/reports/period?from=2026-03-01&to=2026-03-31', []],

        // Y su descarga como fichero (tarea 2.9, RF-IN-04). ENTRA COMO PAREJA
        // PROPIA y no se da por cubierta con la consulta de arriba: es otra ruta,
        // otro `FormRequest` y otro `authorize()`, asi que una policy olvidada
        // aqui seria invisible desde alli. Lo que sale es exactamente lo mismo,
        // con el agravante de que un fichero se reenvia por correo.
        //
        // Con `format` en la URL a proposito, por lo mismo que `from` y `to`: es
        // obligatorio, y sin el la peticion moriria en el `FormRequest` con un
        // `422` que esconderia si la autorizacion funciona.
        'descargar el informe por periodo' => ['GET', '/api/v1/reports/period/export?format=csv&from=2026-03-01&to=2026-03-31', []],

        // La salida a nomina (tarea 3.9, RF-IN-07). ENTRA COMO PAREJA PROPIA por
        // lo mismo que la descarga de arriba —otra ruta, otro `FormRequest`, otro
        // `authorize()`— y ademas porque su policy es OTRA:
        // `PayrollExportPolicy` y no `PeriodReportPolicy`. Hoy dicen lo mismo
        // (`{admin, rrhh}`), y el dia que un responsable pueda ver las horas de su
        // equipo esa concesion no puede arrastrar consigo el fichero con el que se
        // paga. Si las dos policies se fusionaran, este par lo detecta.
        //
        // Con `format`, `from` y `to` en la URL a proposito: son obligatorios, y
        // sin ellos la peticion moriria en el `FormRequest` con un `422` que
        // esconderia si la autorizacion funciona.
        'descargar la salida a nomina' => ['GET', '/api/v1/reports/payroll-export?format=csv&from=2026-03-01&to=2026-03-31', []],

        // El cuadro de impacto y adopcion (tarea 3.13, RF-IN-08). ENTRA COMO DOS
        // PAREJAS por lo mismo que sus vecinas: la consulta y la descarga son dos
        // rutas con su propio `authorize()`, y ademas su policy tiene DOS METODOS
        // —`view` y `export`— que hoy dicen lo mismo. El dia que alguien pueda ver
        // el cuadro sin poder llevarselo, una policy fusionada seria invisible
        // desde aqui.
        //
        // **Y aqui el `403` del `responsable_departamento` es el que mas importa**,
        // al reves que en la vista de cumplimiento. Alla entra acotado a su gente;
        // aqui no entra en absoluto, y no por permisos: un cuadro de adopcion de un
        // departamento de tres personas convierte «horas trabajadas» en el dato de
        // esas tres (regla dura 21). El cuadro es de la instalacion entera
        // precisamente para que eso no pueda pasar, y por eso su consulta no lleva
        // alcance que acotar.
        //
        // Sin `from` ni `to` a proposito, al reves que el informe por periodo: aqui
        // son OPCIONALES —la pantalla se abre con el mes anterior—, asi que la
        // peticion es valida y lo unico que puede fallar es la autorizacion.
        'ver el cuadro de impacto' => ['GET', '/api/v1/reports/adoption', []],
        'descargar el cuadro de impacto' => ['GET', '/api/v1/reports/adoption/export?format=csv', []],

        /*
         * Los informes generados en diferido (tarea 3.9, RF-IN-06, ADR-041).
         *
         * LAS TRES DE GESTION ENTRAN UNA A UNA, por lo mismo que la consulta y su
         * descarga: son tres rutas con su propio `authorize()` —dos en el
         * controlador y uno en el `FormRequest`—, asi que una policy olvidada en
         * cualquiera de ellas seria invisible desde las otras dos. Y lo que
         * reparten no es menos que el informe sincrono: es **el mismo informe**,
         * con el agravante de que aqui queda un fichero en el disco durante dias.
         *
         * LA CUARTA RUTA —la descarga— NO ESTA AQUI, y es deliberado: va sin
         * sesion a proposito (ADR-041), asi que no tiene policy que probar en
         * negativo. Lo que la protege es el token de un solo uso, y eso lo cubren
         * `ReportExportEndpointTest` —enlace gastado, caducado, ajeno y sin
         * token— y la zona `throttle:report-download`.
         *
         * El `POST` con `kind: payroll` entra ademas por separado mas abajo: su
         * policy es otra (`requestPayroll`) y una fusion de las dos seria
         * invisible desde esta fila.
         */
        'pedir un informe en diferido' => ['POST', '/api/v1/reports/exports', [
            'kind' => 'period',
            'format' => 'csv',
            'from' => '2026-03-01',
            'to' => '2026-03-31',
        ]],
        'listar los informes en diferido' => ['GET', '/api/v1/reports/exports', []],
        'consultar un informe en diferido' => ['GET', '/api/v1/reports/exports/019a12b4-5c6d-7e8f-9012-3456789abcde', []],

        // Contratos historizados (tarea 2.8, RF-GP-02). Ambito `employees:*` y
        // policy propia: las condiciones laborales pactadas son de `rrhh+`.
        //
        // **Los dos entran uno a uno** aunque compartan ambito, por lo mismo que
        // los cuatro de credenciales: la policy se declara en el `FormRequest` de
        // cada uno, asi que un `authorize()` que devolviera `true` en uno solo
        // seria invisible desde el otro.
        'listar los contratos de un empleado' => ['GET', '/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90/contracts', []],
        'registrar un contrato' => ['POST', '/api/v1/employees/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90/contracts', [
            'weekly_hours' => 40,
            'schedule_type' => 'turnos',
            'valid_from' => '2026-03-01',
        ]],

        ...absenceEndpoints(),
        ...installationSettingsEndpoints(),
        ...complianceProfileEndpoints(),
        ...kioskPairingEndpoints(),
    ];
}

/**
 * Las ausencias (tarea 3.10, RF-GP-04). **Seis rutas, dos ambitos.**
 *
 * Las dos de lectura llevan `employees:read` y policy «manager+»; las cuatro de
 * escritura llevan `employees:*` y policy «rrhh+». La asimetria es deliberada:
 * el `responsable_departamento` tiene que poder ver quien falta de su gente
 * —acotado, RF-ID-03— y no puede registrar, corregir ni anular nada, porque eso
 * cambia el absentismo de una persona, que es un numero con consecuencias
 * laborales.
 *
 * **Las seis entran una a una** aunque compartan ambito de dos en dos, por lo
 * mismo que los cuatro de credenciales: la policy se declara en el `FormRequest`
 * de cada endpoint —o en el `Gate::authorize` del `GET` de detalle— asi que un
 * `authorize()` que devolviera `true` en uno solo seria invisible desde los
 * otros cinco.
 *
 * **Y aqui el `403` del auditor y del quiosco pesa mas que en casi cualquier
 * otra ruta del fichero**: lo que reparte el listado es una lista de personas
 * con una categoria de ausencia al lado, y una de esas categorias es una baja
 * medica —dato de salud del art. 9 del RGPD—. El dia que alguien pida «que el
 * auditor tambien lo vea», estos pares obligan a que el cambio se haga a la
 * vista.
 *
 * Los cuerpos y los identificadores son validos a proposito, para que lo que
 * falle sea la autorizacion y no la validacion: un `422` esconderia si la policy
 * funciona, y un `404` significaria que la denegacion llega **despues** de mirar
 * los datos, que ya seria el fallo.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function absenceEndpoints(): array
{
    $uuid = '0199f4a1-6c22-7e10-9b40-2a3b4c5d6e70';

    return [
        'listar ausencias' => ['GET', '/api/v1/absences', []],
        'ver una ausencia' => ['GET', '/api/v1/absences/'.$uuid, []],
        'registrar una ausencia' => ['POST', '/api/v1/absences', [
            'employee_uuid' => '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
            'type' => 'vacation',
            'starts_on' => '2026-03-02',
            'ends_on' => '2026-03-06',
        ]],
        'corregir una ausencia' => ['PATCH', '/api/v1/absences/'.$uuid, [
            'ends_on' => '2026-03-09',
            'reason' => 'El parte de baja se prorrogo.',
        ]],
        'anular una ausencia' => ['POST', '/api/v1/absences/'.$uuid.'/void', [
            'reason' => 'Se registro a la persona equivocada.',
        ]],
        'cargar ausencias por fichero' => ['POST', '/api/v1/absences/import', ['mode' => 'validate']],
    ];
}

/**
 * El emparejamiento y la flota de quioscos (tarea 5.6, RF-PD-06). Ambito
 * `settings:*` y rol `admin`, y **solo** `admin`.
 *
 * Bloque propio por lo mismo que la configuracion y el perfil de cumplimiento, y
 * con un motivo que no tienen aquellos: **dar de alta un quiosco es crear un
 * origen de fichajes**. Todo lo que despues entre por esa tablet acaba en el
 * registro horario de alguien, y desvincularla puede dejar un hotel sin poder
 * fichar en pleno cambio de turno. El Anexo B del doc 01 situaba `/devices` en
 * «manager+»; la tarea 5.6 lo corrige a `admin` y esta matriz es lo que lo hace
 * cierto.
 *
 * **Las dos rutas publicas del emparejamiento —`/kiosk/pair` y
 * `/kiosk/pair/claim`— NO estan aqui, y no es un olvido**: son publicas por
 * necesidad —quien las llama todavia no tiene token, porque es el que viene a
 * buscar— asi que no hay rol al que denegar. Lo que las protege se prueba en
 * `Tests\Feature\Kiosk\PairingRejectionTest` (rechazos genericos y de tiempo
 * constante) y en `PairingTest` (nada se vincula sin el `confirm` de un `admin`).
 *
 * Los tres endpoints entran uno a uno aunque compartan ambito, por lo mismo que
 * los cuatro de credenciales: la policy se declara en el `FormRequest` de cada
 * uno, asi que un `authorize()` que devolviera `true` en uno solo seria invisible
 * desde los otros dos.
 *
 * El cuerpo del `confirm` es valido a proposito, para que lo que falle sea la
 * autorizacion y no la validacion: un `422` esconderia si la policy funciona.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function kioskPairingEndpoints(): array
{
    return [
        'confirmar un emparejamiento' => ['POST', '/api/v1/kiosk/pair/confirm', [
            'code' => '483921',
            'name' => 'Recepcion',
        ]],
        'listar los quioscos' => ['GET', '/api/v1/devices', []],
        // El `uuid` no existe en estas pruebas y da igual: lo que se comprueba es
        // que la autorizacion corta **antes** de llegar a mirar si existe. Si
        // alguna vez devolviera `404` en lugar de `403`, eso ya seria el fallo.
        'desvincular un quiosco' => ['POST', '/api/v1/devices/0199f3c9-1b7d-7a44-8e02-3c4d5e6f7a81/unpair', []],
    ];
}

/**
 * El perfil de cumplimiento (tarea 5.2, RF-PD-07). Ambito `settings:*` y rol
 * `admin`, y **solo** `admin`.
 *
 * Bloque propio por lo mismo que la configuracion, y con un motivo aun mas
 * fuerte: lo que hay detras de estas dos rutas es el umbral con el que se decide
 * si una jornada incumple el Estatuto de los Trabajadores y cuantos años hay que
 * conservar el registro. Quien llegue aqui sin ser el administrador de la
 * instalacion puede apagar una alerta legal o autorizar una purga.
 *
 * El cuerpo del `PATCH` es valido a proposito, para que lo que falle sea la
 * autorizacion y no la validacion.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function complianceProfileEndpoints(): array
{
    return [
        'ver el perfil de cumplimiento' => ['GET', '/api/v1/compliance-profile', []],
        'cambiar el perfil de cumplimiento' => ['PATCH', '/api/v1/compliance-profile', [
            'min_rest_hours' => 11,
        ]],
    ];
}

/**
 * La configuracion de la instalacion (tarea 5.1, RF-PD-01). Ambito `settings:*`
 * y rol `admin`, y **solo** `admin`.
 *
 * Entra como bloque propio porque ademas de la matriz general necesita algo que
 * ningun otro endpoint necesita: que **`rrhh` reciba 403**. Es el unico rol que
 * lleva todos los ambitos de gestion y aun asi no llega aqui, asi que sin una
 * prueba suya el `403` de estas dos rutas quedaria sin comprobar justo para el
 * rol que mas se le parece al autorizado.
 *
 * Los dos endpoints entran uno a uno aunque compartan ambito, por lo mismo que
 * los cuatro de credenciales: la policy se declara en el `FormRequest` de cada
 * uno —y en el `Gate::authorize` del `GET`—, asi que un `authorize()` que
 * devolviera `true` en uno solo seria invisible desde el otro.
 *
 * El cuerpo del `PATCH` es valido a proposito, para que lo que falle sea la
 * autorizacion y no la validacion: un `422` esconderia si la policy funciona.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function installationSettingsEndpoints(): array
{
    return [
        'ver la configuracion de la instalacion' => ['GET', '/api/v1/settings', []],
        'cambiar la configuracion de la instalacion' => ['PATCH', '/api/v1/settings', [
            'settings' => ['ATTENDANCE_MAX_SHIFT_HOURS' => 10],
        ]],
    ];
}

/**
 * Los portadores autenticados que NO son el administrador de la instalacion.
 *
 * Existe porque los tres bloques de arriba —emparejamiento, perfil de
 * cumplimiento y configuracion— solo se probaban contra `rrhh` (H-02 de la
 * revision interna ASVS de 2026-09). `rrhh` es el vecino mas cercano del rol
 * autorizado y por eso era el caso elegido, pero elegir el mas parecido deja sin
 * comprobar a los cuatro que llegan por otra puerta: el token de la tablet
 * colgada en la pared, el auditor, la cuenta de una persona de la plantilla y la
 * sesion que todavia no ha pasado el segundo factor.
 *
 * Los nombres se resuelven en {@see tokenDeActorSinPotestad()} y no se pasan
 * como cierres para que el conjunto se lea en la salida de la suite —«con un
 * token de quiosco / ver la configuracion»— y para que el `match` sin `default`
 * reviente si alguien anade un actor y se olvida de darle token.
 *
 * `rrhh` no entra aqui: ya tiene su prueba propia por bloque, con su motivo
 * escrito.
 *
 * @return array<string, array{0: string}>
 */
function actoresSinPotestadDeAdministracion(): array
{
    return [
        'con un token de quiosco' => ['quiosco'],
        'con un auditor' => ['auditor'],
        'con una cuenta de empleado' => ['empleado'],
        'con una sesion pendiente de segundo factor' => ['2fa pendiente'],
    ];
}

/**
 * El token de cada uno de esos actores, emitido como lo emitiria el producto.
 *
 * La sesion pendiente cuelga de una cuenta de `rrhh` —que SI lleva todos los
 * ambitos de gestion— a proposito: asi lo que deniega es el estado de la sesion
 * y no la falta de permisos (RS-06).
 */
function tokenDeActorSinPotestad(string $actor): string
{
    return match ($actor) {
        'quiosco' => ManagementUsers::kioskToken(),
        'auditor' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::AUDITOR)),
        'empleado' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::EMPLEADO)),
        '2fa pendiente' => ManagementUsers::pendingTokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        // Un actor nuevo en el conjunto sin token que emitirle revienta aqui, en
        // voz alta y con su nombre. Devolver un token cualquiera —el de `rrhh`,
        // por ejemplo— habria dado verde a una pareja que nadie estaba probando.
        default => throw new RuntimeException('No hay token definido para el actor «'.$actor.'».'),
    };
}

it('deniega a un token de quiosco cualquier endpoint de gestion', function (string $method, string $uri, array $body): void {
    // RS-04 y §7.3: «un token de quiosco comprometido NO da acceso a la
    // plantilla completa». Es el caso que mas importa de todo este fichero: el
    // token vive en una tablet compartida colgada en una pared.
    Api::as(ManagementUsers::kioskToken())->call($method, $uri, $body)->assertStatus(403);
})->with(managementEndpoints())->group('RS-04', 'RS-05', 'RQ-07', 'RF-ID-04');

it('deniega a un auditor escribir o leer plantilla', function (string $method, string $uri, array $body): void {
    // El auditor es de solo lectura y su ambito es `attendance:read`,
    // `audit:read` y `reports:legal` (§7.3): la plantilla no esta ahi.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::AUDITOR));

    Api::as($token)->call($method, $uri, $body)->assertStatus(403);
})->with(managementEndpoints())->group('RS-05', 'RQ-07', 'RF-ID-02');

/**
 * Los endpoints de gestion que un `responsable_departamento` **sigue** sin poder
 * tocar despues de la tarea 2.1.
 *
 * Es la matriz de arriba menos las dos rutas de lectura de plantilla —`GET
 * /employees` y `GET /employees/{uuid}`—, que RF-ID-03 le concede **acotadas a su
 * departamento**. Su alcance, y el `403` cuando se sale de el, se prueban en
 * `Tests\Feature\Identity\DepartmentScopeTest`: aqui no caben, porque lo que esta
 * matriz comprueba es el par rol x endpoint y aquello depende de a quien se pida.
 *
 * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
 */
function endpointsDeniedToDepartmentManager(): array
{
    $endpoints = managementEndpoints();

    unset(
        $endpoints['listar empleados'],
        $endpoints['ver un empleado'],
        // Y la presencia en vivo desde la tarea 2.4: el Anexo B la sitúa en
        // «manager+», asi que el responsable entra —**acotado a sus
        // departamentos**, tambien en los recuentos—. Ese alcance y su ausencia
        // de `403` se prueban en `Tests\Feature\Reporting\LivePresenceScopeTest`:
        // aqui no caben, porque dependen de a quien se pida.
        $endpoints['ver la presencia en vivo'],
        // Y la vista de cumplimiento desde la tarea 3.4, por lo mismo: es
        // «manager+», el responsable entra **acotado a sus departamentos**
        // —tambien en los recuentos— y su alcance se prueba en
        // `Tests\Feature\Reporting\ComplianceSummaryScopeTest`. Aqui no cabe,
        // porque depende de a quien se pida.
        $endpoints['ver la vista de cumplimiento'],
        // Y la bandeja de incidencias desde la tarea 2.5, por la misma razon: es
        // «manager+», el responsable entra **acotado a sus departamentos**, y su
        // alcance —listado sin `403`, resolucion ajena con `403` y asiento— se
        // prueba en `Tests\Feature\Compliance\IncidentScopeTest`.
        $endpoints['ver la bandeja de incidencias'],
        $endpoints['resolver una incidencia'],
        // Y las DOS de lectura de ausencias desde la tarea 3.10, por lo mismo:
        // el responsable las ve **acotadas a sus departamentos** y sin la nota
        // (RF-GP-04, RF-ID-03). Su alcance y la ausencia del campo `note` se
        // prueban en `Tests\Feature\Workforce\AbsenceScopeTest`: aqui no caben,
        // porque dependen de a quien se pida.
        //
        // **Las CUATRO de escritura se quedan**, y son la mitad que importa de
        // esta tarea: registrar, corregir o anular una ausencia cambia el
        // absentismo de una persona, y eso es de `rrhh+`.
        $endpoints['listar ausencias'],
        $endpoints['ver una ausencia'],
    );

    return $endpoints;
}

it('deniega a un responsable de departamento todo lo que no sea leer su plantilla', function (string $method, string $uri, array $body): void {
    // Tras la tarea 2.1, el responsable lee la plantilla **de su departamento** y
    // nada mas: lleva `employees:read` y no `employees:*` ni `credentials:*`.
    // Escribir, dar de baja, tocar el PIN o las credenciales sigue siendo `403`,
    // y el alcance de lo que si puede leer lo comprueba `DepartmentScopeTest`.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO));

    Api::as($token)->call($method, $uri, $body)->assertStatus(403);
})->with(endpointsDeniedToDepartmentManager())->group('RS-05', 'RQ-07', 'RF-ID-03');

it('deniega a una cuenta con rol de empleado el acceso al panel', function (string $method, string $uri, array $body): void {
    // El empleado consulta lo suyo en el portal, con ambito `self:read`
    // (RF-ID-07). Nunca datos de terceros.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::EMPLEADO));

    Api::as($token)->call($method, $uri, $body)->assertStatus(403);
})->with(managementEndpoints())->group('RS-05', 'RQ-07', 'RF-ID-07');

it('deniega a una sesion pendiente de segundo factor cualquier endpoint de gestion', function (string $method, string $uri, array $body): void {
    // RS-06: el token del `202` de `/auth/login` lleva un unico ambito,
    // `2fa:pending`, y no autoriza **nada** del producto — solo los tres
    // endpoints de `/auth/2fa/*`. Es la mitad que convierte el segundo factor en
    // obligatorio de verdad: sin esta comprobacion, «obligatorio» significaria
    // «hay una pantalla mas» y bastaria no rellenarla.
    //
    // La cuenta es de `rrhh`, que si tiene todos los ambitos de gestion: asi lo
    // que deniega es el estado de la sesion y no la falta de permisos.
    $pending = ManagementUsers::pendingTokenFor(ManagementUsers::withRole(UserRole::RRHH));

    Api::as($pending)->call($method, $uri, $body)->assertStatus(403);
})->with(managementEndpoints())->group('RS-05', 'RQ-07', 'RS-06', 'RF-ID-01');

it('deniega el acceso sin token', function (string $method, string $uri, array $body): void {
    Api::guest()->call($method, $uri, $body)
        ->assertStatus(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:unauthenticated');
})->with(managementEndpoints())->group('RS-05', 'RQ-07');

it('deniega el acceso con un token que ya no vale', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH);
    $token = ManagementUsers::tokenFor($user);

    Api::as($token)->post('/api/v1/auth/logout')->assertStatus(204);

    // Artefacto de la suite, no del producto: las llamadas de una misma prueba
    // comparten aplicacion y el guard de Sanctum cachea el usuario que ya
    // resolvio. En produccion cada peticion arranca su propia aplicacion.
    Auth::forgetGuards();

    Api::as($token)->get('/api/v1/employees')->assertStatus(401);
})->group('RQ-07', 'RF-ID-01');

it('deja pasar a un responsable a leer plantilla, que es el control positivo de RF-ID-03', function (): void {
    // Sin esto, los `403` de arriba pasarian igual si al responsable se le hubiera
    // negado la plantilla por completo, que es como estaba antes de la tarea 2.1.
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO));

    // Sin departamento asignado no alcanza a nadie, y aun asi el endpoint le
    // responde: la lista vacia es la respuesta correcta, no un `403`.
    Api::as($token)->get('/api/v1/employees')->assertStatus(200);
})->group('RQ-07', 'RF-ID-03');

it('deja pasar a RRHH y al administrador, que es lo que hace significativo el resto', function (UserRole $role): void {
    // Sin este caso, todas las pruebas de arriba pasarian igual con la API
    // apagada. Es el control positivo.
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole($role));

    Api::as($token)->get('/api/v1/employees')->assertStatus(200);

    Api::as($token)->post('/api/v1/employees', [
        'first_name' => 'Youssef',
        'last_name' => 'Amrani',
        'hired_at' => '2026-08-14',
    ])->assertStatus(201);
})->with([
    'rrhh' => UserRole::RRHH,
    'admin' => UserRole::ADMIN,
])->group('RQ-07', 'RF-ID-02');

it('deja pasar a RRHH a emitir credenciales, que es el control positivo del ambito credentials', function (): void {
    // El mismo papel que el caso de arriba: sin el, las pruebas de denegacion
    // sobre `/credentials` pasarian igual si la ruta no existiera.
    $site = WorkforceFixtures::site();
    $employee = WorkforceFixtures::employee($site);
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    Api::as($token)
        ->post('/api/v1/credentials', ['employee_uuid' => $employee])
        ->assertStatus(201);
})->group('RQ-07', 'RF-QR-01', 'RS-04');

it('deja pasar a RRHH a imprimir, entregar y ver el panel, que es el control positivo de la 1.10', function (): void {
    // El control positivo de las cuatro parejas nuevas de la matriz. Sin el, los
    // cuarenta `403` de arriba sobre `/print`, `/print-batch`, `/deliver` y
    // `/status` pasarian identicos si esas cuatro rutas estuvieran rotas: un
    // endpoint que revienta y uno que deniega se parecen mucho desde una prueba
    // que solo mira que no sea 200.
    // Sin Chromium: el puerto `CardRenderer` existe para que la impresion se
    // pueda probar sin arrancar un navegador (ver Tests\Support\Identity\
    // FakeCardRenderer). Lo que se ejercita sigue siendo todo lo demas.
    app()->instance(CardRenderer::class, new FakeCardRenderer);

    $site = WorkforceFixtures::site();
    $employee = WorkforceFixtures::employee($site);
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    $uuid = Api::as($token)
        ->post('/api/v1/credentials', ['employee_uuid' => $employee])
        ->assertStatus(201)
        ->json('uuid');

    expect($uuid)->toBeString();

    $credential = is_string($uuid) ? $uuid : '';

    Api::as($token)->post('/api/v1/credentials/'.$credential.'/print')
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');

    Api::as($token)->post('/api/v1/credentials/'.$credential.'/deliver')
        ->assertStatus(200);

    // Ya no queda nada pendiente de imprimir, y el lote lo dice con `204`.
    Api::as($token)->post('/api/v1/credentials/print-batch')->assertStatus(204);

    Api::as($token)->get('/api/v1/credentials/status')->assertStatus(200);

    // Y la hoja que se entrega con la tarjeta (tarea 5.11b, RL-05): sin este
    // control, los `403` de arriba sobre `/instructions-sheet` pasarian
    // identicos si la ruta no existiera. Tambien sin Chromium, por lo mismo.
    app()->instance(InstructionsSheetRenderer::class, new FakeInstructionsSheetRenderer);

    Api::as($token)->get('/api/v1/credentials/instructions-sheet')
        ->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf');
})->group('RQ-07', 'RF-QR-06', 'RF-QR-08', 'RL-05');

it('deja pasar a RRHH a restablecer y entregar el PIN, control positivo de la 1.13', function (): void {
    // Lo mismo para las dos parejas nuevas de `/pin/*`.
    $site = WorkforceFixtures::site();
    $employee = WorkforceFixtures::employee($site);
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    Api::as($token)->post('/api/v1/employees/'.$employee.'/pin/reset')->assertStatus(200);
    Api::as($token)->post('/api/v1/employees/'.$employee.'/pin/deliver')->assertStatus(200);
})->group('RQ-07', 'RF-ID-09');

it('deja pasar a RRHH al informe por periodo y a los contratos, control positivo de la 2.8', function (): void {
    // El mismo papel que los controles positivos de arriba: sin el, los tres
    // bloques de `403` sobre `/reports/period` y `/employees/{uuid}/contracts`
    // pasarian identicos si esas rutas estuvieran rotas o no existieran. Un
    // endpoint que revienta y uno que deniega se parecen mucho desde una prueba
    // que solo mira que no sea 200.
    $site = WorkforceFixtures::site();
    $employee = WorkforceFixtures::employee($site);
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    Api::as($token)
        ->get('/api/v1/reports/period', ['from' => '2026-03-01', 'to' => '2026-03-31', 'granularity' => 'month'])
        ->assertStatus(200);

    // Y la descarga de la 2.9, por lo mismo: sin este control, los `403` sobre
    // `/reports/period/export` pasarian igual si esa ruta no existiera.
    Api::as($token)
        ->get('/api/v1/reports/period/export', [
            'format' => 'csv',
            'from' => '2026-03-01',
            'to' => '2026-03-31',
            'granularity' => 'month',
        ])
        ->assertStatus(200);

    Api::as($token)
        ->post('/api/v1/employees/'.$employee.'/contracts', [
            'weekly_hours' => 40,
            'schedule_type' => 'turnos',
            'valid_from' => '2026-03-01',
        ])
        ->assertStatus(201);

    Api::as($token)->get('/api/v1/employees/'.$employee.'/contracts')->assertStatus(200);

    // Y la salida a nomina de la 3.9 (RF-IN-07), por lo mismo: sin este control,
    // los `403` sobre `/reports/payroll-export` pasarian igual si esa ruta
    // estuviera rota. `rrhh` es la otra mitad de «rrhh+», y es la cuenta que de
    // verdad la usa: `admin` tiene su propio control positivo en
    // `PayrollExportTest`.
    Api::as($token)
        ->get('/api/v1/reports/payroll-export', [
            'format' => 'csv',
            'from' => '2026-03-01',
            'to' => '2026-03-31',
        ])
        ->assertStatus(200);
})->group('RQ-07', 'RF-IN-01', 'RF-IN-04', 'RF-IN-07', 'RF-GP-02');

it('deniega a un token de quiosco emitir o revocar credenciales', function (string $uri, array $body): void {
    // RS-04 con nombre y apellidos. El token del quiosco lleva `scan:write`,
    // `roster:read` y `heartbeat:write` (§7.3): ninguno de los tres abre esta
    // puerta. Si la abriera, quien robase la tablet de la puerta de servicio
    // podria fabricarse una tarjeta a nombre de cualquiera.
    Api::as(ManagementUsers::kioskToken())->post($uri, $body)->assertStatus(403);
})->with([
    'emitir' => ['/api/v1/credentials', ['employee_uuid' => '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90']],
    'revocar' => ['/api/v1/credentials/0199f0d1-2a5b-7d4f-8c32-5e6f7a8b9c01/revoke', ['reason' => 'Perdida']],
])->group('RS-04', 'RF-QR-01', 'RF-QR-03');

it('deniega a RRHH la configuracion de la instalacion', function (string $method, string $uri, array $body): void {
    // El caso que la matriz general no cubre: `rrhh` lleva todos los ambitos de
    // gestion —corrige fichajes, emite credenciales, genera informes— y aun asi
    // no llega aqui. Corregir un tramo deja traza sobre UNA jornada; mover el
    // anti-rebote cambia el calculo de TODAS las siguientes (RF-PD-01, ADR-017).
    //
    // Falla por partida doble y las dos mitades cuentan: no lleva `settings:*`
    // en el token (§7.3) y tampoco esta en `SettingsPolicy`.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    Api::as($token)->call($method, $uri, $body)->assertStatus(403);
})->with(installationSettingsEndpoints())->group('RF-PD-01', 'RS-05', 'RQ-07');

it('deniega la configuracion de la instalacion a todo portador que no es el administrador', function (string $actor, string $method, string $uri, array $body): void {
    // Los otros cuatro actores de la matriz general, sobre el bloque que hasta
    // ahora solo se probaba contra `rrhh`. El que mas pesa es el token de
    // quiosco: detras de `PATCH /settings` esta el anti-rebote, la jornada
    // maxima y la marca de la instalacion (RF-PD-01, ADR-017), y la tablet que
    // lo lleva esta colgada de una pared en un pasillo de servicio.
    Api::as(tokenDeActorSinPotestad($actor))->call($method, $uri, $body)->assertStatus(403);
})->with(actoresSinPotestadDeAdministracion())->with(installationSettingsEndpoints())
    ->group('RF-PD-01', 'RS-04', 'RS-05', 'RQ-07');

it('deniega la configuracion de la instalacion sin token', function (string $method, string $uri, array $body): void {
    // El sexto actor, y el unico que no responde `403`: sin credenciales la
    // respuesta es `401` y lo dice con su tipo de problema, no con un cuerpo
    // vacio.
    Api::guest()->call($method, $uri, $body)
        ->assertStatus(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:unauthenticated');
})->with(installationSettingsEndpoints())->group('RF-PD-01', 'RS-05', 'RQ-07');

it('deniega a RRHH el perfil de cumplimiento', function (string $method, string $uri, array $body): void {
    // Mismo caso y mismo motivo que el de arriba, sobre los umbrales LEGALES.
    // `rrhh` corrige fichajes con motivo y traza sobre una jornada; bajar
    // `min_rest_hours` hace que dejen de saltar las alertas de descanso
    // insuficiente de toda la plantilla, hacia delante, sin que ninguna jornada
    // cambie de aspecto (RF-PD-07, regla dura 14).
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    Api::as($token)->call($method, $uri, $body)->assertStatus(403);
})->with(complianceProfileEndpoints())->group('RF-PD-07', 'RS-05', 'RQ-07');

it('deniega el perfil de cumplimiento a todo portador que no es el administrador', function (string $actor, string $method, string $uri, array $body): void {
    // Lo mismo sobre los umbrales LEGALES. Aqui el actor que mas incomoda es el
    // **auditor**: es el rol que existe para mirar el cumplimiento, y por esa
    // cercania es el que nadie comprobaria. Mirar si una jornada incumple y
    // **decidir a partir de cuando incumple** son dos potestades distintas: la
    // segunda cambia `min_rest_hours` y `retention_years` para toda la plantilla
    // hacia delante (RL-01, RL-02, regla dura 14).
    Api::as(tokenDeActorSinPotestad($actor))->call($method, $uri, $body)->assertStatus(403);
})->with(actoresSinPotestadDeAdministracion())->with(complianceProfileEndpoints())
    ->group('RF-PD-07', 'RS-04', 'RS-05', 'RQ-07');

it('deniega el perfil de cumplimiento sin token', function (string $method, string $uri, array $body): void {
    Api::guest()->call($method, $uri, $body)
        ->assertStatus(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:unauthenticated');
})->with(complianceProfileEndpoints())->group('RF-PD-07', 'RS-05', 'RQ-07');

it('deja pasar al administrador a la configuracion, que es el control positivo de la 5.1', function (): void {
    // Sin esto, los `403` de arriba pasarian identicos si las dos rutas
    // estuvieran rotas o no existieran: un endpoint que revienta y uno que
    // deniega se parecen mucho desde una prueba que solo mira que no sea 200.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($token)->get('/api/v1/settings')->assertStatus(200);

    Api::as($token)
        ->patch('/api/v1/settings', ['settings' => ['ATTENDANCE_MAX_SHIFT_HOURS' => 10]])
        ->assertStatus(200);
})->group('RF-PD-01', 'RQ-07');

it('deniega a RRHH el emparejamiento y la flota de quioscos', function (string $method, string $uri, array $body): void {
    // Mismo caso y mismo motivo que los dos de arriba, sobre los quioscos. `rrhh`
    // emite credenciales y corrige fichajes, y aun asi no llega aqui: dar de alta
    // una tablet es crear un ORIGEN de fichajes, y desvincularla puede dejar un
    // hotel sin poder fichar en pleno cambio de turno (RF-PD-06, §7.3 nota 5).
    //
    // Falla por partida doble y las dos mitades cuentan: no lleva `settings:*` en
    // el token y tampoco esta en `KioskPairingPolicy`.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    Api::as($token)->call($method, $uri, $body)->assertStatus(403);
})->with(kioskPairingEndpoints())->group('RF-PD-06', 'RS-04', 'RQ-07');

it('deniega el emparejamiento y la flota de quioscos a todo portador que no es el administrador', function (string $actor, string $method, string $uri, array $body): void {
    // Y aqui el actor decisivo es el propio **token de quiosco**, porque es el
    // unico caso de toda la matriz en el que el portador comprometido y el
    // recurso son la misma familia de cosas: si una tablet pudiera confirmar
    // emparejamientos, quien se llevara una podria dar de alta las suyas —origen
    // de fichajes nuevos— o desvincular las del hotel en pleno cambio de turno
    // (RF-PD-06, §7.3 nota 5).
    Api::as(tokenDeActorSinPotestad($actor))->call($method, $uri, $body)->assertStatus(403);
})->with(actoresSinPotestadDeAdministracion())->with(kioskPairingEndpoints())
    ->group('RF-PD-06', 'RS-04', 'RS-05', 'RQ-07');

it('deniega el emparejamiento y la flota de quioscos sin token', function (string $method, string $uri, array $body): void {
    // `POST /kiosk/pair/confirm` sin token entra como caso propio y no por
    // descuido: la ruta HERMANA, `POST /kiosk/pair`, es publica a proposito
    // —quien pide el codigo todavia no tiene token— y estan a dos lineas de
    // distancia en `api_v1.php`. Que `confirm` se quedara fuera de la guarda por
    // copiar el grupo de al lado es el error mas facil de cometer de todo el
    // fichero de rutas.
    Api::guest()->call($method, $uri, $body)
        ->assertStatus(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:unauthenticated');
})->with(kioskPairingEndpoints())->group('RF-PD-06', 'RS-05', 'RQ-07');

it('deja pasar al administrador a vincular y gestionar quioscos, control positivo de la 5.6', function (): void {
    // Sin esto, los `403` de arriba pasarian identicos si las tres rutas
    // estuvieran rotas o no existieran: un endpoint que revienta y uno que
    // deniega se parecen mucho desde una prueba que solo mira que no sea 200.
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    // La tablet pide su codigo sin autenticarse: es la ruta publica.
    /** @var array{code: string} $ticket */
    $ticket = Api::guest()->post('/api/v1/kiosk/pair', ['app_version' => '1.4.2'])->json();

    /** @var string $uuid */
    $uuid = Api::as($token)
        ->post('/api/v1/kiosk/pair/confirm', ['code' => $ticket['code'], 'name' => 'Recepcion'])
        ->assertStatus(200)
        ->json('device.uuid');

    Api::as($token)->get('/api/v1/devices')->assertStatus(200);
    Api::as($token)->post('/api/v1/devices/'.$uuid.'/unpair')->assertStatus(200);
})->group('RF-PD-06', 'RQ-07');

it('deja pasar al administrador al perfil de cumplimiento, que es el control positivo de la 5.2', function (): void {
    // Sin esto, los `403` de arriba pasarian identicos si las dos rutas
    // estuvieran rotas o no existieran.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    // Con centro: sin el, las dos rutas responden `404` —el estado de antes de
    // la puesta en marcha— y este control no distinguiria una policy correcta de
    // una ruta rota.
    WorkforceFixtures::site();

    Api::as($token)->get('/api/v1/compliance-profile')->assertStatus(200);

    Api::as($token)
        ->patch('/api/v1/compliance-profile', ['min_rest_hours' => 11])
        ->assertStatus(200);
})->group('RF-PD-07', 'RQ-07');

/*
 * ---------------------------------------------------------------------------
 * La salida a nomina EN DIFERIDO tiene su propia policy (tarea 3.9, RF-IN-07)
 * ---------------------------------------------------------------------------
 *
 * `POST /api/v1/reports/exports` corre una policy u otra segun `kind`:
 * `request` para el informe de horas y `requestPayroll` para la nomina (Anexo B
 * del doc 01: rol `rrhh`). Hoy las dos dicen lo mismo —`{admin, rrhh}`— y por
 * eso esta prueba parece redundante; deja de serlo el dia en que un
 * `responsable_departamento` reciba `reports:*` para ver las horas de su equipo,
 * que es a donde apunta RF-ID-03. Ese dia entrara en `request` y **no** en
 * `requestPayroll`, y si alguien hubiera fusionado las dos policies esta fila lo
 * detecta.
 */
it('deniega al responsable de departamento pedir la nomina en diferido', function (): void {
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO));

    Api::as($token)->post('/api/v1/reports/exports', [
        'kind' => 'payroll',
        'format' => 'csv',
        'from' => '2026-03-01',
        'to' => '2026-03-31',
    ])->assertStatus(403);
})->group('RF-IN-07', 'RF-ID-03', 'RS-05', 'RQ-07');

it('deniega al auditor pedir la nomina en diferido', function (): void {
    // El `auditor` lleva `reports:legal`, el ambito estrecho: lo suyo es el
    // registro normalizado para un requerimiento (RF-IN-05), no el fichero con el
    // que se paga.
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::AUDITOR));

    Api::as($token)->post('/api/v1/reports/exports', [
        'kind' => 'payroll',
        'format' => 'csv',
        'from' => '2026-03-01',
        'to' => '2026-03-31',
    ])->assertStatus(403);
})->group('RF-IN-07', 'RS-05', 'RQ-07');

it('deja pasar a RRHH a pedir un informe en diferido, que es el control positivo de la 3.9', function (): void {
    // Sin esto, los `403` de arriba pasarian identicos si la ruta estuviera rota o
    // no existiera: un endpoint que revienta y uno que deniega se parecen mucho
    // desde una prueba que solo mira que no sea 200.
    WorkforceFixtures::site();
    Queue::fake();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->post('/api/v1/reports/exports', [
            'kind' => 'period',
            'format' => 'csv',
            'from' => '2026-03-01',
            'to' => '2026-03-31',
        ])
        ->assertStatus(202);
})->group('RF-IN-06', 'RQ-07');
