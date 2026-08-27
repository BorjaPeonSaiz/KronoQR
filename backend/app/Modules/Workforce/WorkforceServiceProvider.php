<?php

declare(strict_types=1);

namespace App\Modules\Workforce;

use App\Modules\Attendance\Application\Port\EmployeeDirectory;
use App\Modules\Attendance\Application\Port\SiteCalendar;
use App\Modules\Shared\Application\Port\ClockingEmployees;
use App\Modules\Shared\Application\Port\EmployeeCardDirectory;
use App\Modules\Shared\Application\Port\EmployeeRegistry;
use App\Modules\Workforce\Application\Port\DepartmentRepository;
use App\Modules\Workforce\Application\Port\EmployeePinRepository;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\PinMetrics;
use App\Modules\Workforce\Application\Port\PinPolicyProvider;
use App\Modules\Workforce\Application\Port\SiteRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Model\Department;
use App\Modules\Workforce\Domain\Model\Employee;
use App\Modules\Workforce\Domain\Model\Site;
use App\Modules\Workforce\Http\Policy\DepartmentPolicy;
use App\Modules\Workforce\Http\Policy\EmployeePolicy;
use App\Modules\Workforce\Http\Policy\SitePolicy;
use App\Modules\Workforce\Infrastructure\Adapter\ConfiguredPinPolicyProvider;
use App\Modules\Workforce\Infrastructure\Adapter\EloquentClockingEmployees;
use App\Modules\Workforce\Infrastructure\Adapter\EloquentEmployeeCardDirectory;
use App\Modules\Workforce\Infrastructure\Adapter\EloquentEmployeeDirectory;
use App\Modules\Workforce\Infrastructure\Adapter\EloquentEmployeeRegistry;
use App\Modules\Workforce\Infrastructure\Adapter\EloquentSiteCalendar;
use App\Modules\Workforce\Infrastructure\Adapter\LaravelWorkforceEventPublisher;
use App\Modules\Workforce\Infrastructure\Metrics\RedisPinMetrics;
use App\Modules\Workforce\Infrastructure\Persistence\EloquentDepartmentRepository;
use App\Modules\Workforce\Infrastructure\Persistence\EloquentEmployeePinRepository;
use App\Modules\Workforce\Infrastructure\Persistence\EloquentEmployeeRepository;
use App\Modules\Workforce\Infrastructure\Persistence\EloquentSiteRepository;
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
        $this->app->bind(WorkforceEventPublisher::class, LaravelWorkforceEventPublisher::class);

        // PIN del empleado (RF-ID-09). El repositorio es el unico que escribe
        // `pin_hash`, y ninguno de sus metodos lo devuelve; la politica de PIN
        // llega de la configuracion de la instalacion (regla dura 13).
        $this->app->bind(EmployeePinRepository::class, EloquentEmployeePinRepository::class);
        $this->app->bind(PinPolicyProvider::class, ConfiguredPinPolicyProvider::class);
        $this->app->bind(PinMetrics::class, RedisPinMetrics::class);

        // ADR-025: los puertos los declara quien los necesita —el nucleo— y los
        // implementa quien tiene el dato.
        $this->app->bind(EmployeeDirectory::class, EloquentEmployeeDirectory::class);
        $this->app->bind(SiteCalendar::class, EloquentSiteCalendar::class);

        // Y el puerto de `Shared` que traduce entre el UUID publico de un
        // empleado y su clave interna. Lo declara `Shared` porque lo consume
        // `Identity` —que posee `credentials`, cuya clave ajena apunta aqui— y
        // ningun satelite puede importar nada de otro (§1.6). Lo implementa este
        // modulo por la misma razon que los dos de arriba: es donde esta la
        // tabla.
        $this->app->bind(EmployeeRegistry::class, EloquentEmployeeRegistry::class);

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
    }
}
