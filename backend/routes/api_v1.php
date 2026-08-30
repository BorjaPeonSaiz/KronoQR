<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\ReadinessController;
use App\Modules\Attendance\Http\Controller\PinScanController;
use App\Modules\Attendance\Http\Controller\ScanBatchController;
use App\Modules\Attendance\Http\Controller\ScanController;
use App\Modules\Attendance\Http\Controller\ShiftEntryController;
use App\Modules\Attendance\Http\Controller\VoidShiftEntryController;
use App\Modules\Compliance\Http\Controller\IncidentController;
use App\Modules\Compliance\Http\Controller\LegalExportController;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Identity\Http\Controller\CredentialController;
use App\Modules\Identity\Http\Controller\CredentialStatusController;
use App\Modules\Identity\Http\Controller\CurrentUserController;
use App\Modules\Identity\Http\Controller\DeliverCredentialController;
use App\Modules\Identity\Http\Controller\LoginController;
use App\Modules\Identity\Http\Controller\LogoutController;
use App\Modules\Identity\Http\Controller\PortalLoginController;
use App\Modules\Identity\Http\Controller\PrintCredentialBatchController;
use App\Modules\Identity\Http\Controller\PrintCredentialController;
use App\Modules\Identity\Http\Controller\RevokeCredentialController;
use App\Modules\Identity\Http\Controller\TwoFactorController;
use App\Modules\Kiosk\Http\Controller\HeartbeatController;
use App\Modules\Kiosk\Http\Controller\RosterController;
use App\Modules\Reporting\Http\Controller\EmployeeWorkDayController;
use App\Modules\Reporting\Http\Controller\LivePresenceController;
use App\Modules\Reporting\Http\Controller\MyWorkDayController;
use App\Modules\Reporting\Http\Controller\MyWorkDayExportController;
use App\Modules\Reporting\Http\Controller\PeriodReportController;
use App\Modules\Workforce\Http\Controller\DepartmentController;
use App\Modules\Workforce\Http\Controller\EmployeeController;
use App\Modules\Workforce\Http\Controller\EmployeePinController;
use App\Modules\Workforce\Http\Controller\EmploymentContractController;
use App\Modules\Workforce\Http\Controller\OffboardEmployeeController;
use App\Modules\Workforce\Http\Controller\SiteController;
use Illuminate\Support\Facades\Route;

/*
 * API del producto, version 1.
 *
 * Todas las rutas de este fichero cuelgan de /api/v1: la version va en la
 * ruta, no en una cabecera (ADR-012), y el prefijo se declara una sola vez, en
 * bootstrap/app.php.
 *
 * El contrato docs/api/openapi.yaml es la fuente de verdad de la API y se
 * modifica ANTES que el codigo (ADR-013): ningun endpoint entra aqui antes de
 * estar descrito alli.
 *
 * DOS COMPROBACIONES POR RUTA, NO UNA (doc 02 §7.3, regla dura 18). El
 * middleware `ability` verifica el AMBITO del token y la policy del recurso
 * verifica SOBRE QUE DATOS. Con las dos, un token de quiosco robado no alcanza
 * estos endpoints aunque su portador tuviera rol, y un rol sin ambito tampoco.
 * La policy se declara en el FormRequest o en el controlador de cada endpoint;
 * aqui esta la mitad del ambito.
 */

/*
 * Las dos sondas del Anexo B. Las consume la infraestructura —Docker, Nginx, el
 * orquestador y el actualizador de la tarea 5.7—, no las aplicaciones cliente.
 *
 * LAS UNICAS DOS RUTAS DE TODA LA API SIN `auth:sanctum` NI POLICY, y no es un
 * olvido de la regla dura 18: el contrato las declara `security: []` porque
 * quien las consulta lo hace ANTES de que exista sesion alguna —un contenedor
 * que arranca no tiene con que autenticarse—. Que no se alcancen desde fuera de
 * la red del cliente lo decide Nginx (§7.2), no la aplicacion. Ninguna de las
 * dos revela nada: una devuelve un numero de version y la otra un si o un no.
 *
 * TAMPOCO LLEVAN `throttle`, Y ESO SI ES DELIBERADO. El limitador de Laravel
 * cuenta en la cache, que en produccion es Redis: una sonda de VIDA que
 * consultara Redis para saber si puede responder dejaria de responder cuando
 * Redis se caiga, y Docker reiniciaria el contenedor de PHP por un fallo que no
 * es suyo. El techo de peticiones de estas dos rutas es el de Nginx.
 *
 * SON DOS SONDAS Y NO UNA porque responden a preguntas distintas: `/health` dice
 * «este proceso esta vivo» (si responde que no, reinicia el contenedor) y
 * `/ready` dice «este proceso puede atender trafico» (si responde que no, no le
 * mandes peticiones y espera). Fundirlas en una es como se acaba reiniciando PHP
 * cada vez que la base de datos tarda en arrancar.
 */
Route::get('/health', HealthController::class)->name('health.live');

Route::get('/ready', ReadinessController::class)->name('health.ready');

