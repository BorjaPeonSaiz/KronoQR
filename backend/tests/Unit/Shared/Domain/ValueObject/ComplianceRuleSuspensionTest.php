<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\ComplianceRule;
use App\Modules\Shared\Domain\ValueObject\ComplianceRuleSuspension;

/*
 * RF-AT-12 y ADR-024 — que reglas no abren incidencia en esta instalacion.
 *
 * Hasta la tarea 3.5 esto era una constante del producto y su rama «no
 * suspendida» **no se podia ejercitar desde ninguna prueba** (trampa apuntada en
 * `HANDOFF.md`). Ahora es un objeto de valor construido desde el ajuste del
 * hotel, y las dos ramas se prueban aqui, que es donde la decision vive.
 *
 * Dominio puro: ni framework, ni base de datos, ni reloj.
 */

it('no suspende nada cuando el hotel ficha la pausa', function (): void {
    // Activar `ATTENDANCE_BREAK_CLOCKING` devuelve RN-12 a la bandeja sin tocar
    // `Attendance` ni `Product`: todo lo que depende de esto se deriva.
    $suspension = ComplianceRuleSuspension::forInstallation(breakClockingEnabled: true);

    expect($suspension->suspended())->toBe([])
        ->and($suspension->isSuspended(ComplianceRule::BreakInContinuousShift))->toBeFalse();
})->group('RN-12', 'RF-AT-12', 'RQ-01');

it('suspende RN-12, y solo RN-12, mientras el fichaje de pausa este desactivado', function (): void {
    // **El estado de serie** (decision 7 de la ficha 3.5): sin pausa declarada,
    // un hueco entre dos tramos puede ser una comida o el descanso entre dos
    // turnos. Abrir `missing_break` ahi seria alertar contra gente que descanso
    // sin fichar.
    $suspension = ComplianceRuleSuspension::forInstallation(breakClockingEnabled: false);

    expect($suspension->suspended())->toBe([ComplianceRule::BreakInContinuousShift])
        ->and($suspension->isSuspended(ComplianceRule::BreakInContinuousShift))->toBeTrue();
})->group('RN-12', 'RF-AT-12', 'RQ-01');

it('no suspende jamas las otras tres reglas del perfil', function (bool $breakClocking): void {
    // RN-10 y RN-11 abren incidencia desde la tarea 2.6 y RN-17 no la abre nunca
    // por definicion (art. 34.1 ET, computo anual). Ninguna de las tres depende
    // del fichaje de pausa, y afirmarlo aqui es lo que impide que un ajuste de
    // quiosco acabe apagando el descanso entre jornadas.
    $suspension = ComplianceRuleSuspension::forInstallation($breakClocking);

    expect($suspension->isSuspended(ComplianceRule::MinimumRestBetweenWorkDays))->toBeFalse()
        ->and($suspension->isSuspended(ComplianceRule::MaximumDailyWorkingTime))->toBeFalse()
        ->and($suspension->isSuspended(ComplianceRule::MaximumWeeklyWorkingTime))->toBeFalse();
})->with([
    'con la pausa activada' => [true],
    'con la pausa desactivada' => [false],
])->group('RN-10', 'RN-11', 'RN-17', 'RF-AT-12');

it('ofrece un constructor sin suspensiones para quien no habla del ajuste', function (): void {
    // `none()` y no `forInstallation(true)`: una prueba que no va de la pausa no
    // deberia parecer que va de la pausa. Mismo motivo que `DebouncePolicy::disabled()`.
    expect(ComplianceRuleSuspension::none()->suspended())->toBe([])
        ->and(ComplianceRuleSuspension::none()->isSuspended(ComplianceRule::BreakInContinuousShift))->toBeFalse();
})->group('RN-12', 'RQ-01');

it('distingue suspender de no abrir incidencia, que no es lo mismo', function (): void {
    /*
     * Confundirlos seria grave y por eso se afirma aqui: la suspension de RN-12
     * es **temporal y del hotel** —se quita activando un ajuste—, mientras que
     * RN-17 no abre incidencia **nunca**, porque el art. 34.1 ET fija la jornada
     * semanal en computo anual y una semana por encima no es por si sola un
     * incumplimiento.
     *
     * Con la pausa activada, RN-12 vuelve a abrir y RN-17 sigue sin abrir: son
     * dos ejes distintos y ninguno se deriva del otro.
     */
    $activada = ComplianceRuleSuspension::forInstallation(breakClockingEnabled: true);

    expect($activada->isSuspended(ComplianceRule::BreakInContinuousShift))->toBeFalse()
        ->and(ComplianceRule::BreakInContinuousShift->opensIncident())->toBeTrue()
        ->and($activada->isSuspended(ComplianceRule::MaximumWeeklyWorkingTime))->toBeFalse()
        ->and(ComplianceRule::MaximumWeeklyWorkingTime->opensIncident())->toBeFalse();
})->group('RN-12', 'RN-17', 'RF-AT-12');
