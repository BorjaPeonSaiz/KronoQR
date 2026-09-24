<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\ValueObject\AdoptionIndicator;
use App\Modules\Reporting\Domain\ValueObject\AdoptionIndicatorKey;
use App\Modules\Reporting\Domain\ValueObject\AdoptionTarget;
use App\Modules\Reporting\Domain\ValueObject\AdoptionTargetComparison;

/*
 * **Donde cae la linea de cada objetivo del §1.3** (RF-IN-08, RNF-D-01, tarea 3.13).
 *
 * ## Por que un fichero propio y no mas casos en `AdoptionIndicatorsTest`
 *
 * Alli se comprueba **el cuadro**: que cada indicador sale con su objetivo, su
 * comparacion y su estado. Aqui se comprueba **el juicio**, que es una pieza
 * distinta y con una propiedad que no se ve desde arriba: los tres desenlaces de
 * `isMetBy()` son `true`, `false` y **`null`**, y el `null` no significa «no
 * cumple» sino «no se puede decir». Un cuadro que confundiera los dos publicaria
 * «Fuera del objetivo» sobre un mes sin datos, que es acusar a alguien de un
 * incumplimiento inventado.
 *
 * Y una razon de herramienta, diagnosticada el 24-09-2026 en el cierre de la Fase 3:
 * en la maquina de desarrollo (Windows, bind mount de Docker Desktop) `make mutate`
 * no creaba **ningun** mutante para `AdoptionTarget` ni `AdoptionIndicator`, y sin
 * `--covered-only` los 22 salian «uncovered». No es que la mutacion atribuya por
 * nombre: es que el bind mount no enumera estos ficheros al recorrer `app/`
 * (`SourceDiscoveryTest` los lista), la cobertura de PHPUnit los filtra fuera del
 * informe y Pest los da por no cubiertos. En una copia del backend dentro del
 * contenedor, y en la CI, los mismos mutantes salen y esta prueba los mata. Justo
 * ahi vive el `>=` frente al `>` que el doc 02 §9.3 pone como ejemplo para
 * justificar el umbral de MSI: por eso las cifras de mutacion se leen de la CI.
 */

it('da por cumplido un objetivo de minimo exactamente en el umbral', function (
    float $value,
    bool $cumple,
): void {
    // «Al menos el 99 %»: 99,00 cumple y 98,99 no. El borde se prueba porque un `>`
    // en lugar de un `>=` no se nota en ningun otro caso, y dejaria en rojo el mes
    // que da el objetivo clavado.
    expect(AdoptionTarget::atLeast(99.0)->isMetBy($value))->toBe($cumple);
})->with([
    'el umbral clavado' => [99.0, true],
    'un centesimo por debajo' => [98.99, false],
    'por encima' => [99.4, true],
])->group('RF-IN-08');

it('deja fuera de un objetivo de maximo el valor que cae en el limite', function (
    float $value,
    bool $cumple,
): void {
    // «Menos del 2 %» (`reports.adoption.target.at_most`): el §1.3 lo escribe con
    // `<`, asi que **el limite no cumple**. El rotulo del documento y el juicio
    // tienen que decir lo mismo, o el papel se contradice en la misma fila.
    expect(AdoptionTarget::atMost(2.0)->isMetBy($value))->toBe($cumple);
})->with([
    'el limite clavado' => [2.0, false],
    'un centesimo por debajo' => [1.99, true],
    'por encima' => [2.4, false],
])->group('RF-IN-08');

it('no juzga el objetivo de disponibilidad con un decimal menos', function (): void {
    /*
     * RNF-D-01, «≥ 99,9 %». Los dos valores son los que separan cumplir de no
     * cumplir con DOS decimales: 99,86 no llega y 99,94 si. Si el cuadro redondeara a
     * un decimal, los dos serian «99,9 %» y el objetivo dejaria de poder juzgarse —
     * que es el motivo por el que `AdoptionIndicators` trabaja con dos.
     */
    $target = AdoptionTarget::atLeast(99.9);

    expect($target->isMetBy(99.94))->toBeTrue()
        ->and($target->isMetBy(99.86))->toBeFalse();
})->group('RF-IN-08', 'RNF-D-01');

