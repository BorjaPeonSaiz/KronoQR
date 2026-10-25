<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Queue;

use App\Modules\Attendance\Domain\Event\ShiftEntryCorrected;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryAnomaly;
use App\Modules\Attendance\Domain\ValueObject\ShiftEntryTimes;

/**
 * Los nombres de antes de la 2.3.0 de las clases que viajan serializadas en la
 * cola, para que un trabajo encolado por la version anterior se pueda leer
 * despues de actualizar.
 *
 * ## Por que hace falta
 *
 * La 2.3.0 renombra el tramo segun el glosario (`Shift*` → `ShiftEntry*`).
 * Dos oyentes son `ShouldQueue` en la conexion `resilient`
 * (`RecordWorkedMinutes` y `BroadcastPresenceChange`), y la cola guarda el
 * evento con `serialize()`: el nombre de la clase va dentro del trabajo. Un
 * `EmployeeClockedOut` encolado antes de actualizar lleva casos de
 * `ShiftAnomaly`, y un `ShiftCorrected` lleva `ShiftTimes`; sin el nombre
 * viejo, `unserialize()` devuelve `__PHP_Incomplete_Class` y el trabajo falla.
 *
 * ## Cuales y cuales no
 *
 * Solo lo que puede estar dentro de un trabajo en cola. Los dos oyentes reciben
 * `EmployeeClockedIn`, `EmployeeClockedOut` y el evento de correccion; de lo que
 * contienen, solo cambiaron de nombre el propio evento, `ShiftTimes` y el enum
 * `ShiftAnomaly`. El resto de los renombrados de la 2.3.0 no viaja: los
 * comandos y los casos de uso se ejecutan en el proceso que los crea, las
 * excepciones se guardan en `failed_jobs` como texto, las utilidades de
 * `Domain/Support/` son estaticas y los dos trabajos diferidos
 * (`GenerateReportExportJob`, `GenerateDataExportJob`) llevan solo un UUID.
 *
 * ## Como se carga
 *
 * Por `autoload.files` de Composer (`bootstrap/renamed-queued-classes.php`) y no
 * por un proveedor: el alias tiene que existir en cualquier proceso que pueda
 * deserializar un trabajo, arranque o no la aplicacion entera
 * (`queue:failed`, `queue:retry`, Horizon leyendo una carga, una prueba
 * unitaria). Y es **perezoso**: se registra un cargador que solo actua cuando
 * alguien pide el nombre viejo, asi que una peticion normal no carga ninguna de
 * estas clases.
 *
 * **Se retiran en la 2.4.0** (`REMOVED_IN`). Una version entera basta para que
 * cualquier trabajo encolado antes de actualizar se haya consumido o haya ido a
 * `failed_jobs`. `RenamedQueuedClassesTest` falla en cuanto `VERSION` llega a
 * la 2.4.0 con los alias todavia aqui, y antes de eso, si alguien quita uno.
 */
final class RenamedQueuedClasses
{
    /** La version en la que este fichero y su entrada en `autoload.files` desaparecen. */
    public const string REMOVED_IN = '2.4.0';

    /**
     * Nombre de la 2.2.x => nombre actual. Todos se retiran en la 2.4.0.
     *
     * @var array<string, class-string>
     */
    public const array ALIASES = [
        // Retirar en la 2.4.0.
        'App\Modules\Attendance\Domain\Event\ShiftCorrected' => ShiftEntryCorrected::class,
        // Retirar en la 2.4.0.
        'App\Modules\Attendance\Domain\ValueObject\ShiftTimes' => ShiftEntryTimes::class,
        // Retirar en la 2.4.0.
        'App\Modules\Attendance\Domain\ValueObject\ShiftAnomaly' => ShiftEntryAnomaly::class,
    ];

    private static bool $registered = false;

    public static function register(): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        spl_autoload_register(static function (string $class): void {
            $current = self::ALIASES[$class] ?? null;

            if ($current !== null) {
                class_alias($current, $class);
            }
        });
    }
}