/*
 * POST /api/v1/scan — el escaneo del quiosco (RF-AT-01, tarea 1.4).
 *
 * `scan:write` y nada mas. Es el unico ambito que necesita una tablet para
 * fichar, y es lo que impide que un token de quiosco robado —vive colgado de una
 * pared— alcance la plantilla (RS-04, doc 02 §7.3). La otra mitad de la
 * autorizacion es la policy `attendance.scan`, que comprueba que quien porta el
 * token es un dispositivo y no una sesion de gestion (regla dura 18).
 *
 * LOS LIMITES SON DOS CAPAS Y NINGUNA SUSTITUYE A LA OTRA (§7.1, RS-02). Nginx
 * limita por ORIGEN —600 r/m con rafaga de 50 desde `KIOSK_VLAN_CIDR`, 30 r/m
 * con rafaga de 10 desde cualquier otro sitio— y no puede hacer mas, porque no
 * sabe que token trae la peticion y en un hotel todos los quioscos salen por la
 * misma IP. `throttle:scan` limita por DISPOSITIVO, que es lo que impide que una
 * tablet averiada consuma la cuota de las demas. Los numeros salen de
 * `config/kiosk.php` (regla dura 13) y el limitador se declara en
 * `AttendanceServiceProvider`.
 *
 * EL ORDEN DE LOS MIDDLEWARES IMPORTA: `throttle` va DESPUES de `auth:sanctum`
 * para que la clave del limite pueda ser el dispositivo del token. Delante solo
 * podria limitar por IP, que es justo lo que no distingue quioscos.
 */
Route::post('/scan', ScanController::class)
    ->middleware(['auth:sanctum', 'ability:'.TokenAbility::SCAN_WRITE->value, 'throttle:scan'])
    ->name('attendance.scan');

/*
 * POST /api/v1/scan/batch — sincronizacion de la cola offline (RF-KI-04, §6).
 *
 * Mismo ambito que `/scan` y no uno propio: sincronizar es fichar con retraso,
 * no una potestad distinta. Lo que si es propio es el limite, porque un lote trae
 * cincuenta escaneos por peticion y compartir contador con el endpoint individual
 * obligaria a elegir entre asfixiar el drenaje o dejar `/scan` sin techo.
 *
 * Responde 207 pase lo que pase (regla dura 19): un elemento rechazado no invalida
 * el envio, y cada resultado lleva su propio codigo.
 */
Route::post('/scan/batch', ScanBatchController::class)
    ->middleware(['auth:sanctum', 'ability:'.TokenAbility::SCAN_WRITE->value, 'throttle:scan-batch'])
    ->name('attendance.scan.batch');

/*
 * POST /api/v1/scan/pin — fichaje de respaldo por PIN (RF-AT-11, RS-12, tarea
 * 1.12).
 *
 * MISMO AMBITO QUE `/scan`, Y NO UNO PROPIO. Fichar sin tarjeta sigue siendo
 * fichar: no es una potestad distinta, es la misma con otra credencial. Un
 * ambito propio habria obligado ademas a reemitir el token de todos los quioscos
 * instalados para activar una funcionalidad que RF-AT-11 declara obligatoria, y
 * un quiosco al que se le olvidara el ambito dejaria sin fichar a quien llegara
 * sin tarjeta —regla dura 19— sin que nadie se enterara hasta ese momento.
 *
 * ZONA DE LIMITE PROPIA, `scan-pin`, Y ESA SI TIENE QUE SERLO. Aqui no se frena
 * un ritmo de fichaje, se frena FUERZA BRUTA sobre un espacio de 10^6 (RS-12), y
 * los numeros razonables son dos ordenes de magnitud mas bajos que los de `/scan`:
 * una persona teclea un codigo y seis digitos en decenas de segundos, no en
 * milisegundos. Compartir contador con `/scan` habria significado elegir entre
 * dejar el PIN practicamente sin techo o asfixiar el fichaje por tarjeta.
 *
 * TRES CONTROLES DISTINTOS Y NINGUNO SUSTITUYE A LOS OTROS (§7.1, §7.5, RS-12):
 * Nginx limita por ORIGEN, `throttle:scan-pin` limita por DISPOSITIVO y por IP, y
 * el bloqueo escalonado del caso de uso cuenta FALLOS POR EMPLEADO. El primero no
 * distingue quioscos; el segundo no distingue a quien prueba PIN de quien ficha;
 * el tercero no ve a quien prueba codigos al azar. Hacen falta los tres.
 */
Route::post('/scan/pin', PinScanController::class)
    ->middleware(['auth:sanctum', 'ability:'.TokenAbility::SCAN_WRITE->value, 'throttle:scan-pin'])
    ->name('attendance.scan.pin');

/*
 * Correcciones trazadas del registro horario (RF-PA-04, RN-13, tarea 1.15).
 *
 * AMBITO PROPIO, `attendance:correct`, Y NO `attendance:read` (§7.3). Leer el
 * registro de alguien y rectificarlo son dos potestades distintas: un `auditor`
 * tiene la primera y no puede tener la segunda, porque auditar es mirar. Con un
 * solo ambito, quien puede consultar la presencia podria cambiar las horas de
 * cualquiera.
 *
 * LA OTRA MITAD ES `ShiftEntryPolicy`, que comprueba el ROL y ademas distingue
 * las dos potestades que el ambito no separa: `manager+` crea y corrige,
 * `rrhh+` anula (plan 1.15, paso 6, regla dura 18). Se declara en cada
 * FormRequest.
 *
 * NO HAY `DELETE` (regla dura 5): anular es un POST con nombre propio, igual
 * que la baja de un empleado y que la revocacion de una credencial. La fila se
 * queda en la tabla con su motivo y su firma.
 *
 * EL `{uuid}` IDENTIFICA UNA VERSION, no un tramo a lo largo del tiempo
 * (ADR-035): corregir crea una fila nueva con identificador propio, asi que el
 * que devolvio la ultima correccion es el que hay que usar en la siguiente.
 *
 * `throttle:management` POR LO QUE ESCRIBEN CUANDO DENIEGAN. Las tres rutas pasan
 * por `ScopeGuard` (RF-ID-03) y cada denegacion por alcance escribe `access.denied`
 * en `audit_log`, que toma el candado global de ADR-010 — el mismo por el que pasa
 * cada fichaje. Sin techo por cuenta, un bucle sobre UUID ajenos mete escrituras
 * ilimitadas en el camino critico del cambio de turno. La otra mitad de esa
 * defensa es la agrupacion de denegaciones repetidas, detras del puerto
 * `AuthorizationJournal`. La zona se declara en `IdentityServiceProvider`.
 */
