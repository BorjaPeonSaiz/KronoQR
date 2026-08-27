<?php

declare(strict_types=1);

use App\Modules\Attendance\Http\Controller\ScanBatchController;
use App\Modules\Attendance\Http\Controller\ScanController;
use App\Modules\Attendance\Http\Controller\ShiftEntryController;
use App\Modules\Attendance\Http\Controller\VoidShiftEntryController;
use App\Modules\Compliance\Http\Controller\LegalExportController;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Identity\Http\Controller\CredentialController;
use App\Modules\Identity\Http\Controller\CredentialStatusController;
use App\Modules\Identity\Http\Controller\CurrentUserController;
use App\Modules\Identity\Http\Controller\DeliverCredentialController;
use App\Modules\Identity\Http\Controller\LoginController;
use App\Modules\Identity\Http\Controller\LogoutController;
use App\Modules\Identity\Http\Controller\PrintCredentialBatchController;
use App\Modules\Identity\Http\Controller\PrintCredentialController;
use App\Modules\Identity\Http\Controller\RevokeCredentialController;
use App\Modules\Kiosk\Http\Controller\HeartbeatController;
use App\Modules\Kiosk\Http\Controller\RosterController;
use App\Modules\Reporting\Http\Controller\EmployeeWorkDayController;
use App\Modules\Workforce\Http\Controller\DepartmentController;
use App\Modules\Workforce\Http\Controller\EmployeeController;
use App\Modules\Workforce\Http\Controller\EmployeePinController;
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
 * Todavia sin implementar, cada uno con su tarea:
 *
 *   GET  /api/v1/health   sonda de salud (doc 01 Anexo B)  -> tarea 1.7
 *   GET  /api/v1/ready    sonda de arranque                -> tarea 1.7
 *
 * DOS COMPROBACIONES POR RUTA, NO UNA (doc 02 §7.3, regla dura 18). El
 * middleware `ability` verifica el AMBITO del token y la policy del recurso
 * verifica SOBRE QUE DATOS. Con las dos, un token de quiosco robado no alcanza
 * estos endpoints aunque su portador tuviera rol, y un rol sin ambito tampoco.
 * La policy se declara en el FormRequest o en el controlador de cada endpoint;
 * aqui esta la mitad del ambito.
 */

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
 */
Route::middleware(['auth:sanctum', 'ability:'.TokenAbility::ATTENDANCE_CORRECT->value])->group(function (): void {
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
    ->middleware(['auth:sanctum', 'ability:'.TokenAbility::ATTENDANCE_READ->value])
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
Route::get('/reports/legal-export', LegalExportController::class)
    ->middleware([
        'auth:sanctum',
        'ability:'.TokenAbility::REPORTS_LEGAL->value.','.TokenAbility::REPORTS_ALL->value,
    ])
    ->name('compliance.reports.legal-export');

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

    Route::post('/logout', LogoutController::class)
        ->middleware('auth:sanctum')
        ->name('auth.logout');

    Route::get('/me', CurrentUserController::class)
        ->middleware('auth:sanctum')
        ->name('auth.me');

    /*
     * POST /api/v1/auth/2fa/verify NO existe todavia: el 2FA obligatorio es de
     * la tarea 2.1 (Anexo A del doc 01). Se anota aqui para que su ausencia sea
     * una decision visible y no un olvido.
     */
});

Route::middleware(['auth:sanctum', 'ability:'.TokenAbility::EMPLOYEES_ALL->value])->group(function (): void {
    Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
    Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::get('/employees/{uuid}', [EmployeeController::class, 'show'])
        ->whereUuid('uuid')
        ->name('employees.show');
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

    Route::get('/departments', [DepartmentController::class, 'index'])->name('departments.index');
    Route::post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
    Route::get('/departments/{id}', [DepartmentController::class, 'show'])
        ->whereNumber('id')
        ->name('departments.show');
    Route::patch('/departments/{id}', [DepartmentController::class, 'update'])
        ->whereNumber('id')
        ->name('departments.update');

    Route::get('/sites', [SiteController::class, 'index'])->name('sites.index');
    Route::post('/sites', [SiteController::class, 'store'])->name('sites.store');
    Route::get('/sites/{id}', [SiteController::class, 'show'])
        ->whereNumber('id')
        ->name('sites.show');
    Route::patch('/sites/{id}', [SiteController::class, 'update'])
        ->whereNumber('id')
        ->name('sites.update');
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
