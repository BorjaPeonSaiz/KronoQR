<?php

declare(strict_types=1);

namespace App\Modules\Workforce;

use App\Modules\Attendance\Application\Port\EmployeeDirectory;
use App\Modules\Attendance\Application\Port\SiteCalendar;
use App\Modules\Shared\Application\Port\ClockingEmployees;
use App\Modules\Shared\Application\Port\EmployeeCardDirectory;
use App\Modules\Shared\Application\Port\EmployeePinVerifier;
use App\Modules\Shared\Application\Port\EmployeeRegistry;
use App\Modules\Shared\Application\Port\EmployeeScopeDirectory;
use App\Modules\Shared\Application\Port\InstallationSiteProvider;
use App\Modules\Shared\Application\Port\PortalSessionIssuer;
use App\Modules\Workforce\Application\Port\DepartmentRepository;
use App\Modules\Workforce\Application\Port\EmployeeImportDirectory;
use App\Modules\Workforce\Application\Port\EmployeeImportSource;
use App\Modules\Workforce\Application\Port\EmployeePinRepository;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\EmploymentContractRepository;
use App\Modules\Workforce\Application\Port\PinHasher;
use App\Modules\Workforce\Application\Port\PinMetrics;
use App\Modules\Workforce\Application\Port\PinPolicyProvider;
use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Model\Department;
use App\Modules\Workforce\Domain\Model\Employee;
use App\Modules\Workforce\Domain\Model\EmploymentContract;
use App\Modules\Workforce\Domain\Model\Site;
use App\Modules\Workforce\Http\Policy\DepartmentPolicy;
use App\Modules\Workforce\Http\Policy\EmployeePolicy;
use App\Modules\Workforce\Http\Policy\EmploymentContractPolicy;
use App\Modules\Workforce\Http\Policy\SitePolicy;
use App\Modules\Workforce\Infrastructure\Adapter\ConfiguredPinPolicyProvider;
use App\Modules\Workforce\Infrastructure\Adapter\EloquentClockingEmployees;
use App\Modules\Workforce\Infrastructure\Adapter\EloquentEmployeeCardDirectory;
use App\Modules\Workforce\Infrastructure\Adapter\EloquentEmployeeDirectory;
use App\Modules\Workforce\Infrastructure\Adapter\EloquentEmployeeRegistry;
use App\Modules\Workforce\Infrastructure\Adapter\EloquentEmployeeScopeDirectory;
use App\Modules\Workforce\Infrastructure\Adapter\EloquentInstallationSiteProvider;
use App\Modules\Workforce\Infrastructure\Adapter\EloquentSiteCalendar;
use App\Modules\Workforce\Infrastructure\Adapter\HashedEmployeePinVerifier;
use App\Modules\Workforce\Infrastructure\Adapter\LaravelPinHasher;
use App\Modules\Workforce\Infrastructure\Adapter\LaravelWorkforceEventPublisher;
use App\Modules\Workforce\Infrastructure\Adapter\SanctumPortalSessionIssuer;
use App\Modules\Workforce\Infrastructure\Adapter\SimpleExcelImportSource;
use App\Modules\Workforce\Infrastructure\Metrics\RedisPinMetrics;
use App\Modules\Workforce\Infrastructure\Persistence\EloquentDepartmentRepository;
use App\Modules\Workforce\Infrastructure\Persistence\EloquentEmployeeImportDirectory;
use App\Modules\Workforce\Infrastructure\Persistence\EloquentEmployeePinRepository;
use App\Modules\Workforce\Infrastructure\Persistence\EloquentEmployeeRepository;
use App\Modules\Workforce\Infrastructure\Persistence\EloquentEmploymentContractRepository;
use App\Modules\Workforce\Infrastructure\Persistence\EloquentSiteRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Modulo Workforce — empleados, departamentos, centros, contratos y ausencias
 * (doc 02 §1.6). Depende de Shared y de Attendance/Application/Port, cuyos
 * puertos implementa.
 *
 * Aqui esta la raiz de composicion del modulo, y en ella las dos aristas de
 * ADR-025: los adaptadores que sirven a puertos del **nucleo** viven en este
 * modulo —que es donde estan `employees` y `sites`— y se declaran en este
 * proveedor, no en el de `Attendance`. `Attendance` no sabe quien le resuelve la
 * plantilla ni la zona horaria del centro.
 *
 * **Las policies se registran contra los modelos de DOMINIO**, no contra los
 * modelos Eloquent. Es deliberado: si la autorizacion se declarara sobre la fila
 * de la base de datos, un controlador tendria que cargar la fila para poder
 * preguntar si puede verla, y esa es la via por la que la autorizacion acaba
 * ocurriendo despues del acceso a los datos.
 */
