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

    /** RN-17: jornada semanal ordinaria (art. 34.1 ET). */
    case MaxWeeklyHours = 'max_weekly_hours';

    /** RN-12: tramo continuo maximo sin pausa registrada. */
    case BreakRequiredAfterHours = 'break_required_after_hours';

    /** RN-17: dia en que empieza la semana del perfil, ISO-8601. */
    case WeekStartsOn = 'week_starts_on';

    /** RF-GP-04: festivos del centro. Los aplica el informe por periodo desde la tarea 3.10. */
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
     * `null` si el campo no gobierna ninguna regla: el nombre del convenio, los
     * años de retencion y los festivos. Los festivos **si tienen consumidor**
     * desde la tarea 3.10 —el informe por periodo no los cuenta como absentismo
     * (RF-GP-04)—, pero no son el umbral de ninguna de las cuatro reglas del
     * perfil, asi que aqui siguen en `null` y {@see self::affectsIncidentDetection()}
     * sigue siendo `false` para ellos: ningun festivo abre ni cierra una
     * incidencia.
     *
     * Es el vocabulario de `Shared`, que es el unico que este modulo comparte con
     * `Attendance` y con `Reporting` (doc 02 §1.6). Sirve para saber si la regla
     * abre incidencias **hoy** sin tener que repetir aqui la lista de reglas
     * suspendidas.
     *
     * **`week_starts_on` gobierna RN-17 igual que `max_weekly_hours`**, aunque no
     * sea un umbral sino el dia por el que se corta la semana: mover el inicio de
     * semana cambia que siete jornadas se suman, y por tanto cambia que semanas
     * salen señaladas. Dejarlo fuera habria hecho que el asiento de auditoria de
     * ese cambio dijera «no afecta a nada».
     */
    public function complianceRule(): ?ComplianceRule
    {
        return match ($this) {
            self::MinRestHours => ComplianceRule::MinimumRestBetweenWorkDays,
            self::MaxDailyHours => ComplianceRule::MaximumDailyWorkingTime,
            self::BreakRequiredAfterHours => ComplianceRule::BreakInContinuousShift,
            self::MaxWeeklyHours, self::WeekStartsOn => ComplianceRule::MaximumWeeklyWorkingTime,
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
     * **Dice la verdad de hoy, no la del catalogo.** Gobernar una regla no basta,
     * y hay dos motivos distintos por los que un umbral legal puede no mover
     * ninguna incidencia:
     *
     *   - **La regla no abre incidencias, por definicion.** RN-17 no lo hace: el
     *     art. 34.1 ET fija la jornada semanal en computo anual, asi que una
     *     semana larga se señala en la vista de cumplimiento y no en la bandeja
     *     ({@see ComplianceRule::opensIncident()}). Es permanente.
     *   - **La apertura esta suspendida.** RN-12 lo esta mientras el fichaje de
     *     pausa siga desactivado en esta instalacion (ADR-024, RF-AT-12), y lo
     *     dice {@see ComplianceRuleSuspension}. Es temporal y **depende del
     *     hotel**: activar `ATTENDANCE_BREAK_CLOCKING` devuelve esto a `true`
     *     solo, sin tocar este fichero.
     *
     * Escribir `true` en cualquiera de los dos casos seria afirmar algo falso
     * dentro de un registro con valor legal. Lo que distingue uno de otro para
     * quien lea el asiento son {@see self::governsSuspendedRule()} y
     * {@see self::affectsComplianceView()}.
     *
     * **La suspension llega por parametro** (regla dura 14): quien la resuelve
     * es `UpdateComplianceProfileHandler`, que es el que alcanza los ajustes de
     * la instalacion. Un enum de dominio que fuera a buscarla seria dominio
     * leyendo configuracion.
     */
    public function affectsIncidentDetection(ComplianceRuleSuspension $suspension): bool
    {
        $rule = $this->complianceRule();

        return $rule instanceof ComplianceRule
            && $rule->opensIncident()
            && ! $suspension->isSuspended($rule);
    }

    /**
     * Si cambiarlo cambia **lo que enseña la vista de cumplimiento** (RF-PA-06).
     *
     * Es el efecto que faltaba, y hace falta por RN-17: `max_weekly_hours` y
     * `week_starts_on` dejaron de ser «campos sin consumidor» con la tarea 3.4,
     * pero **no** afectan a la deteccion de incidencias. Sin este tercer efecto,
     * el asiento de auditoria de un cambio suyo seria indistinguible del de un
     * cambio de nombre del convenio —los dos con los tres booleanos en `false`—,
     * y son cosas muy distintas: una mueve los avisos que RRHH revisa.
     *
     * **Las cuatro reglas del perfil se enseñan en esa vista**, tambien la
     * suspendida: `meta.rules[]` lleva el umbral de RN-12 con `evaluated: false`,
     * asi que cambiarlo cambia lo que la pantalla dice. Por eso esto es
     * exactamente «gobierna alguna regla» y no un subconjunto.
     */
    public function affectsComplianceView(): bool
    {
        return $this->complianceRule() instanceof ComplianceRule;
    }

    /**
     * Si el campo gobierna una regla que existe, se evalua y tiene sus pruebas,
     * pero **cuya apertura de incidencia esta suspendida en esta instalacion**.
     *
     * Es lo que distingue «este campo no mueve ninguna alerta porque no gobierna
     * ninguna regla» —el nombre del convenio— de «este campo gobierna RN-12 y
     * RN-12 no esta abriendo incidencias». Sin esta distincion, el asiento y la
     * pantalla dirian lo mismo de las dos cosas, y son muy distintas: la segunda
     * cambia de comportamiento en cuanto el hotel active
     * `ATTENDANCE_BREAK_CLOCKING` (RF-AT-12), y eso es un ajuste que quien lee la
     * pantalla puede cambiar el mismo.
     *
     * **No es lo mismo que {@see self::hasNoConsumerYet()}**, y confundirlos seria
     * mentir en la otra direccion: aquellos tres campos no los lee **ninguna**
     * regla; este lo lee una regla que si se evalua.
     *
     * Recibe la suspension por el mismo motivo que
     * {@see self::affectsIncidentDetection()}: desde la tarea 3.5 la respuesta
     * depende de un ajuste del hotel, y el dominio no lo consulta.
     */
    public function governsSuspendedRule(ComplianceRuleSuspension $suspension): bool
    {
        $rule = $this->complianceRule();

        return $rule instanceof ComplianceRule && $suspension->isSuspended($rule);
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
     * Si el producto lo guarda pero todavia **no lo aplica nadie**.
     *
     * **Ya no queda ninguno, y eso es el final de una promesa concreta.** Este
     * predicado nacio con tres campos, la tarea 3.4 estreno `max_weekly_hours` y
     * `week_starts_on` con RN-17, y `holiday_calendar` —el ultimo— lo estrena la
     * tarea 3.10: el informe por periodo no cuenta un festivo del perfil como
     * absentismo (RF-GP-04, `Reporting\Domain\Policy\AbsenteeismRule`, nombrada
     * en prosa porque `Product` no ve `Reporting`). El doc 05 §9 prometia que
     * «lo estrenara la gestion de ausencias», y esto es eso.
     *
     * **No se borra el metodo.** Devolver `false` siempre no es codigo muerto:
     * es la afirmacion —verificada por `ComplianceProfileSnapshotTest`— de que
     * ningun campo editable del perfil es decorativo. El dia que se añada uno
     * antes que su consumidor, este es el sitio donde se declara, y el panel lo
     * pinta sin tocar nada mas.
     *
     * **Aplicar no es abrir incidencias.** `holiday_calendar` lo lee ahora un
     * informe, no la revision diaria: {@see self::affectsIncidentDetection()}
     * sigue siendo `false` para el, porque ningun festivo abre ni cierra nada en
     * la bandeja.
     *
     * **No confundir con {@see self::governsSuspendedRule()}.** Aquel dice que un
     * campo gobierna una regla que si se evalua y cuya **apertura de incidencia**
     * esta suspendida (RN-12 mientras el quiosco no registre la pausa, ADR-024).
     * Meterlos en el mismo saco haria que la pantalla dijera «no lo aplica
     * ninguna regla» de una regla que si se aplica.
     */
    public function hasNoConsumerYet(): bool
    {
        return false;
    }
}