Route::middleware([
    'auth:sanctum',
    'ability:'.TokenAbility::ATTENDANCE_CORRECT->value,
    'throttle:management',
])->group(function (): void {
    Route::post('/shift-entries', [ShiftEntryController::class, 'store'])
        ->name('attendance.shift-entries.store');

    Route::patch('/shift-entries/{uuid}', [ShiftEntryController::class, 'update'])
        ->whereUuid('uuid')
        ->name('attendance.shift-entries.update');

    Route::post('/shift-entries/{uuid}/void', VoidShiftEntryController::class)
        ->whereUuid('uuid')
        ->name('attendance.shift-entries.void');
});

/*
 * GET /api/v1/attendance/live — presencia en tiempo real (RF-PA-01, RF-PA-02,
 * tarea 2.4).
 *
 * `attendance:read`, EL MISMO AMBITO QUE EL REGISTRO HORARIO Y QUE EL CANAL DE
 * WEBSOCKET. Los tres responden a la misma pregunta —«¿que horas ha hecho esta
 * gente?»— con distinta frescura, y separarlos habria significado poder conceder
 * la vista en vivo a quien no puede leer el registro, que es una distincion sin
 * sentido: la presencia de ahora es el registro de dentro de un rato. El §7.3 ya
 * describe este ambito como «consultar presencia, jornadas y tramos».
 *
 * LA OTRA MITAD ES `LivePresencePolicy`, que comprueba el ROL: `manager+` del
 * Anexo B, que desde la tarea 2.1 es `{admin, rrhh, responsable_departamento}`
 * (regla dura 18). Es la mitad que deja fuera al `auditor`, que lleva
 * `attendance:read` en el token y aun asi recibe `403`: auditar es mirar lo que
 * quedo escrito, no quien esta en la cocina ahora.
 *
 * EL ALCANCE POR DEPARTAMENTO NO SE COMPRUEBA AQUI: entra **en la consulta**
 * (RF-ID-03), incluidos los recuentos. Es un listado, y un listado acota en vez
 * de denegar.
 *
 * `throttle:management` PORQUE ESTE ENDPOINT SE SONDEA. Cuando el WebSocket no
 * llega, el panel lo pide cada 15 s (RNF-D-03), y cada peticion deja ademas un
 * asiento de divulgacion agrupado (RS-05). Sin techo por cuenta, una pestaña en
 * bucle de reintento golpea la misma base de datos que atiende el fichaje
 * (RNF-P-02).
 *
 * NO ADMITE `site_id` (ADR-040: hay un centro) NI PAGINACION: la respuesta es
 * una fila por persona del alcance y el panel la virtualiza. El porque esta
 * escrito en el contrato.
 */
Route::get('/attendance/live', LivePresenceController::class)
    ->middleware([
        'auth:sanctum',
        'ability:'.TokenAbility::ATTENDANCE_READ->value,
        'throttle:management',
    ])
    ->name('reporting.attendance.live');

/*
 * GET /api/v1/employees/{uuid}/workdays — el registro horario de una persona
 * (RF-PA-03, tarea 1.16).
 *
 * `attendance:read` Y NO `employees:*`, aunque la ruta cuelgue de `/employees`.
 * Lo que se lee aqui no es la ficha de nadie: son sus horas de trabajo. Son dos
 * potestades distintas del §7.3 y compartir ambito significaria que quien puede
 * corregir un apellido puede tambien ver a que hora entro y salio cada dia.
 *
 * Y NO `attendance:correct`, que es el ambito de las tres rutas de correccion de
 * arriba: mirar el registro y cambiarlo no son la misma cosa (RF-PA-04).
 *
 * LA OTRA MITAD ES `WorkDayJournalPolicy`, que comprueba el ROL: `manager+` del
 * Anexo B, que en esta fase es `{admin, rrhh}` (regla dura 18). Es la mitad que
 * deja fuera al `auditor` y al `responsable_departamento`, que llevan
 * `attendance:read` en el token y aun asi reciben `403`: el primero tiene la
 * exportacion legal de la tarea 1.17 y el segundo no adquiere alcance propio
 * hasta RF-ID-03 (tarea 2.1).
 *
 * EL `self` DEL ANEXO B NO ENTRA POR AQUI. El propio empleado consulta lo suyo
 * en `GET /api/v1/me/workdays`, con sesion de portal y ambito `self:read`
 * (ADR-015, tarea 1.11): un token de portal no alcanza esta ruta porque le falta
 * el ambito, que es lo que impide que el portal de una persona sirva para mirar
 * el registro de otra.
 *
 * FUERA DEL GRUPO DE `employees:*` A PROPOSITO, aunque comparta prefijo de URL.
 * Meterla dentro le habria dado el ambito del grupo y el aparente cobijo de su
 * policy, que es de plantilla y no de registro horario.
 */
