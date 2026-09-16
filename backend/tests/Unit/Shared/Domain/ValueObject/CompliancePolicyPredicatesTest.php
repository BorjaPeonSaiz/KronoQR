<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\CompliancePolicy;

/*
 * **Las cuatro comparaciones del perfil de cumplimiento, en sus limites**
 * (RN-10, RN-11, RN-12, RN-17; RF-PD-07, RF-PA-06).
 *
 * ## Por que estos cuatro metodos merecen un fichero propio
 *
 * Porque son el **unico** sitio del producto donde se decide si un umbral legal
 * se ha superado, y lo usan dos modulos que no pueden verse (doc 02 §1.6): la
 * revision diaria de `Attendance`, que abre las incidencias, y el evaluador de
 * `Reporting`, que pinta la vista de cumplimiento. Los dos **tienen que contar lo
 * mismo**: con un `<` en cada uno, bastaria tocar uno para que la bandeja marcara
 * una jornada que la pantalla no, y quien lo descubriria seria un empleado
 * defendiendose de un aviso.
 *
 * ## Los limites son ABIERTOS, y es lo que se prueba
 *
 * El doc 01 los enuncia asi: con 12 h de descanso minimo, **11 h 59 alerta y 12 h
 * exactas no**. Es el matiz que se pierde al copiar una comparacion, y por eso
 * cada regla tiene aqui sus dos casos pegados al limite —y RN-12 tres, porque su
 * limite es por arriba y conviene ver los dos lados—.
 *
 * ## Y ningun numero se da por sabido
 *
 * El perfil se construye en cada caso con los valores escritos. Que el `ES-hosteleria`
 * de serie sean 12/9/6/40 es un dato de la **migracion**, no del codigo: probarlo
 * contra constantes de PHP seria probar justamente lo que la regla dura 14
 * prohibe.
 */

/**
 * Un perfil con los umbrales que pida el caso, en horas.
 *
 * Nombre largo y propio del fichero: en Pest las funciones de un fichero de
 * prueba son **globales** para toda la suite, asi que un `perfil()` chocaria con
 * el de la primera prueba que lo declarara.
 */
function policyWithHours(
    int $restHours = 12,
    int $dailyHours = 9,
    int $breakAfterHours = 6,
    int $weeklyHours = 40,
    int $weekStartsOn = 1,
): CompliancePolicy {
    return new CompliancePolicy(
        minimumRestMinutes: $restHours * 60,
        maximumDailyMinutes: $dailyHours * 60,
        breakRequiredAfterMinutes: $breakAfterHours * 60,
        retentionYears: 4,
        maximumWeeklyMinutes: $weeklyHours * 60,
        weekStartsOn: $weekStartsOn,
        holidayCalendar: [],
    );
}

it('RN-10: once horas y cincuenta y nueve minutos de descanso alertan y doce horas exactas no', function (): void {
    $policy = policyWithHours(restHours: 12);

    expect($policy->restIsInsufficient(11 * 60 + 59))->toBeTrue()
        ->and($policy->restIsInsufficient(12 * 60))->toBeFalse()
        // Cero minutos es descanso —el peor que se puede registrar sin solapar— y
        // por tanto insuficiente. Un solape no llega aqui: de eso responde RN-02
        // en el esquema.
        ->and($policy->restIsInsufficient(0))->toBeTrue();
})->group('RN-10', 'RF-PD-07', 'RF-PA-06');

it('RN-11: nueve horas exactas de jornada no alertan y nueve horas y un minuto si', function (): void {
    $policy = policyWithHours(dailyHours: 9);

    expect($policy->dailyTimeIsExcessive(9 * 60))->toBeFalse()
        ->and($policy->dailyTimeIsExcessive(9 * 60 + 1))->toBeTrue();
})->group('RN-11', 'RF-PD-07', 'RF-PA-06');

it('RN-12: un tramo continuo de seis horas exactas no pide pausa y uno de seis horas y un minuto si', function (): void {
    $policy = policyWithHours(breakAfterHours: 6);

    expect($policy->continuousShiftNeedsBreak(5 * 60 + 59))->toBeFalse()
        ->and($policy->continuousShiftNeedsBreak(6 * 60))->toBeFalse()
        ->and($policy->continuousShiftNeedsBreak(6 * 60 + 1))->toBeTrue();
})->group('RN-12', 'RF-PD-07', 'RF-PA-06');

it('RN-17: cuarenta horas exactas de semana no alertan y cuarenta horas y un minuto si', function (): void {
    // La regla nueva de la tarea 3.4. Es informativa —el art. 34.1 ET fija la
    // jornada semanal en computo anual— pero su limite se lee igual que los
    // otros tres: abierto.
    $policy = policyWithHours(weeklyHours: 40);

    expect($policy->weeklyTimeIsExcessive(40 * 60))->toBeFalse()
        ->and($policy->weeklyTimeIsExcessive(40 * 60 + 1))->toBeTrue()
        // Una semana sin fichar no incumple nada.
        ->and($policy->weeklyTimeIsExcessive(0))->toBeFalse();
})->group('RN-17', 'RF-PD-07', 'RF-PA-06');

it('cambia lo que alerta al cambiar el perfil, sin tocar una linea de codigo', function (): void {
    /*
     * El escenario «Perfil de cumplimiento distinto» del doc 01 §11, escrito una
     * sola vez y sobre el objeto de valor: vale para la bandeja y para la vista,
     * porque las dos preguntan aqui.
     *
     * Once horas de descanso: con un convenio de 10 h se cumple y con el espanol
     * de 12 h no. Ni un `12` en el dominio (regla dura 14, ADR-017).
     */
    $elevenHours = 11 * 60;

    expect(policyWithHours(restHours: 10)->restIsInsufficient($elevenHours))->toBeFalse()
        ->and(policyWithHours(restHours: 12)->restIsInsufficient($elevenHours))->toBeTrue();
})->group('RF-PD-07', 'RN-10', 'RF-PA-06');

it('el dia en que empieza la semana viaja en el perfil y no es siempre el lunes', function (): void {
    // `week_starts_on` en numeracion ISO-8601: 1 lunes, 7 domingo. Es lo que la
    // tarea 3.4 estrena y lo que hace que RN-17 se pueda vender fuera de Europa.
    expect(policyWithHours(weekStartsOn: 1)->weekStartsOn)->toBe(1)
        ->and(policyWithHours(weekStartsOn: 7)->weekStartsOn)->toBe(7);
})->group('RN-17', 'RF-PD-07');