final class WorkforceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EmployeeRepository::class, EloquentEmployeeRepository::class);
        $this->app->bind(SiteRepository::class, EloquentSiteRepository::class);
        $this->app->bind(DepartmentRepository::class, EloquentDepartmentRepository::class);

        /*
         * Importacion masiva de plantilla (tarea 5.5, RF-GP-05).
         *
         * El lector va sobre `spatie/simple-excel` (doc 02 §3.1) y **no es
         * `singleton`**: guarda si la ultima lectura se trunco, y compartirlo
         * entre dos importaciones de la misma peticion —el planificador lee una
         * vez y la aplicacion vuelve a leer— arrastraria el estado de la
         * primera.
         *
         * El directorio consulta `employees` y `departments` con el constructor
         * de consultas: compara el documento **hasheado en la propia sentencia**
         * (`digest(?, 'sha256')`, RL-08), que es la misma expresion con la que el
         * alta lo escribe. Si las dos se separaran, la busqueda no encontraria a
         * nadie y cada reimportacion crearia la plantilla de nuevo.
         */
        $this->app->bind(EmployeeImportSource::class, SimpleExcelImportSource::class);

        $this->app->bind(
            EmployeeImportDirectory::class,
            static fn (): EloquentEmployeeImportDirectory => new EloquentEmployeeImportDirectory(DB::connection()),
        );
        $this->app->bind(WorkforceEventPublisher::class, LaravelWorkforceEventPublisher::class);

        // Contratos historizados (RF-GP-02, tarea 2.8). Es lo que permite al
        // informe de RF-IN-03 comparar cada dia contra lo que estaba pactado
        // **ese** dia; sin el, no hay «horas contratadas» que comparar.
        $this->app->bind(EmploymentContractRepository::class, EloquentEmploymentContractRepository::class);

        // PIN del empleado (RF-ID-09). El repositorio es el unico que escribe
        // `pin_hash`, y ninguno de sus metodos lo devuelve; la politica de PIN
        // llega de la configuracion de la instalacion (regla dura 13).
        $this->app->bind(EmployeePinRepository::class, EloquentEmployeePinRepository::class);
        $this->app->bind(PinPolicyProvider::class, ConfiguredPinPolicyProvider::class);
        $this->app->bind(PinMetrics::class, RedisPinMetrics::class);

        /*
         * El hasher del PIN, detras de un puerto (revision de la 5.5).
         *
         * Existe para que el CALCULO pueda ocurrir donde decide el caso de uso y
         * no donde ocurre la escritura: la importacion masiva lo hace fuera de su
         * transaccion, porque bcrypt cuesta unos 160 ms por PIN y 500 de ellos
         * dentro monopolizaban el candado global de `audit_log` —y con el, los
         * fichajes del hotel— durante minuto y medio.
         */
        $this->app->bind(PinHasher::class, LaravelPinHasher::class);

        // Y el que COMPRUEBA ese mismo PIN (RF-AT-11, RF-ID-06, RS-12). El
        // puerto lo declara `Shared` porque lo necesitan dos satelites que no
        // pueden verse entre si —el fichaje de respaldo del quiosco y el portal
        // del empleado— y lo implementa este modulo, que es donde esta
        // `employees.pin_hash`. Deliberadamente separado de
        // `EmployeePinRepository`: aquel EMITE y ninguno de sus metodos lee el
        // hash, este lo COMPRUEBA y no puede escribirlo. Un solo puerto habria
        // dado a quien provisiona la capacidad de verificar y al reves.
        $this->app->bind(EmployeePinVerifier::class, HashedEmployeePinVerifier::class);

        // Y la SESION que se abre cuando ese PIN acierta en el portal (RF-ID-05,
        // RF-ID-07, RL-05, tarea 1.11). Mismo reparto que el verificador y por
        // la misma razon: quien decide si se abre sesion es `Identity`, y quien
        // puede acuñar un token colgado de una persona es este modulo, porque el
        // `tokenable` es la fila de `employees`. Que el token cuelgue del
        // empleado y no de una cuenta de gestion es lo que evita una cuenta
        // espejo por persona —con correo obligatorio (regla dura 12), politica
        // de contraseña y el 2FA de la tarea 2.1— para algo cuya credencial es
        // un PIN de seis digitos.
        $this->app->bind(PortalSessionIssuer::class, SanctumPortalSessionIssuer::class);

        // ADR-025: los puertos los declara quien los necesita —el nucleo— y los
        // implementa quien tiene el dato.
        $this->app->bind(EmployeeDirectory::class, EloquentEmployeeDirectory::class);
        $this->app->bind(SiteCalendar::class, EloquentSiteCalendar::class);
        // El centro de la instalacion para quien no es este modulo (ADR-040):
        // `Identity` etiqueta con el sus metricas de cobertura. Misma arista
        // que `SiteCalendar`, sobre la misma tabla.
        $this->app->bind(InstallationSiteProvider::class, EloquentInstallationSiteProvider::class);

        // Y el puerto de `Shared` que traduce entre el UUID publico de un
        // empleado y su clave interna. Lo declara `Shared` porque lo consume
        // `Identity` —que posee `credentials`, cuya clave ajena apunta aqui— y
        // ningun satelite puede importar nada de otro (§1.6). Lo implementa este
        // modulo por la misma razon que los dos de arriba: es donde esta la
        // tabla.
        $this->app->bind(EmployeeRegistry::class, EloquentEmployeeRegistry::class);

        // Y el departamento de una persona, que es lo que `Reporting` y
        // `Attendance` necesitan para decidir si queda dentro del alcance de quien
        // pregunta (RF-ID-03). Puerto propio y no un metodo mas del anterior:
        // aquel se invoca en el camino de fichaje y dice de si mismo que traduce
        // identificadores «y nada mas».
        $this->app->bind(EmployeeScopeDirectory::class, EloquentEmployeeScopeDirectory::class);

        // Y el padron minimo que consume el quiosco (RF-KI-03, tarea 1.7). Mismo
        // patron y misma razon: lo declara `Shared` porque lo necesita `Kiosk`,
        // que es otro satelite, y lo implementa este modulo, que es donde estan
        // los nombres y la situacion laboral que decide quien aparece (RN-14).
        $this->app->bind(ClockingEmployees::class, EloquentClockingEmployees::class);

        // Y el nombre, el departamento y el centro que se imprimen en una tarjeta
        // (RF-QR-04), y la plantilla de alta que cuenta el panel de RF-QR-08.
        // Mismo patron y misma razon, y deliberadamente separado de
        // `EmployeeDirectory` y de `ClockingEmployees`: los dos primeros sirven al
        // camino de fichaje con la forma minima del nombre (§7.3), y este sirve a
        // la tarjeta que se entrega en mano y al panel de RRHH, donde hace falta
        // el nombre completo. Un solo puerto habria metido el apellido entero en
        // el padron cacheado de una tablet colgada de una pared.
        $this->app->bind(EmployeeCardDirectory::class, EloquentEmployeeCardDirectory::class);
    }

    public function boot(): void
    {
        Gate::policy(Employee::class, EmployeePolicy::class);
        Gate::policy(Site::class, SitePolicy::class);
        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(EmploymentContract::class, EmploymentContractPolicy::class);
    }
}
