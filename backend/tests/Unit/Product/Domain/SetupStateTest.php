<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\SetupState;
use App\Modules\Product\Domain\ValueObject\SetupStep;
use App\Modules\Product\Domain\ValueObject\SetupStepState;

/*
 * El estado del asistente de puesta en marcha (RF-PD-03), sin base de datos.
 *
 * Es donde vive la regla: que pasos bloquean el cierre, cuales admiten
 * `skipped` y —lo mas importante— que los dos derivados **ignoran cualquier
 * marca guardada**. Esa ultima es la que impide que una fila perdida en una
 * restauracion, o un `PUT` mal dirigido, hagan que el asistente afirme que hay
 * administrador cuando no lo hay.
 */

it('nace con los ocho pasos en pendiente', function (): void {
    $state = SetupState::of([], hasAdministrator: false, hasSite: false, completedAt: null);

    foreach (SetupStep::cases() as $step) {
        expect($state->stateOf($step))->toBe(SetupStepState::PENDING, $step->value);
    }

    expect($state->isAvailable())->toBeTrue();
})->group('RF-PD-03');

it('deriva el administrador y el centro del dato, ignorando cualquier marca', function (): void {
    // Con marcas guardadas que dicen lo contrario: el dato manda. Si no lo
    // hiciera, bastaria una fila escrita a mano para que el asistente diera por
    // hecho un paso que nadie ha hecho.
    $state = SetupState::of(
        [
            SetupStep::ADMINISTRATOR->value => SetupStepState::COMPLETED,
            SetupStep::SITE->value => SetupStepState::COMPLETED,
        ],
        hasAdministrator: false,
        hasSite: false,
        completedAt: null,
    );

    expect($state->stateOf(SetupStep::ADMINISTRATOR))->toBe(SetupStepState::PENDING)
        ->and($state->stateOf(SetupStep::SITE))->toBe(SetupStepState::PENDING);
})->group('RF-PD-03');

it('cuenta como sin resolver el paso obligatorio que se omitiera', function (): void {
    // No deberia poder ocurrir —el caso de uso lo rechaza— pero si una fila
    // llegara asi, el asistente no puede darla por buena: `skipped` resuelve un
    // paso omitible, nunca uno obligatorio.
    $state = SetupState::of(
        [SetupStep::COMPLIANCE_PROFILE->value => SetupStepState::SKIPPED],
        hasAdministrator: true,
        hasSite: true,
        completedAt: null,
    );

    expect($state->unresolvedSteps())->toContain(SetupStep::COMPLIANCE_PROFILE);
})->group('RF-PD-03', 'RL-21');

it('da por resuelto el paso omitible que se omite', function (): void {
    // Regla dura 15: omitir la licencia es una decision, no un olvido, y no puede
    // impedir terminar la puesta en marcha.
    $state = SetupState::of(
        [
            SetupStep::ORGANISATION->value => SetupStepState::COMPLETED,
            SetupStep::COMPLIANCE_PROFILE->value => SetupStepState::COMPLETED,
            SetupStep::DEPARTMENTS->value => SetupStepState::SKIPPED,
            SetupStep::EMPLOYEES->value => SetupStepState::SKIPPED,
            SetupStep::LICENSE->value => SetupStepState::SKIPPED,
            SetupStep::KIOSK->value => SetupStepState::SKIPPED,
        ],
        hasAdministrator: true,
        hasSite: true,
        completedAt: null,
    );

    expect($state->unresolvedSteps())->toBe([]);
})->group('RF-PD-03');

it('sigue abierto hasta que alguien lo cierra, aunque no quede nada por hacer', function (): void {
    // No se cierra solo: si lo hiciera, el panel saltaria a la pantalla de acceso
    // justo antes de enseñar el resumen final, que es la unica oportunidad de
    // decir cuantas tarjetas quedan por imprimir.
    $state = SetupState::of(
        [
            SetupStep::ORGANISATION->value => SetupStepState::COMPLETED,
            SetupStep::COMPLIANCE_PROFILE->value => SetupStepState::COMPLETED,
            SetupStep::DEPARTMENTS->value => SetupStepState::SKIPPED,
            SetupStep::EMPLOYEES->value => SetupStepState::SKIPPED,
            SetupStep::LICENSE->value => SetupStepState::SKIPPED,
            SetupStep::KIOSK->value => SetupStepState::SKIPPED,
        ],
        hasAdministrator: true,
        hasSite: true,
        completedAt: null,
    );

    expect($state->unresolvedSteps())->toBe([])
        ->and($state->isAvailable())->toBeTrue();

    $cerrado = SetupState::of([], hasAdministrator: true, hasSite: true, completedAt: new DateTimeImmutable('2026-09-02T09:14:00Z'));

    expect($cerrado->isAvailable())->toBeFalse();
})->group('RF-PD-03');

it('reparte obligatoriedad y omisibilidad como manda el requisito', function (): void {
    // La licencia es omitible por la regla dura 15; el perfil de convenio no lo
    // es por RL-21. Los dos derivados son obligatorios y no se marcan a mano.
    expect(SetupStep::LICENSE->isSkippable())->toBeTrue()
        ->and(SetupStep::KIOSK->isSkippable())->toBeTrue()
        ->and(SetupStep::EMPLOYEES->isSkippable())->toBeTrue()
        ->and(SetupStep::DEPARTMENTS->isSkippable())->toBeTrue()
        ->and(SetupStep::COMPLIANCE_PROFILE->isSkippable())->toBeFalse()
        ->and(SetupStep::ORGANISATION->isSkippable())->toBeFalse()
        ->and(SetupStep::ADMINISTRATOR->isDerived())->toBeTrue()
        ->and(SetupStep::SITE->isDerived())->toBeTrue()
        ->and(SetupStep::LICENSE->isDerived())->toBeFalse();
})->group('RF-PD-03', 'RL-21');

it('no confunde la clave de cierre con un paso', function (): void {
    // `completion` comparte tabla con los pasos y NO es uno: no esta en el enum,
    // no viaja en el contrato y no aparece en `steps`.
    expect(SetupStep::tryFrom(SetupStep::COMPLETION_KEY))->toBeNull();
})->group('RF-PD-03');
