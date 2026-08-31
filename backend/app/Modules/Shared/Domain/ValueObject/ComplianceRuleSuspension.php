<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\ValueObject;

/**
 * Que reglas de cumplimiento **estan enunciadas y probadas pero todavia no
 * abren incidencia** (doc 01 §4, notas sobre RN-12).
 *
 * ## Por que esta lista vive aqui y no dentro del caso de uso que filtra
 *
 * Nacio como una constante privada de `DetectAttendanceAnomalies`, que es quien
 * descarta el hallazgo. Funcionaba mientras solo la mirase quien filtra; dejo de
 * funcionar en cuanto el panel empezo a decirle al cliente que cambiar
 * `break_required_after_hours` «hara que se marquen jornadas distintas» y el
 * asiento de `audit_log` empezo a afirmar `affects_incident_detection: true`.
 * Las dos cosas eran **falsas**, y la segunda lo era dentro de un registro con
 * valor legal.
 *
 * `Product` no puede importar `Attendance` (doc 02 §1.6), asi que el hecho sube
 * a `Shared`, que es el unico sitio que los dos alcanzan. Lo que gana el
 * producto es que **la tarea 3.5 reactive la regla vaciando UNA lista**: el
 * asiento, la metrica y los textos del panel vuelven a decir la verdad solos,
 * sin que nadie tenga que acordarse de tocar `Product`. Una prueba fija
 * exactamente esa propiedad.
 *
 * **Suspendida no es lo mismo que inexistente**, y la diferencia importa en la
 * pantalla: RN-12 se evalua en el dominio y tiene sus pruebas en los limites
 * 5:59 / 6:00 / 6:01; lo unico que no ocurre es la **apertura de la incidencia**.
 * Por eso quien consulta esto pregunta si la regla abre incidencias hoy, no si
 * existe.
 */
final class ComplianceRuleSuspension
{
    /**
     * Las reglas cuya apertura de incidencia esta suspendida.
     *
     * **La tarea 3.5 la vacia** cuando el quiosco registre la pausa declarada
     * (RF-AT-12, ADR-024). Ese es el unico cambio que hace falta: todo lo que
     * depende de esto se deriva.
     *
     * @var list<ComplianceRule>
     */
    private const array SUSPENDED = [ComplianceRule::BreakInContinuousShift];

    public static function isSuspended(ComplianceRule $rule): bool
    {
        return in_array($rule, self::SUSPENDED, true);
    }

    /**
     * @return list<ComplianceRule>
     */
    public static function suspended(): array
    {
        return self::SUSPENDED;
    }
}