Route::get('/employees/{uuid}/workdays', EmployeeWorkDayController::class)
    ->middleware([
        'auth:sanctum',
        'ability:'.TokenAbility::ATTENDANCE_READ->value,
        // Pasa por `ScopeGuard`, asi que una denegacion por alcance escribe en
        // `audit_log` bajo el candado global de ADR-010. Ver la zona `management`
        // en `IdentityServiceProvider`.
        'throttle:management',
    ])
    ->whereUuid('uuid')
    ->name('reporting.employees.workdays');

/*
 * GET /api/v1/reports/legal-export — exportacion para la Inspeccion de Trabajo
 * (RF-IN-05, RL-03, RL-06, tarea 1.17).
 *
 * DOS AMBITOS Y CUALQUIERA DE LOS DOS VALE. El middleware `ability` de este
 * repositorio es `CheckForAnyAbility` (bootstrap/app.php), asi que la lista
 * separada por comas significa «o». Y tiene que significar «o»: el `auditor`
 * lleva `reports:legal` —el ambito estrecho, el unico informe que puede pedir— y
 * RRHH lleva `reports:*`, la familia entera. Exigir los dos dejaria fuera
 * precisamente al rol cuya funcion es esta.
 *
 * LA OTRA MITAD ES `LegalExportPolicy`, que comprueba el ROL: `auditor`, `rrhh`
 * y `admin` (Anexo B, regla dura 18). Se declara en el FormRequest.
 *
 * ES UN `GET` AUNQUE QUEDE AUDITADO. Solo lee: lo que cambia el registro horario
 * son las correcciones, y aquellas si son `POST`. Que descargarlo deje asiento
 * en `audit_log` (RS-05) no lo convierte en una escritura.
 *
 * NO ADMITE `site_id` NI `department`. O la plantilla completa, o una persona:
 * son los dos alcances que el asiento de auditoria y la cabecera de criterios
 * del fichero saben describir, y los tres tienen que decir lo mismo.
 */
/*
 * GET /api/v1/reports/period — horas por periodo, agregados y comparativa con lo
 * contratado (RF-IN-01, RF-IN-02, RF-IN-03, tarea 2.8).
 *
 * `reports:*` Y NO `reports:legal`. Son dos ambitos distintos del §7.3 y la
 * diferencia es exactamente el `auditor`: lleva el estrecho —el unico informe que
 * puede pedir es la exportacion normalizada para un requerimiento (RF-IN-05)— y
 * no lleva la familia. Compartir ambito le habria dado el cuadro de horas
 * trabajadas frente a contratadas, que es una herramienta de gestion de personal
 * y no de auditoria.
 *
 * NO ES «O», al contrario que la exportacion legal de abajo, que si acepta
 * cualquiera de los dos: aqui solo vale `reports:*`.
 *
 * EL `responsable_departamento` NO LLEGA, Y NO ES UN OLVIDO. El §7.3 no le da
 * `reports:*` —tiene `attendance:read`, `attendance:correct`, `incidents:*` y
 * `employees:read`—, asi que se queda en el middleware. `PeriodReportPolicy`, que
 * es la otra mitad (regla dura 18), dice lo mismo: `{admin, rrhh}`. Que las dos
 * comprobaciones coincidan es deliberado; si dijeran cosas distintas, una de las
 * dos estaria de adorno.
 *
 * EL ALCANCE POR DEPARTAMENTO ENTRA IGUALMENTE EN LA CONSULTA (RF-ID-03),
 * incluidos los agregados: un total por centro calculado sobre toda la plantilla
 * y servido a quien alcanza un solo departamento seria una fuga por agregacion.
 * Esta ahi para el dia en que el producto decida que un responsable ve las horas
 * de su equipo — ese dia se le añade el ambito y el rol, y la consulta ya esta
 * acotada.
 *
 * ES UN `GET` AUNQUE QUEDE AUDITADO. Solo lee; que sacar un informe con datos de
 * terceros deje asiento en `audit_log` (RS-05) no lo convierte en una escritura.
 * Mismo criterio que la exportacion legal.
 *
 * `throttle:management` POR LO QUE CUESTA Y POR LO QUE ESCRIBE. Cada peticion
 * cruza la plantilla con el calendario y deja ademas su asiento de divulgacion,
 * que toma el candado global de ADR-010 —el mismo por el que pasa cada fichaje—.
 * Sin techo por cuenta, una pestaña en bucle de reintento golpea la misma base de
 * datos que atiende el cambio de turno (RNF-P-02). El presupuesto de RNF-P-05 es
 * la otra mitad de esa defensa y vive en `config/reporting.php`.
 *
 * NO ADMITE `site_id` (ADR-040: hay un centro) NI PAGINACION: un informe se lee
 * entero o no significa nada, y paginarlo partiria una semana por la mitad. Lo
 * que si tiene es un techo, y por encima de el responde `422` remitiendo a la
 * generacion en diferido de RF-IN-06 (tarea 3.9).
 */
Route::get('/reports/period', PeriodReportController::class)
    ->middleware([
        'auth:sanctum',
        'ability:'.TokenAbility::REPORTS_ALL->value,
        'throttle:management',
    ])
    ->name('reporting.reports.period');

Route::get('/reports/legal-export', LegalExportController::class)
    ->middleware([
        'auth:sanctum',
        'ability:'.TokenAbility::REPORTS_LEGAL->value.','.TokenAbility::REPORTS_ALL->value,
    ])
    ->name('compliance.reports.legal-export');

