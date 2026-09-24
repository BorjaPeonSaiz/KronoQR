<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\Policy\AbsenteeismRule;

/*
 * La definicion canonica de los tres contadores de absentismo del informe por
 * periodo (**RF-GP-04**, decision 7 de la ficha 3.10).
 *
 * ## Por que esto se prueba aparte del informe
 *
 * Porque es el **enunciado**, y el SQL de `DatabasePeriodReportReader` es su
 * traduccion. Una prueba de integracion compara las dos sobre el mismo caso
 * (`PeriodReportAbsenteeismTest`); esta comprueba que el enunciado dice lo que
 * tiene que decir, sin base de datos y con las dieciseis combinaciones posibles
 * de los cuatro booleanos delante.
 *
 * ## Sin `Workforce` y sin `Absence`
 *
 * `Reporting` no ve ese modulo (doc 02 §1.6) y Deptrac lo verifica, asi que la
 * ausencia de tres dias del ultimo caso se modela con sus dos fechas —`starts_on`
 * y `ends_on`, que es exactamente lo que guarda la tabla— y se pregunta por cada
 * dia. Es tambien como lo hace el informe.
 */

it('cuenta como dia de ausencia el de quien esta de alta y tiene una ausencia activa', function (): void {
    // Tenga o no actividad: una ausencia registrada es un hecho de RRHH, y el
    // informe lo dice en vez de corregirlo. Y tenga o no festivo: ahi gana la
    // ausencia, que es lo que hace disjuntos los dos contadores.
    expect(AbsenteeismRule::isAbsenceDay(employed: true, coveredByAbsence: true))->toBeTrue();

    // De baja pero fuera de la relacion laboral: no es una ausencia de nadie, es
    // un dia en el que esa persona no trabajaba aqui.
    expect(AbsenteeismRule::isAbsenceDay(employed: false, coveredByAbsence: true))->toBeFalse();

    expect(AbsenteeismRule::isAbsenceDay(employed: true, coveredByAbsence: false))->toBeFalse();
})->group('RF-GP-04');

it('cuenta como festivo solo el que no esta ya cubierto por una ausencia', function (): void {
    expect(AbsenteeismRule::isHolidayDay(employed: true, holiday: true, coveredByAbsence: false))->toBeTrue();

    // EL CASO QUE HACE DISJUNTOS LOS DOS CONTADORES. Si el mismo dia sumara en
    // `absence_days` y en `holiday_days`, quien los sumara para restarlos del
    // periodo contaria un dia de mas.
    expect(AbsenteeismRule::isHolidayDay(employed: true, holiday: true, coveredByAbsence: true))->toBeFalse();

    expect(AbsenteeismRule::isHolidayDay(employed: false, holiday: true, coveredByAbsence: false))->toBeFalse();
    expect(AbsenteeismRule::isHolidayDay(employed: true, holiday: false, coveredByAbsence: false))->toBeFalse();
})->group('RF-GP-04');

it('solo cuenta como no justificado el dia de alta, sin actividad, sin ausencia y sin festivo', function (
    bool $employed,
    bool $hasActivity,
    bool $coveredByAbsence,
    bool $holiday,
    bool $esperado,
): void {
    // Las dieciseis combinaciones de los cuatro booleanos, enumeradas a mano y
    // no generadas: lo que se afirma es el ENUNCIADO, y un generador que
    // recompusiera la condicion seria una segunda copia de la regla probandose a
    // si misma.
    expect(AbsenteeismRule::isUnjustifiedAbsenceDay($employed, $hasActivity, $coveredByAbsence, $holiday))
        ->toBe($esperado);
})->with([
    // El unico `true` de los dieciseis.
    'de alta, sin nada' => [true, false, false, false, true],

    // Con actividad: fichó, luego no falto.
    'de alta, trabajó' => [true, true, false, false, false],
    'de alta, trabajó en festivo' => [true, true, false, true, false],
    'de alta, trabajó estando de baja' => [true, true, true, false, false],
    'de alta, trabajó en festivo estando de baja' => [true, true, true, true, false],

    // Justificado por ausencia, por festivo o por los dos.
    'de alta, de baja' => [true, false, true, false, false],
    'de alta, festivo' => [true, false, false, true, false],
    'de alta, de baja en festivo' => [true, false, true, true, false],

    // Fuera de la relacion laboral: ninguno cuenta, haya lo que haya.
    'sin alta, sin nada' => [false, false, false, false, false],
    'sin alta, trabajó' => [false, true, false, false, false],
    'sin alta, trabajó en festivo' => [false, true, false, true, false],
    'sin alta, trabajó estando de baja' => [false, true, true, false, false],
    'sin alta, trabajó en festivo estando de baja' => [false, true, true, true, false],
    'sin alta, de baja' => [false, false, true, false, false],
    'sin alta, festivo' => [false, false, false, true, false],
    'sin alta, de baja en festivo' => [false, false, true, true, false],
])->group('RF-GP-04');

it('deja los tres contadores disjuntos donde tienen que serlo', function (): void {
    // Un dia de alta cae como mucho en UNO de `absence` y `holiday`, y si cae en
    // alguno no puede caer en `unjustified`. La propiedad se comprueba sobre las
    // dieciseis combinaciones porque es lo que permite sumar las columnas sin
    // contar dos veces.
    foreach ([true, false] as $employed) {
        foreach ([true, false] as $hasActivity) {
            foreach ([true, false] as $absence) {
                foreach ([true, false] as $holiday) {
                    $counters = AbsenteeismRule::classify($employed, $hasActivity, $absence, $holiday);

                    $ciertos = \count(array_filter($counters));

                    expect($ciertos)->toBeLessThanOrEqual(1);
                }
            }
        }
    }
})->group('RF-GP-04');

