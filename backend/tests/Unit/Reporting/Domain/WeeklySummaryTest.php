<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\ValueObject\ContractCoverage;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Reporting\Domain\ValueObject\IsoWeek;
use App\Modules\Reporting\Domain\ValueObject\PeriodReport;
use App\Modules\Reporting\Domain\ValueObject\PeriodReportRow;
use App\Modules\Reporting\Domain\ValueObject\ReportGranularity;
use App\Modules\Reporting\Domain\ValueObject\ReportGrouping;
use App\Modules\Reporting\Domain\ValueObject\ReportSubject;
use App\Modules\Reporting\Domain\ValueObject\WeeklySummary;

/*
 * La composicion del resumen semanal (**RF-PR-05**, decision 3 de la ficha
 * 3.12).
 *
 * ## Lo que se defiende aqui
 *
 * **Que el recorte del correo no toca los totales.** Un correo con doscientas
 * lineas no lo lee nadie, asi que se detallan cincuenta y del resto se dice
 * cuantas son; pero si los totales se calcularan sobre lo detallado, el correo
 * daria una cifra falsa con aspecto de cifra buena —y quien la lea no tiene
 * forma de notarlo—. Es el fallo mas caro que puede tener esta pantalla, porque
 * no se parece a un fallo.
 *
 * Nada de lo que hay aqui recalcula horas: las cifras salen de `daily_totals` a
 * traves del informe por periodo (regla dura 7).
 */

function filaDeResumen(string $name, int $worked, int $contracted): PeriodReportRow
{
    return new PeriodReportRow(
        subject: ReportSubject::employee(
            '0199a0c0-0000-7000-8000-'.mb_substr(md5($name), 0, 12),
            'E'.mb_substr(md5($name), 0, 6),
            $name,
            7,
            'Cocina',
        ),
        periodStart: new DateTimeImmutable('2026-09-14'),
        periodEnd: new DateTimeImmutable('2026-09-20'),
        workedMinutes: $worked,
        shiftCount: 5,
        daysInPeriod: 7,
        daysWithActivity: 5,
        openShiftDays: 0,
        incidentDays: 0,
        contractedMinutes: $contracted,
        daysWithoutContract: 0,
        absenceDays: 0,
        holidayDays: 0,
        unjustifiedAbsenceDays: 0,
    );
}

/**
 * @param  list<PeriodReportRow>  $rows
 */
function resumenSemanal(array $rows, int $openIncidents = 0): WeeklySummary
{
    return new WeeklySummary(
        IsoWeek::fromLabel('2026-W38'),
        new PeriodReport(
            rows: $rows,
            range: DateRange::between('2026-09-14', '2026-09-20'),
            granularity: ReportGranularity::Range,
            grouping: ReportGrouping::Employee,
            timeZone: 'Europe/Madrid',
            generatedAt: new DateTimeImmutable('2026-09-21T06:00:00+00:00'),
            criteria: [],
            contractCoverage: new ContractCoverage(0, 0),
        ),
        $openIncidents,
    );
}

/**
 * @return list<PeriodReportRow>
 */
function filasDeResumen(int $count): array
{
    $rows = [];

    for ($i = 0; $i < $count; $i++) {
        // Sesenta minutos por persona: el total es entonces un numero que se
        // puede comprobar a ojo y un recorte mal hecho salta a la vista.
        $rows[] = filaDeResumen('Persona '.$i, 60, 60);
    }

    return $rows;
}

it('detalla como mucho cincuenta lineas y dice cuantas quedan', function (): void {
    $summary = resumenSemanal(filasDeResumen(63));

    expect($summary->detailedRows())->toHaveCount(WeeklySummary::MAXIMUM_DETAILED_LINES)
        ->and($summary->undetailedRows())->toBe(13)
        // El tope es el mismo que el de la lista de afectados del asiento de
        // `audit_log`, y no es casualidad: lo que se detalla en el correo es lo
        // que el trail enumera como divulgado.
        ->and(WeeklySummary::MAXIMUM_DETAILED_LINES)->toBe(50);
})->group('RF-PR-05');

it('no recorta nada cuando el alcance cabe entero', function (int $people): void {
    // Los dos bordes: justo en el tope y justo por debajo. El segundo no es
    // redundante —«y N personas mas» con N negativo es una frase que nadie
    // escribe a proposito, y con 49 filas es el unico caso que la produce—.
    $summary = resumenSemanal(filasDeResumen($people));

    expect($summary->detailedRows())->toHaveCount($people)
        ->and($summary->undetailedRows())->toBe(0);
})->with([
    'justo en el tope' => [50],
    'una por debajo del tope' => [49],
    'un departamento pequeño' => [3],
])->group('RF-PR-05');

it('suma los totales sobre TODAS las filas y no sobre las detalladas', function (): void {
    // EL FALLO QUE ESTA PRUEBA FIJA. Con los totales calculados sobre
    // `detailedRows()`, un departamento de 63 personas recibiria «total: 50:00»
    // en lugar de «63:00» y nadie lo notaria: las dos cifras son plausibles.
    $summary = resumenSemanal(filasDeResumen(63));

    expect($summary->workedMinutes())->toBe(63 * 60)
        ->and($summary->contractedMinutes())->toBe(63 * 60)
        ->and($summary->employeeCount())->toBe(63);
})->group('RF-PR-05');

it('lleva la desviacion con signo y no la llama exceso', function (): void {
    // `deviationMinutes()` es `trabajado - contratado`, con signo: trabajar de
    // menos no es un exceso negativo. Y el correo no la llama «horas extra»:
    // eso lo decide el convenio, con compensaciones y periodos de referencia que
    // el producto no modela.
    $summary = resumenSemanal([
        filaDeResumen('Ana', 2400, 2100),
        filaDeResumen('Luis', 1800, 2100),
    ]);

    expect($summary->deviationMinutes())->toBe(0)
        ->and($summary->workedMinutes())->toBe(4200);

    $enDefecto = resumenSemanal([filaDeResumen('Luis', 1800, 2100)]);

    expect($enDefecto->deviationMinutes())->toBe(-300);
})->group('RF-PR-05');

it('sabe que una semana sin nadie en el alcance esta vacia', function (): void {
    // No es un error: puede ser un departamento recien creado. El correo lo dice
    // en lugar de mandar una tabla vacia, que parece una averia.
    $summary = resumenSemanal([], openIncidents: 2);

    expect($summary->isEmpty())->toBeTrue()
        ->and($summary->employeeCount())->toBe(0)
        ->and($summary->workedMinutes())->toBe(0)
        // Y el recuento de la bandeja llega igualmente: una semana sin fichajes
        // es justo cuando mas interesa saber que hay incidencias sin resolver.
        ->and($summary->openIncidents)->toBe(2);
})->group('RF-PR-05');