/*
 * La bandeja de incidencias y su resolucion (RF-PA-05, tarea 2.5).
 *
 * `incidents:*` PARA LAS DOS, Y ES UN SOLO AMBITO A PROPOSITO. El §7.3 lo
 * describe como «consultar y resolver incidencias»: no hay un `incidents:read`, y
 * no lo hay porque separarlo daria una frontera que solo existiria en PHP y que
 * nadie podria conceder ni retirar desde la administracion. Lo que si son dos
 * cosas distintas son los dos metodos de `IncidentPolicy`, para que la matriz de
 * autorizacion negativa pruebe cada endpoint por separado (regla dura 18).
 *
 * Y NO `attendance:read`, aunque lo que se mira sean horas: leer el registro de
 * alguien y decidir que se hace con lo que esta pendiente son dos potestades del
 * §7.3, y el `auditor` es la prueba de que la distincion importa — lleva
 * `attendance:read` y no lleva este, asi que ni siquiera pasa del middleware.
 *
 * LA OTRA MITAD ES `IncidentPolicy`, que comprueba el ROL: «manager+» del Anexo
 * B, que desde la tarea 2.1 es `{admin, rrhh, responsable_departamento}`.
 *
 * EL ALCANCE POR DEPARTAMENTO SE APLICA DE DOS FORMAS (RF-ID-03): en el listado
 * entra **en la consulta** —incluido `meta.total`— y no devuelve `403`, porque un
 * listado acota en vez de denegar; al resolver, se comprueba el recurso ya
 * cargado y se responde `403` **con asiento** en `audit_log`, porque ahi si hay
 * un sujeto identificable al que apuntar.
 *
 * `throttle:management` POR LO QUE ESCRIBEN. Las dos rutas pasan por
 * `ScopeGuard` y cada denegacion por alcance escribe `access.denied` bajo el
 * candado global de ADR-010 —el mismo por el que pasa cada fichaje—; ademas el
 * listado deja su asiento de divulgacion (RS-05) en cada peticion. Sin techo por
 * cuenta, un bucle sobre identificadores ajenos mete escrituras ilimitadas en el
 * camino critico del cambio de turno. La zona se declara en
 * `IdentityServiceProvider`.
 *
 * NO HAY `PATCH` NI `DELETE` (regla dura 5): resolver es un `POST` con nombre
 * propio, igual que anular un tramo o revocar una credencial. La fila se queda en
 * la tabla con su nota y su firma, y no hay forma de reabrirla desde la API.
 *
 * `{id}` ES LA CLAVE INTERNA, y es la unica ruta del producto donde eso pasa. El
 * motivo esta escrito en el contrato: una incidencia no es una persona ni una
 * tarjeta, es una fila de trabajo interno que no viaja impresa ni se enseña a un
 * tercero, y su numero no revela nada sobre la plantilla.
 */
Route::middleware([
    'auth:sanctum',
    'ability:'.TokenAbility::INCIDENTS_ALL->value,
    'throttle:management',
])->group(function (): void {
    Route::get('/incidents', [IncidentController::class, 'index'])
        ->name('compliance.incidents.index');

    Route::post('/incidents/{id}/resolve', [IncidentController::class, 'resolve'])
        ->whereNumber('id')
        ->name('compliance.incidents.resolve');
});

/*
 * El resto de lo que necesita una tablet: padron y latido (tarea 1.7).
 *
 * TRES AMBITOS DISTINTOS PARA TRES COSAS DISTINTAS, y no un unico ambito
 * «kiosk» (§7.3). Es lo que hace que la promesa de RS-04 sea comprobable: un
 * token al que solo se le concedio `heartbeat:write` no puede descargarse el
 * padron, aunque lo porte el mismo dispositivo. Un ambito unico habria hecho de
 * los tres endpoints una sola llave.
 *
 * NINGUNO ACEPTA `site_id`, Y ESO ES LA MITAD DE LA AUTORIZACION. El centro sale
 * del token: un quiosco no puede ni siquiera formular la peticion «dame el padron
 * del otro hotel de la cadena». La otra mitad es `KioskPolicy`, que comprueba que
 * quien porta el token es un dispositivo y no una sesion de gestion (regla dura
 * 18).
 */
Route::prefix('kiosk')->middleware(['auth:sanctum', 'throttle:kiosk'])->group(function (): void {
    Route::get('/roster', RosterController::class)
        ->middleware('ability:'.TokenAbility::ROSTER_READ->value)
        ->name('kiosk.roster');

    Route::post('/heartbeat', HeartbeatController::class)
        ->middleware('ability:'.TokenAbility::HEARTBEAT_WRITE->value)
        ->name('kiosk.heartbeat');

    /*
     * POST /api/v1/kiosk/pair y /pair/confirm NO existen todavia: el
     * emparejamiento por codigo es RF-PD-06, tarea 5.6. Se anota aqui para que su
     * ausencia sea una decision visible y no un olvido; hasta entonces los tokens
     * de dispositivo se emiten por consola (tarea 1.5).
     */
});

