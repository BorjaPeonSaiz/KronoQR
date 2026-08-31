<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\ComplianceRule;
use App\Modules\Shared\Domain\ValueObject\ComplianceRuleSuspension;

/**
 * Los campos **editables** del perfil de cumplimiento (RF-PD-07, regla dura 14).
 *
 * El valor de cada caso es el nombre de la columna de `compliance_profiles` y el
 * del campo del contrato: son el mismo nombre a proposito, porque cualquier
 * traduccion entre los tres sitios es una traduccion que alguien puede olvidar
 * al añadir el siguiente.
 *
 * ## Que NO esta aqui, y por que
 *
 * - **`id`**, evidente.
 * - **`jurisdiction`**: hoy no la lee ninguna regla. Un campo editable cuyo
 *   cambio no tiene efecto es peor que uno que no existe, porque quien lo cambia
 *   cree haber configurado algo.
 * - **`is_default`**: es lo que hace que el centro resuelva su perfil cuando no
 *   tiene uno asignado (ADR-040: un centro por instalacion). Poder apagarlo desde
 *   el panel es poder dejar la instalacion sin umbrales legales resolubles.
 *
 * ## Los limites viven aqui y en el esquema, y las dos copias son a proposito
 *
 * Aqui producen el `422` con el mensaje util; el `CHECK` de `compliance_profiles`
 * es la ultima linea de defensa, que vale igual si alguien edita la fila con
 * `psql`. Los dos numeros tienen que coincidir y una prueba de integracion los
 * ata.
 */
enum ComplianceProfileField: string
{
    /** Como se llama el convenio que el perfil describe. */
    case Name = 'name';

    /** RN-10: descanso minimo entre el fin de un turno y el inicio del siguiente. */
    case MinRestHours = 'min_rest_hours';

    /** RN-11: jornada diaria ordinaria por encima de la cual se alerta. */
    case MaxDailyHours = 'max_daily_hours';

    /** Jornada semanal ordinaria (art. 34.1 ET). Sin consumidor hasta la tarea 3.4. */
    case MaxWeeklyHours = 'max_weekly_hours';

    /** RN-12: tramo continuo maximo sin pausa registrada. */
    case BreakRequiredAfterHours = 'break_required_after_hours';

    /** Dia en que empieza la semana, ISO-8601. Sin consumidor hasta la tarea 3.4. */
    case WeekStartsOn = 'week_starts_on';

    /** Festivos del centro. Sin consumidor hasta la tarea 3.4. */
    case HolidayCalendar = 'holiday_calendar';

    /** RL-02: años que se conserva el registro horario antes de poder purgarlo. */
    case RetentionYears = 'retention_years';

    public function type(): ComplianceProfileFieldType
    {
        return match ($this) {
            self::Name => ComplianceProfileFieldType::Text,
            self::HolidayCalendar => ComplianceProfileFieldType::DateList,
            default => ComplianceProfileFieldType::Integer,
        };
    }

    /**
     * Minimo admitido, para los campos enteros.
     *
     * Uno, sin excepcion: un cero en cualquiera de estos umbrales no describe un
     * convenio mas laxo, apaga la regla — y una regla legal apagada es
     * indistinguible de una plantilla que cumple.
     */
    public function minimum(): int
    {
        return 1;
    }

    /**
     * Maximo admitido, para los campos enteros.
     *
     * Existen porque esta tarea abre por primera vez un camino de escritura
     * **humano** a estos umbrales. El error peligroso no es el valor absurdo que
     * se ve, es el silencioso: `max_daily_hours = 90` —el 9 con un cero de mas—
     * no rompe nada, no da error y apaga RN-11 hasta que alguien compara una
     * nomina con el convenio.
     */
    public function maximum(): int
    {
        return match ($this) {
            self::MaxWeeklyHours => 168,
            self::RetentionYears => 50,
            // ISO-8601: de 1 (lunes) a 7 (domingo). El `CHECK` del esquema dice
            // lo mismo, y sin este caso el `422` se convertiria en un `500` de
            // PostgreSQL — un error del cliente presentado como una averia.
            self::WeekStartsOn => 7,
            default => 24,
        };
    }

    /** Longitud maxima del nombre, que es la de la columna. */
    public function maximumLength(): int
    {
        return 64;
    }