it('no juzga nada cuando el indicador no tiene valor', function (
    AdoptionTarget $target,
): void {
    /*
     * **`null` no es «no cumple»**, y es la distincion que sostiene todo el cuadro:
     * un periodo sin jornadas no incumplio el 99 % de registro completo, no tuvo
     * jornadas. Con un `false` ahi, la pantalla con la que se decide si el sistema se
     * renueva enseñaria un incumplimiento que nadie cometio.
     */
    expect($target->isMetBy(null))->toBeNull();
})->with([
    'minimo' => [AdoptionTarget::atLeast(99.0)],
    'maximo' => [AdoptionTarget::atMost(2.0)],
    'reduccion' => [AdoptionTarget::reductionOf(80.0)],
])->group('RF-IN-08');

it('no juzga la reduccion sobre la linea base, ni con valor', function (): void {
    /*
     * El «−80 % en horas consolidando hojas de horas» del §1.3 se presenta como
     * referencia y no se juzga: el producto **no puede** medir si se consiguio,
     * porque las hojas ya no se consolidan a mano —que es justamente el punto—. Lo
     * unico que el sistema sabe es la linea base que el cliente declaro.
     *
     * Se afirma **con un valor dentro**, que es lo que distingue «no se juzga» de «no
     * hay dato»: con `null` las dos cosas darian el mismo resultado.
     */
    $target = AdoptionTarget::reductionOf(80.0);

    expect($target->comparison)->toBe(AdoptionTargetComparison::Reduction)
        ->and($target->comparison->judgesCompliance())->toBeFalse()
        ->and($target->isMetBy(2400.0))->toBeNull();
})->group('RF-IN-08');

it('conserva la cifra del objetivo tal y como la escribe el §1.3', function (
    AdoptionTarget $target,
    AdoptionTargetComparison $comparison,
    float $value,
): void {
    // El objetivo viaja dentro del cuadro y entra en la huella del documento
    // exportado: dos cuadros con el mismo 98,60 % y objetivos distintos no dicen lo
    // mismo, asi que la cifra no puede transformarse por el camino.
    expect($target->comparison)->toBe($comparison)
        ->and($target->value)->toBe($value);
})->with([
    'jornadas completas' => [AdoptionTarget::atLeast(99.0), AdoptionTargetComparison::AtLeast, 99.0],
    'fichajes por QR' => [AdoptionTarget::atLeast(98.0), AdoptionTargetComparison::AtLeast, 98.0],
    'disponibilidad' => [AdoptionTarget::atLeast(99.9), AdoptionTargetComparison::AtLeast, 99.9],
    'correcciones' => [AdoptionTarget::atMost(2.0), AdoptionTargetComparison::AtMost, 2.0],
    'resolucion en 24 h' => [AdoptionTarget::atMost(1440.0), AdoptionTargetComparison::AtMost, 1440.0],
    'linea base' => [AdoptionTarget::reductionOf(80.0), AdoptionTargetComparison::Reduction, 80.0],
])->group('RF-IN-08');

it('un indicador sin objetivo no juzga nada, ni con valor', function (): void {
    /*
     * Seis de los doce indicadores no tienen objetivo en el §1.3 (minutos trabajados
     * y contratados, las dos fotos, la mediana y los resueltos sin servidor):
     * `meetsTarget()` tiene que decir «no se puede decir» y no reventar contra un
     * objetivo que no existe. Es el mutante que el `?->` protege: sin esta prueba,
     * cambiarlo por `->` sobrevive porque nadie pregunta por el estado de un
     * indicador sin objetivo.
     */
    $indicator = AdoptionIndicator::of(AdoptionIndicatorKey::WorkedMinutes, 9600.0, 8400.0);

    expect($indicator->target)->toBeNull()
        ->and($indicator->meetsTarget())->toBeNull();
})->group('RF-IN-08');