Route::prefix('auth')->group(function (): void {
    // Publico y limitado a 5 r/m (§7.1). El bloqueo por intentos fallidos es
    // otro control distinto y vive en el caso de uso: este cuenta peticiones,
    // aquel cuenta fallos por cuenta.
    Route::post('/login', LoginController::class)
        ->middleware('throttle:auth')
        ->name('auth.login');

    /*
     * Segundo factor obligatorio (RF-ID-01 completo, RS-06, tarea 2.1).
     *
     * LOS TRES EXIGEN `2fa:pending` Y NADA MAS, que es el unico ambito que lleva
     * el token del `202` de `/login`. Con eso, la sesion pendiente no alcanza
     * ningun otro endpoint del producto: no es una sesion, es el permiso para
     * presentar un codigo.
     *
     * LA OTRA MITAD ES `TwoFactorPolicy`, que comprueba QUIEN porta el token
     * (regla dura 18): un token de quiosco o una sesion de portal a los que
     * alguien añadiera este ambito seguirian sin poder canjearlos por una sesion
     * de gestion. El empleado no tiene segundo factor y no puede tenerlo (reglas
     * duras 11 y 12).
     *
     * ZONA `2fa` PROPIA, Y NO LA `auth` DEL ACCESO. Aquella toma la cuenta del
     * `email` del cuerpo, y aqui no hay ninguno: `TwoFactorCodeRequest` solo
     * admite `code` y el alta no admite nada. Con la zona `auth`, la clave por
     * cuenta se componia siempre con la cadena vacia y los cinco intentos por
     * minuto los compartia LA INSTALACION ENTERA — cualquiera con un reto abierto
     * dejaba a los demas sin poder completar su acceso. La zona `2fa` toma el
     * sujeto del DUEÑO DEL TOKEN PENDIENTE, que es lo unico que el cliente no
     * puede falsificar.
     *
     * EL ORDEN DE LOS MIDDLEWARES IMPORTA, igual que en `/scan`: `throttle` va
     * DESPUES de `auth:sanctum` porque sin actor resuelto no hay cuenta a la que
     * contar la peticion.
     *
     * TRES CONTROLES Y NINGUNO SUSTITUYE A LOS OTROS: Nginx limita todo
     * `^~ /api/v1/auth/` a 5 r/m en el borde (§7.1, tarea 1.7), `throttle:2fa`
     * limita por cuenta y por IP, y el bloqueo por intentos de codigo vive en el
     * caso de uso con contador propio distinto del de la contrasena.
     *
     * `enrol` Y `confirm` NO ESTAN EN EL ANEXO B, y su ausencia era un hueco: sin
     * ellos, una cuenta nueva de `rrhh` no tiene forma de obtener su segundo
     * factor y por tanto ninguna forma de entrar. La alternativa era repartir
     * secretos por consola, que obliga al cliente a usar SSH para dar de alta a
     * una persona. Se describen en el contrato antes que aqui (ADR-013).
     */
    Route::prefix('2fa')
        ->middleware([
            'auth:sanctum',
            'ability:'.TokenAbility::TWO_FACTOR_PENDING->value,
            'throttle:2fa',
        ])
        ->group(function (): void {
            Route::post('/verify', [TwoFactorController::class, 'verify'])
                ->name('auth.2fa.verify');

            Route::post('/enrol', [TwoFactorController::class, 'enrol'])
                ->name('auth.2fa.enrol');

            Route::post('/confirm', [TwoFactorController::class, 'confirm'])
                ->name('auth.2fa.confirm');
        });

    /*
     * El cierre de sesion SI acepta un token pendiente, a proposito: abandonar un
     * acceso a medias es lo que hace el panel cuando alguien cancela la pantalla
     * del codigo, y negarselo dejaria el reto vivo hasta que caduque.
     */
    Route::post('/logout', LogoutController::class)
        ->middleware('auth:sanctum')
        ->name('auth.logout');

    /*
     * GET /api/v1/auth/me — EL UNICO ENDPOINT QUE NO PUEDE EXIGIR UN AMBITO.
     *
     * Lo llaman los cuatro roles de gestion y cada uno lleva los suyos, asi que
     * aqui no hay ninguna lista que poner en `ability`. Sin `session.complete`,
     * este seria el unico endpoint alcanzable con media autenticacion, y lo que
     * adelantaria —rol y alcance por departamento— es justo lo que ayuda a decidir
     * a que cuenta merece la pena seguir atacando (RS-06).
     */
    Route::get('/me', CurrentUserController::class)
        ->middleware(['auth:sanctum', 'session.complete'])
        ->name('auth.me');
});

/*
 * Portal del empleado (RF-ID-05..08, RL-05, art. 34.9 ET, tarea 1.11).
 *
 * EXISTE POR OBLIGACION LEGAL. La persona trabajadora tiene que poder acceder a
 * su propio registro de jornada y llevarselo. Si estas tres rutas no funcionan,
 * el cliente incumple.
 *
 * NINGUNA LLEVA `{uuid}`, Y ESA AUSENCIA ES LA MITAD DE LA AUTORIZACION
 * (RF-ID-07, regla dura 18). El empleado se resuelve del token, que es lo unico
 * que el cliente no puede falsificar: sin identificador en la ruta, no hay URL
 * que manipular para llegar al registro de otra persona. La otra mitad son el
 * ambito `self:read` —que verifica el middleware `ability`— y `SelfJournalPolicy`,
 * que comprueba que quien porta el token es una sesion de portal y no una cuenta
 * de gestion. Con las tres, un token de panel recibe 403 aunque su portador sea
 * administrador: el registro de otro se consulta por
 * `GET /employees/{uuid}/workdays`, que queda auditado como divulgacion (RS-05).
 *
 * `self:read` Y NADA MAS. No hay ninguna ruta por la que un empleado pueda
 * cambiar su propio registro horario: rectificarlo es `PATCH
 * /shift-entries/{uuid}`, con ambito `attendance:correct` y una cuenta de
 * gestion, y deja autor y motivo (RN-13). El ambito de lectura del panel
 * —`attendance:read`— tampoco alcanza estas rutas.
 *
 * ZONA DE LIMITE PROPIA, `portal`, MAS ESTRECHA QUE LA DE AUTENTICACION (§7.1:
 * 10 r/m frente a 5 r/m del panel... y por eso mismo mas estrecha en lo que
 * importa). Aqui no se frena a quien prueba contraseñas: se frena fuerza bruta
 * sobre un espacio de 10^6 (RS-12). Se aplica por IP y por codigo de empleado a
 * la vez, y se declara en `IdentityServiceProvider`.
 *
 * TRES CONTROLES DISTINTOS Y NINGUNO SUSTITUYE A LOS OTROS, igual que en
 * `/scan/pin`: Nginx limita por origen (§7.2), `throttle:portal` limita por IP y
 * por codigo, y el bloqueo escalonado del §7.5 cuenta FALLOS POR EMPLEADO Y POR
 * PUERTA. El contador del portal es distinto del del quiosco, para que sondear
 * uno no deje a nadie sin poder fichar por el otro (regla dura 19).
 *
 * LA CADUCIDAD DE LA LICENCIA NO TOCA ESTAS RUTAS (ADR-019, regla dura 15). El
 * portal es registro legal: se degradan funcionalidades accesorias, nunca esto.
 */