it('devuelve los tres contadores del dia, con su nombre y en un solo paso', function (
    bool $employed,
    bool $hasActivity,
    bool $coveredByAbsence,
    bool $holiday,
    bool $absence,
    bool $holidayCounter,
    bool $unjustified,
): void {
    /*
     * `classify()` ES LA PUERTA QUE USA EL INFORME, y hasta el cierre de la
     * Fase 3 ninguna prueba miraba lo que devuelve: la de arriba solo contaba
     * cuantas claves salen ciertas, y «como mucho una» sigue siendo verdad con
     * una clave de menos —o con el array vacio—.
     *
     * Se midio: borrar `'absence'`, `'holiday'` o `'unjustified'` del array, o
     * devolver `[]` entero, no rompia nada (cuatro mutantes vivos en las lineas
     * 134-137). El informe se quedaria sin una columna y el unico sintoma seria
     * un cero en la pantalla del hotel.
     *
     * Por eso se compara el array COMPLETO y con sus claves, no clave a clave: es
     * la unica forma de que una que falte cuente como fallo.
     */

    // arrange / act
    $counters = AbsenteeismRule::classify($employed, $hasActivity, $coveredByAbsence, $holiday);

    // assert
    expect($counters)->toBe([
        'absence' => $absence,
        'holiday' => $holidayCounter,
        'unjustified' => $unjustified,
    ]);
})->with([
    // Un caso por columna, mas el dia en que ninguna cuenta y el dia de quien ya
    // no esta de alta.
    'de alta con ausencia activa' => [true, false, true, false, true, false, false],
    'de alta en festivo sin ausencia' => [true, false, false, true, false, true, false],
    'de alta, sin actividad, sin ausencia y sin festivo' => [true, false, false, false, false, false, true],
    'de alta y trabajando' => [true, true, false, false, false, false, false],
    'ausencia que cae en festivo: gana la ausencia' => [true, false, true, true, true, false, false],
    'sin alta no cuenta ninguna' => [false, false, true, true, false, false, false],
])->group('RF-GP-04');

it('no cuenta como absentismo un dia cubierto por una ausencia, y si el anterior y el posterior', function (): void {
    /*
     * EL CASO DE LA FICHA, LITERAL: «un empleado con una baja de tres dias no
     * aparece como ausente injustificado ninguno de esos tres, ni el anterior ni
     * el siguiente cambian de estado».
     *
     * La ausencia se modela con sus dos fechas, que es lo que guarda la tabla
     * `absences`: `starts_on` y `ends_on`, **inclusivas en los dos extremos**. No
     * se importa `Absence` de `Workforce` porque `Reporting` no ve ese modulo
     * (doc 02 §1.6) y Deptrac lo verifica.
     */
    $startsOn = '2026-03-10';
    $endsOn = '2026-03-12';

    // Cinco dias: el de antes, los tres de la baja y el de despues. Nadie fichó
    // ninguno de los cinco y ninguno es festivo, asi que lo unico que decide es
    // la ausencia.
    $dias = ['2026-03-09', '2026-03-10', '2026-03-11', '2026-03-12', '2026-03-13'];

    $noJustificados = [];
    $deAusencia = [];

    foreach ($dias as $dia) {
        $cubierto = AbsenteeismRule::covers($startsOn, $endsOn, $dia);

        if (AbsenteeismRule::isUnjustifiedAbsenceDay(true, false, $cubierto, false)) {
            $noJustificados[] = $dia;
        }

        if (AbsenteeismRule::isAbsenceDay(true, $cubierto)) {
            $deAusencia[] = $dia;
        }
    }

    // Los tres de la baja, incluidos el primero y el ultimo: los extremos son
    // parte de la ausencia, no su frontera abierta.
    expect($deAusencia)->toBe(['2026-03-10', '2026-03-11', '2026-03-12']);

    // Y el anterior y el posterior no cambian de estado por que exista la baja.
    expect($noJustificados)->toBe(['2026-03-09', '2026-03-13']);
})->group('RF-GP-04');

it('cubre los dos extremos de la ausencia y ni un dia mas', function (): void {
    expect(AbsenteeismRule::covers('2026-03-10', '2026-03-12', '2026-03-10'))->toBeTrue();
    expect(AbsenteeismRule::covers('2026-03-10', '2026-03-12', '2026-03-12'))->toBeTrue();

    expect(AbsenteeismRule::covers('2026-03-10', '2026-03-12', '2026-03-09'))->toBeFalse();
    expect(AbsenteeismRule::covers('2026-03-10', '2026-03-12', '2026-03-13'))->toBeFalse();

    // Una ausencia de un solo dia cubre ese dia. Con un intervalo semiabierto
    // —el error clasico— no cubriria ninguno.
    expect(AbsenteeismRule::covers('2026-03-10', '2026-03-10', '2026-03-10'))->toBeTrue();

    // Cambio de mes y de año, que es donde falla una comparacion que no sea ISO.
    expect(AbsenteeismRule::covers('2026-12-30', '2027-01-02', '2027-01-01'))->toBeTrue();
    expect(AbsenteeismRule::covers('2026-12-30', '2027-01-02', '2026-12-29'))->toBeFalse();
})->group('RF-GP-04');
