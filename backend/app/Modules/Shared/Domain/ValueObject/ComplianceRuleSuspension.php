<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Que reglas de cumplimiento **estan enunciadas y probadas pero hoy no abren
 * incidencia en esta instalacion** (doc 01 §4, notas sobre RN-12).
 *
 * ## Por que esto vive aqui y no dentro del caso de uso que filtra
 *
 * Nacio como una constante privada de `DetectAttendanceAnomalies`, que es quien
 * descarta el hallazgo. Funcionaba mientras solo la mirase quien filtra; dejo de
 * funcionar en cuanto el panel empezo a decirle al cliente que cambiar
 * `break_required_after_hours` «hara que se marquen jornadas distintas» y el
 * asiento de `audit_log` empezo a afirmar `affects_incident_detection: true`.
 * Las dos cosas eran **falsas**, y la segunda lo era dentro de un registro con
 * valor legal.
 *
 * `Product` no puede importar `Attendance` (doc 02 §1.6), asi que el hecho vive
 * en `Shared`, que es el unico sitio que los dos alcanzan.
 *
 * ## Dejo de ser una constante en la tarea 3.5 (decision 8)
 *
 * Hasta RF-AT-12 la suspension de RN-12 era del **producto**: sin pausa
 * declarada, un hueco entre dos tramos podia ser una comida o el descanso entre
 * dos turnos, y las dos cosas se leian igual en la tabla. Con el fichaje de
 * pausa la suspension pasa a ser de la **instalacion**: RN-12 abre incidencias
 * donde el quiosco registra la pausa y sigue suspendida donde no.
 *
 * Y sigue suspendida **de serie**, porque `ATTENDANCE_BREAK_CLOCKING` nace en
 * `disabled` (decision 7): activar la regla en un hotel cuya plantilla no ficha
 * la pausa abriria `missing_break` contra gente que descanso sin fichar, que es
 * peor que no abrir nada. Desactivarla vuelve a suspenderla sin cerrar nada de
 * lo ya abierto.
 *
 * ## Es una instancia, y por eso llega por parametro
 *
 * Los consumidores —`AnomalyType`, `ComplianceProfileField`,
 * `ComplianceEvaluation`— la **reciben** de quien tiene acceso a
 * `OperationalSettingsProvider`, en lugar de preguntarle a un estatico. Es la
 * regla dura 14 aplicada a un booleano: el dominio recibe el ajuste ya resuelto
 * y nunca consulta la configuracion. Ademas es lo que permite probar las dos
 * ramas —activada y desactivada— sin tocar ninguna constante privada, que es
 * justo lo que la trampa apuntada en `HANDOFF.md` describia como imposible.
 *
 * **Suspendida no es lo mismo que inexistente**, y la diferencia importa en la
 * pantalla: RN-12 se evalua en el dominio y tiene sus pruebas en los limites
 * 5:59 / 6:00 / 6:01; lo unico que no ocurre es la **apertura de la incidencia**.
 * Por eso quien consulta esto pregunta si la regla abre incidencias aqui, no si
 * existe.
 */
final readonly class ComplianceRuleSuspension
{
    /**
     * @param  list<ComplianceRule>  $suspended  las reglas cuya apertura de incidencia esta
     *                                           suspendida en esta instalacion
     */
    private function __construct(private array $suspended) {}

    /**
     * La suspension que corresponde a esta instalacion.
     *
     * Un solo parametro porque hoy solo hay una regla suspendible y un solo
     * ajuste que la gobierna. Que sea un constructor con nombre y no un
     * `new self([...])` publico es lo que mantiene la decision en un sitio: el
     * dia que una segunda regla dependa de otro ajuste, la firma crece aqui y
     * todos los consumidores la reciben ya resuelta, sin enterarse.
     *
     * @param  bool  $breakClockingEnabled  `ATTENDANCE_BREAK_CLOCKING` de la instalacion
     *                                      (RF-AT-12, `OperationalSettings::$breakClockingEnabled`)
     */
    public static function forInstallation(bool $breakClockingEnabled): self
    {
        return new self($breakClockingEnabled ? [] : [ComplianceRule::BreakInContinuousShift]);
    }

    /**
     * Nada suspendido: las cuatro reglas del perfil se comportan segun lo que
     * cada una es ({@see ComplianceRule::opensIncident()}).
     *
     * Existe para que una prueba que no va de la suspension pueda decirlo en
     * lugar de escribir `forInstallation(true)`, que se lee como si el caso
     * tratara del ajuste. Es el mismo motivo por el que `DebouncePolicy` tiene
     * `disabled()` y no un `0` suelto.
     */
    public static function none(): self
    {
        return new self([]);
    }

    public function isSuspended(ComplianceRule $rule): bool
    {
        return in_array($rule, $this->suspended, true);
    }

    /**
     * @return list<ComplianceRule>
     */
    public function suspended(): array
    {
        return $this->suspended;
    }
}