Route::post('/me/login', PortalLoginController::class)
    ->middleware('throttle:portal')
    ->name('portal.login');

Route::middleware([
    'auth:sanctum',
    'ability:'.TokenAbility::SELF_READ->value,
    'throttle:portal',
])->group(function (): void {
    Route::get('/me/workdays', MyWorkDayController::class)->name('portal.workdays');

    /*
     * La descarga del historico propio. `GET` y no `POST` porque solo lee: que
     * devuelva un fichero no lo convierte en una escritura, y un `POST`
     * impediria que el portal ofreciera la descarga como un enlace.
     *
     * `?format=csv` es el unico valor de esta fase; el PDF llega en la tarea 2.9
     * como un valor mas del mismo enumerado (ADR-012).
     */
    Route::get('/me/export', MyWorkDayExportController::class)->name('portal.export');

    /*
     * POST /api/v1/me/logout NO existe, y no es un olvido. El Anexo B del doc 01
     * lista tres rutas de portal y solo tres. La sesion es corta y el cliente
     * olvida el token, que es lo mismo que hace hoy el panel cuando caduca.
     *
     * Y para el caso que de verdad importa —un movil perdido— ya hay un camino
     * que ademas cierra las dos puertas a la vez: que RRHH restablezca el PIN
     * (RF-ID-09). `IdentityServiceProvider` invalida toda sesion de portal
     * anterior al ultimo `pin_issued_at`, asi que el restablecimiento tiene
     * efecto en la peticion siguiente y no cuando caduque el token.
     */
});

/*
 * LECTURA DE PLANTILLA: `employees:read`, no `employees:*` (RF-ID-03, tarea 2.1).
 *
 * La familia se parte en dos porque el Anexo B del doc 01 dice que `GET
 * /employees` es «manager+» —lo que incluye al `responsable_departamento`— y el
 * §7.3 no le daba ningun ambito de plantilla. Con un unico ambito de familia,
 * dejarle leer era dejarle escribir, y la unica defensa quedaba en la policy.
 *
 * `admin` y `rrhh` llevan los dos ambitos, asi que no pierden nada.
 *
 * EL ALCANCE POR DEPARTAMENTO NO SE COMPRUEBA AQUI: se aplica **dentro de la
 * consulta** (`EmployeeQueries`) para el listado, y con la policy contra la ficha
 * cargada para el detalle. Un filtro posterior daria un `meta.total` que describe
 * a personas que quien pregunta no puede ver.
 *
 * `throttle:management` POR LA FICHA: es la que pasa por `ScopeGuard` y por tanto
 * la que puede escribir `access.denied` en `audit_log` bajo el candado global de
 * ADR-010. Se aplica al grupo entero y no solo a `show` porque el listado sale de
 * la misma pantalla y con el mismo token: un techo que solo cubriera una de las
 * dos seria un techo que se rodea cambiando de URL.
 */
Route::middleware([
    'auth:sanctum',
    'ability:'.TokenAbility::EMPLOYEES_READ->value,
    'throttle:management',
])->group(function (): void {
    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('/employees/{uuid}', [EmployeeController::class, 'show'])
        ->whereUuid('uuid')
        ->name('employees.show');
});

