<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\Exception\InvalidLegalExportRequest;
use App\Modules\Compliance\Domain\ValueObject\ExportedDuration;
use App\Modules\Compliance\Domain\ValueObject\ExportedMarks;
use App\Modules\Compliance\Domain\ValueObject\ExportedSubject;
use App\Modules\Compliance\Domain\ValueObject\LegalExportManifest;
use App\Modules\Compliance\Domain\ValueObject\LegalExportPeriod;
use App\Modules\Compliance\Domain\ValueObject\LegalExportScope;
use App\Modules\Compliance\Domain\ValueObject\LegalExportTally;

/*
 * La forma de lo que se entrega a la Inspeccion (RF-IN-05, RL-06, tarea 1.17).
 *
 * Nivel unitario porque son reglas de escritura del documento, no de esquema ni
 * de endpoint: se comprueban sin base de datos y sin framework (§9.5, fila
 * «regla de negocio»).
 *
 * La que manda es la primera: **`HH:MM`, nunca decimal**. «7,5 horas» obliga a
 * interpretar —siete y media, o siete y cinco minutos— y ademas cambia de
 * significado segun la configuracion regional del programa que abra el fichero.
 * En una inspeccion, eso es una discusion; `07:30` no se interpreta.
 */

it('escribe cualquier duracion como HH:MM y jamas en decimal', function (int $minutes, string $expected): void {
    $written = ExportedDuration::ofMinutes($minutes)->toClockText();

    expect($written)->toBe($expected)
        // La afirmacion de verdad: ni coma ni punto decimal en ninguna celda de
        // duracion, sea cual sea el valor.
        ->and($written)->not->toContain(',')
        ->and($written)->not->toContain('.')
        ->and($written)->toMatch('/^\d{2,}:\d{2}$/');
})->with([
    'jornada de ocho horas' => [480, '08:00'],
    'la que se escribiria 7,5 en decimal' => [450, '07:30'],
    'la que se escribiria 7,05 en decimal' => [423, '07:03'],
    'menos de una hora' => [45, '00:45'],
    'un solo minuto' => [1, '00:01'],
    'cero minutos trabajados, que no es lo mismo que no constar' => [0, '00:00'],
    // No es una hora del reloj: es una cantidad de trabajo. El total mensual de
    // una persona son ciento sesenta y tantas horas y se escribe tal cual.
    'un mes completo, que pasa de 24 horas' => [10_080, '168:00'],
    'un turno que cruza la medianoche' => [480, '08:00'],
])->group('RF-IN-05', 'RL-06');

it('distingue no constar de haber trabajado cero', function (): void {
    // Un tramo abierto —alguien que todavia esta trabajando— no tiene duracion,
    // y no tiene cero: escribir `00:00` afirmaria que no trabajo nada.
    expect(ExportedDuration::absent()->toClockText())->toBe('')
        ->and(ExportedDuration::ofMinutes(0)->toClockText())->toBe('00:00')
        ->and(ExportedDuration::ofNullableMinutes(null)->toClockText())->toBe('')
        ->and(ExportedDuration::ofNullableMinutes(0)->toClockText())->toBe('00:00');
})->group('RF-IN-05', 'RL-06');

it('no acepta una duracion negativa', function (): void {
    // Una celda con «-01:30» en un registro horario no significa nada, y llegar
    // hasta el fichero significaria que la consulta que la produjo esta mal.
    expect(static fn (): ExportedDuration => ExportedDuration::ofMinutes(-1))
        ->toThrow(InvalidLegalExportRequest::class);
})->group('RF-IN-05');

it('escribe la version de antes y la de despues de una correccion en una celda', function (): void {
    // Lo que un inspector compara es una linea con otra. Las horas van en `HH:MM`
    // tambien aqui.
    $marks = ExportedMarks::of('2026-03-14 06:00', '2026-03-14 15:00', ExportedDuration::ofMinutes(540));

    expect($marks->describe())->toBe('2026-03-14 06:00 → 2026-03-14 15:00 (09:00)');
})->group('RF-IN-05', 'RL-04');