    /**
     * La regla del **perfil de cumplimiento** cuyo umbral fija este campo, o
     * `null` si el campo no gobierna ninguna regla (el nombre del convenio, los
     * años de retencion, y los tres que todavia no lee nadie).
     *
     * Es el vocabulario de `Shared`, que es el unico que este modulo comparte con
     * `Attendance` (doc 02 §1.6). Sirve para saber si la regla abre incidencias
     * **hoy** sin tener que repetir aqui la lista de reglas suspendidas.
     */
    public function complianceRule(): ?ComplianceRule
    {
        return match ($this) {
            self::MinRestHours => ComplianceRule::MinimumRestBetweenWorkDays,
            self::MaxDailyHours => ComplianceRule::MaximumDailyWorkingTime,
            self::BreakRequiredAfterHours => ComplianceRule::BreakInContinuousShift,
            default => null,
        };
    }

    /**
     * Si cambiarlo cambia **que incidencias se abren** en la revision diaria.
     *
     * Es la mitad del asiento de auditoria que responde a la pregunta que trae
     * quien llega con una inspeccion delante: «¿por que esta jornada no genero
     * alerta?».
     *
     * **Dice la verdad de hoy, no la del catalogo.** Gobernar una regla no basta:
     * si la apertura de esa regla esta suspendida —RN-12 lo esta hasta que el
     * quiosco registre la pausa declarada (ADR-024, RF-AT-12, tarea 3.5)—
     * cambiar su umbral **no altera ni una incidencia**, y escribir `true` en un
     * registro con valor legal seria afirmar algo falso. Se deriva de
     * {@see ComplianceRuleSuspension}: el dia que la 3.5 vacie esa lista, esto
     * vuelve a `true` solo, sin tocar este fichero.
     */
    public function affectsIncidentDetection(): bool
    {
        $rule = $this->complianceRule();

        return $rule instanceof ComplianceRule && ! ComplianceRuleSuspension::isSuspended($rule);
    }

    /**
     * Si el campo gobierna una regla que existe, se evalua y tiene sus pruebas,
     * pero **cuya apertura de incidencia esta suspendida hoy**.
     *
     * Es lo que distingue «este campo no mueve ninguna alerta porque no gobierna
     * ninguna regla» —el nombre del convenio— de «este campo gobierna RN-12 y
     * RN-12 no esta abriendo incidencias». Sin esta distincion, el asiento y la
     * pantalla dirian lo mismo de las dos cosas, y son muy distintas: la segunda
     * cambia de comportamiento en cuanto llegue la tarea 3.5.
     *
     * **No es lo mismo que {@see self::hasNoConsumerYet()}**, y confundirlos seria
     * mentir en la otra direccion: aquellos tres campos no los lee **ninguna**
     * regla; este lo lee una regla que si se evalua.
     */
    public function governsSuspendedRule(): bool
    {
        $rule = $this->complianceRule();

        return $rule instanceof ComplianceRule && ComplianceRuleSuspension::isSuspended($rule);
    }

    /**
     * Si cambiarlo cambia **que datos puede purgar la retencion** (RL-02).
     *
     * Solo uno, y es el unico campo del perfil cuyo error se paga con datos que
     * no vuelven. Se separa de la deteccion porque son dos consecuencias
     * distintas y quien lee el trail busca una o la otra.
     */
    public function affectsRetention(): bool
    {
        return $this === self::RetentionYears;
    }

    /**
     * Si el producto lo guarda pero todavia **no lo aplica ninguna regla**.
     *
     * Los estrena la vista de cumplimiento (tarea 3.4). Se declara aqui —y viaja
     * al panel— para no prometer un efecto que hoy no existe.
     *
     * **No confundir con {@see self::governsSuspendedRule()}.** Aquellos tres no
     * los lee **nadie**; `break_required_after_hours` lo lee RN-12, que se evalua
     * y tiene sus pruebas — lo unico suspendido es que abra incidencia. Meterlos
     * en el mismo saco haria que la pantalla dijera «no lo aplica ninguna regla»
     * de una regla que si se aplica, que es mentir en la otra direccion.
     */
    public function hasNoConsumerYet(): bool
    {
        return match ($this) {
            self::MaxWeeklyHours, self::WeekStartsOn, self::HolidayCalendar => true,
            default => false,
        };
    }
}