Route::middleware(['auth:sanctum', 'ability:'.TokenAbility::EMPLOYEES_ALL->value])->group(function (): void {
    Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::patch('/employees/{uuid}', [EmployeeController::class, 'update'])
        ->whereUuid('uuid')
        ->name('employees.update');

    // Baja logica, nunca borrado (regla dura 5, RF-GP-03). Por eso es un POST
    // con nombre propio y no un DELETE: no hay ningun DELETE en esta API.
    Route::post('/employees/{uuid}/offboard', OffboardEmployeeController::class)
        ->whereUuid('uuid')
        ->name('employees.offboard');

    /*
     * PIN del empleado (tarea 1.13, RF-ID-09).
     *
     * Ambito `employees:*` y no uno propio: provisionar el PIN es parte de dar
     * de alta y mantener a una persona, igual que la ficha. La policy comprueba
     * ademas el rol —RRHH, Anexo B del doc 01— y es la mitad que impide que un
     * token con ambito de plantilla pero sin rol llegue a restablecer nada.
     *
     * Los dos son POST con nombre propio, como el resto de este fichero: el
     * primero genera una credencial y el segundo afirma que se entrego en mano.
     * Ninguno de los dos es un `PATCH` de la ficha, porque ninguno es un cambio
     * de datos: son dos hechos con asiento propio en `audit_log`.
     */
    Route::post('/employees/{uuid}/pin/reset', [EmployeePinController::class, 'reset'])
        ->whereUuid('uuid')
        ->name('employees.pin.reset');

    Route::post('/employees/{uuid}/pin/deliver', [EmployeePinController::class, 'deliver'])
        ->whereUuid('uuid')
        ->name('employees.pin.deliver');

    /*
     * Contratos historizados (RF-GP-02, tarea 2.8).
     *
     * AMBITO `employees:*` Y NO `reports:*`, aunque lo que se registra aqui sea
     * lo que despues compara el informe de RF-IN-03. El contrato es una
     * condicion laboral de la ficha de una persona —horas, tipo de jornada,
     * computo anual—, no un informe: quien lo mantiene es quien mantiene la
     * plantilla. Con `reports:*`, alguien que solo puede mirar cifras podria
     * cambiar la cifra contra la que se miden.
     *
     * Y NO `employees:read` PARA EL LISTADO, aunque el grupo de lectura exista
     * dos bloques mas arriba. El unico que hoy puede leer contratos es
     * `rrhh+`, que lleva la familia entera: meterlo en el grupo estrecho se lo
     * habria abierto al `responsable_departamento`, y las condiciones laborales
     * pactadas no son suyas. `EmploymentContractPolicy` dice lo mismo (regla
     * dura 18). El dia que el producto decida que un responsable las consulta,
     * la ruta baja al otro grupo y la policy gana un rol.
     *
     * NO HAY `PATCH` NI `DELETE` (regla dura 5): un contrato no se edita. Se
     * registra otro y el anterior queda cerrado con su fecha, en la misma
     * transaccion. Corregir una errata todavia no tiene camino, y esta anotado
     * como deuda en el controlador.
     */
    Route::get('/employees/{uuid}/contracts', [EmploymentContractController::class, 'index'])
        ->whereUuid('uuid')
        ->name('employees.contracts.index');

    Route::post('/employees/{uuid}/contracts', [EmploymentContractController::class, 'store'])
        ->whereUuid('uuid')
        ->name('employees.contracts.store');

    Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
    Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
    Route::get('/departments/{id}', [DepartmentController::class, 'show'])
        ->whereNumber('id')
        ->name('departments.show');
    Route::patch('/departments/{id}', [DepartmentController::class, 'update'])
        ->whereNumber('id')
        ->name('departments.update');

    // El centro de trabajo es un recurso singular: hay exactamente uno por
    // instalacion (ADR-040). Sin alta —la hace la puesta en marcha— ni baja.
    Route::get('/site', [SiteController::class, 'show'])->name('site.show');
    Route::patch('/site', [SiteController::class, 'update'])->name('site.update');
});

/*
 * Credenciales QR (tareas 1.5 y 1.10, RF-QR-01..06 y RF-QR-08).
 *
 * Ambito propio, `credentials:*`, y no `employees:*`: emitir una tarjeta y
 * gestionar la plantilla son dos potestades distintas del §7.3, y compartir
 * ambito significaria que quien puede corregir un apellido puede tambien emitir
 * una tarjeta a nombre de cualquiera. La policy comprueba ademas el rol.
 *
 * No hay `DELETE`: revocar es un POST con nombre propio porque la credencial no
 * se borra, se marca revocada y conserva su historia (regla dura 5).
 *
 * LOS CUATRO ENDPOINTS DE LA 1.10 SON `POST` AUNQUE DOS DEVUELVAN UN DOCUMENTO,
 * y no es una cuestion de gusto: imprimir ACUÑA el QR (ADR-034) y es
 * irreversible. Un `GET` invitaria a que un navegador lo repitiera al recargar, a
 * que un proxy lo cachease y a que alguien lo pusiera en un enlace — y cada una
 * de las tres cosas produce una tarjeta muerta en el bolsillo de alguien. El
 * unico `GET` del grupo es el panel, que solo lee.
 */
Route::middleware(['auth:sanctum', 'ability:'.TokenAbility::CREDENTIALS_ALL->value])->group(function (): void {
    Route::post('/credentials', [CredentialController::class, 'store'])->name('credentials.store');

    /*
     * Las dos rutas de segmento fijo van ANTES que las de `{uuid}` a proposito.
     * Hoy no colisionan —`whereUuid` acota el parametro y ademas las de abajo
     * tienen un segmento mas—, pero el orden deja la intencion escrita: `status`
     * y `print-batch` son recursos propios, no credenciales con nombre raro.
     */
    Route::get('/credentials/status', CredentialStatusController::class)
        ->name('credentials.status');

    Route::post('/credentials/print-batch', PrintCredentialBatchController::class)
        ->name('credentials.print-batch');

    Route::post('/credentials/{uuid}/print', PrintCredentialController::class)
        ->whereUuid('uuid')
        ->name('credentials.print');

    Route::post('/credentials/{uuid}/deliver', DeliverCredentialController::class)
        ->whereUuid('uuid')
        ->name('credentials.deliver');

    Route::post('/credentials/{uuid}/revoke', RevokeCredentialController::class)
        ->whereUuid('uuid')
        ->name('credentials.revoke');
});