it('dice «no habia» en lugar de dejar la celda en blanco', function (): void {
    // En un alta no hay version anterior y en una anulacion no hay posterior.
    // Una celda vacia se lee «no lo se»; el guion largo dice «no habia».
    expect(ExportedMarks::none()->describe())->toBe('—');
})->group('RF-IN-05', 'RL-04');

it('deja a la vista que un tramo sigue abierto', function (): void {
    $marks = ExportedMarks::of('2026-03-14 22:00', null, ExportedDuration::absent());

    expect($marks->describe())->toBe('2026-03-14 22:00 → — (—)');
})->group('RF-IN-05');

it('acepta un periodo por fecha de jornada y rechaza el que termina antes de empezar', function (): void {
    $period = LegalExportPeriod::between('2026-01-01', '2026-01-31');

    expect($period->from)->toBe('2026-01-01')
        ->and($period->to)->toBe('2026-01-31')
        ->and($period->slug())->toBe('2026-01-01_2026-01-31');

    // No se «arregla» dando la vuelta a las fechas: el fichero que acabaria en
    // un expediente llevaria escrito un periodo que nadie pidio.
    expect(static fn (): LegalExportPeriod => LegalExportPeriod::between('2026-01-31', '2026-01-01'))
        ->toThrow(InvalidLegalExportRequest::class);
})->group('RF-IN-05', 'RL-06');

it('rechaza una fecha con la forma correcta que no existe', function (string $value): void {
    expect(static fn (): LegalExportPeriod => LegalExportPeriod::between($value, '2026-12-31'))
        ->toThrow(InvalidLegalExportRequest::class);
})->with([
    'el 31 de febrero' => '2026-02-31',
    'con hora, que la convertiria en un instante' => '2026-01-01T00:00:00Z',
    'en formato español' => '01/01/2026',
    'vacia' => '',
])->group('RF-IN-05');

it('no pone el nombre de nadie en el nombre del fichero', function (): void {
    // Un adjunto llamado «registro-Lucia-Fernandez.csv» divulga a quien se esta
    // inspeccionando con solo mirar la bandeja de entrada (regla dura 21).
    $manifest = new LegalExportManifest(
        generatedAt: new DateTimeImmutable('2026-02-01T09:00:00+00:00'),
        period: LegalExportPeriod::between('2026-01-01', '2026-01-31'),
        scope: LegalExportScope::employee('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90'),
    );

    expect($manifest->filename())->toBe('registro-horario-2026-01-01_2026-01-31.csv')
        ->and($manifest->filename())->not->toContain('0199f0c2');
})->group('RF-IN-05', 'RS-05');

it('etiqueta la metrica con el alcance y nunca con un identificador', function (): void {
    // Un UUID en una etiqueta de Prometheus crea una serie temporal por persona
    // exportada: fuga y explosion de cardinalidad a la vez.
    expect(LegalExportScope::everyone()->metricLabel())->toBe('all')
        ->and(LegalExportScope::employee('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90')->metricLabel())->toBe('employee');
})->group('RF-IN-05', 'RS-05');

it('cuenta las filas de datos sin contar la cabecera', function (): void {
    $tally = LegalExportTally::of(shiftEntries: 1_240, corrections: 17, employees: 63);

    expect($tally->rows())->toBe(1_257)
        ->and(LegalExportTally::empty()->rows())->toBe(0);
})->group('RF-IN-05', 'RS-05');

it('ordena a la persona como se lee una lista de personal', function (): void {
    $subject = new ExportedSubject(
        employeeCode: 'E0001',
        lastName: 'Fernandez Ruiz',
        firstName: 'Lucia',
        employeeUuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        siteName: 'Hotel de pruebas',
        departmentName: null,
        timezone: 'Europe/Madrid',
        workDate: '2026-01-14',
    );

    expect($subject->fullName())->toBe('Fernandez Ruiz, Lucia');
})->group('RF-IN-05');
