<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Command\CorrectShiftCommand;
use App\Modules\Attendance\Application\Command\VoidShiftCommand;
use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Application\UseCase\CorrectShiftHandler;
use App\Modules\Attendance\Application\UseCase\VoidShiftHandler;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReason;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Compliance\Application\Command\GenerateLegalExportCommand;
use App\Modules\Compliance\Application\Port\LegalExportSource;
use App\Modules\Compliance\Application\UseCase\GenerateLegalExport;
use App\Modules\Compliance\Application\UseCase\LegalExport;
use App\Modules\Compliance\Domain\ValueObject\LegalExportPeriod;
use App\Modules\Compliance\Domain\ValueObject\LegalExportScope;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\Instants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La exportacion para la Inspeccion, contra PostgreSQL de verdad y con volumen
 * (RF-IN-05, RL-03, RL-06, RL-04, tarea 1.17).
 *
 * **Por que integracion y no unitaria.** Lo que se comprueba aqui no es el
 * formato de una duracion —eso es
 * `tests/Unit/Compliance/Domain/LegalExportFormatTest.php`— sino la CONSULTA: el
 * `AT TIME ZONE` de la zona del centro, la funcion de ventana que numera los
 * tramos y suma la jornada, el `UNION` con `shift_corrections` y el cursor de
 * servidor. Nada de eso existe fuera de PostgreSQL, y una prueba con dobles
 * daria por buena una consulta que no funciona (doc 02 §9.5, fila «esquema o
 * restriccion»).
 *
 * **Con volumen, porque el fallo que importa solo aparece con volumen.** Lo que
 * el doc 02 §3.1 exige de una exportacion es que «no cargue en memoria un mes de
 * 500 empleados»: una prueba de tres filas pasaria igual con un `SELECT`
 * completo en memoria, y el fallo se descubriria el dia del requerimiento, con
 * el periodo mas grande y el servidor mas pequeño.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // El idioma del documento es configuracion de la instalacion (`APP_LOCALE`,
    // regla dura 13, ADR-017) y no una constante del programa. Se fija aqui para
    // que estas pruebas no dependan del `.env` de quien las ejecute; el ingles
    // tiene su propia prueba mas abajo.
    App::setLocale('es');
});

/**
 * Centro y autor de las correcciones.
 *
 * @return array{site: int, author: User}
 */
function contextoDeExportacionLegal(): array
{
    return [
        'site' => WorkforceFixtures::site('Hotel de Inspeccion', 'Europe/Madrid'),
        'author' => ManagementUsers::withRole(UserRole::RRHH),
    ];
}

function rutaDeExportacionLegal(): string
{
    return storage_path('framework/testing/legal-export-'.Str::random(10).'.csv');
}

/**
 * Tramos cerrados escritos directamente en la tabla.
 *
 * Se insertan con el constructor de consultas y no con el agregado a proposito:
 * lo que se ejercita aqui es la CONSULTA de la exportacion, y levantar
 * trescientos agregados para poblarla solo mediria el repositorio.
 *
 * @param  list<string>  $employeeUuids
 * @return int Tramos escritos.
 */
function tramosEnBloqueParaExportacion(int $siteId, array $employeeUuids, string $firstDate, int $days): int
{
    /** @var array<string, int> $ids */
    $ids = DB::table('employees')->whereIn('uuid', $employeeUuids)->pluck('id', 'uuid')->all();
    $zone = new DateTimeZone('Europe/Madrid');
    $utc = new DateTimeZone('UTC');
    $rows = [];

    foreach ($employeeUuids as $uuid) {
        for ($day = 0; $day < $days; $day++) {
            $workDate = (new DateTimeImmutable($firstDate, $zone))->modify('+'.$day.' days')->format('Y-m-d');
            $in = (new DateTimeImmutable($workDate.' 09:00', $zone))->setTimezone($utc);
            $out = $in->modify('+480 minutes');

            $rows[] = [
                'uuid' => Str::uuid7()->toString(),
                'employee_id' => $ids[$uuid],
                'site_id' => $siteId,
                'work_date' => $workDate,
                'clocked_in_at' => $in->format('Y-m-d H:i:sP'),
                'clocked_out_at' => $out->format('Y-m-d H:i:sP'),
                'duration_minutes' => 480,
                'status' => 'closed',
                'clock_in_source' => 'qr_kiosk',
                'clock_out_source' => 'qr_kiosk',
                'version' => 1,
                'created_at' => $out->format('Y-m-d H:i:sP'),
                'updated_at' => $out->format('Y-m-d H:i:sP'),
            ];
        }
    }

    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('shift_entries')->insert($chunk);
    }

    return count($rows);
}

/**
 * Un tramo real, por el agregado, para poder corregirlo despues con los casos de
 * uso de la tarea 1.15.
 *
 * Carga la jornada si ya existe: dos `WorkDay::start()` sobre el mismo dia
 * darian dos agregados que no se ven, y el segundo `save()` escribiria sobre un
 * estado que no conoce.
 */
function tramoDeJornadaExportable(int $siteId, string $employeeUuid, string $workDate, string $in, ?string $out): string
{
    $repository = app(WorkDayRepository::class);
    $date = WorkDate::fromIsoDate($workDate, Instants::madrid());

    $workDay = $repository->findWorkDayFor($employeeUuid, $date)
        ?? WorkDay::start($employeeUuid, $siteId, $date);

    $entry = $workDay->clockIn(Str::uuid7()->toString(), Instants::utc($in), ScanOrigin::QR_KIOSK);

    if ($out !== null) {
        $workDay->clockOut(Instants::utc($out), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    }

    $repository->save($workDay);

    return $entry->uuid();
}

function exportacionLegal(string $from, string $to, ?string $employeeUuid = null): LegalExport
{
    return app(GenerateLegalExport::class)->handle(new GenerateLegalExportCommand(
        period: LegalExportPeriod::between($from, $to),
        scope: $employeeUuid === null ? LegalExportScope::everyone() : LegalExportScope::employee($employeeUuid),
        destinationPath: rutaDeExportacionLegal(),
    ));
}

/**
 * Las filas de datos como mapas de columna -> valor, usando los rotulos de la
 * cabecera como claves. El bloque de criterios queda fuera: la tabla empieza en
 * la primera linea que tiene todas las columnas.
 *
 * @return list<array<string, string>>
 */
function filasDeExportacion(string $path): array
{
    $handle = fopen($path, 'rb');

    if ($handle === false) {
        return [];
    }

    /** @var list<string>|null $header */
    $header = null;
    $rows = [];

    while (($cells = fgetcsv($handle, 0, ';', '"', '')) !== false) {
        /** @var list<string> $cells */
        $cells = array_map(static fn (?string $cell): string => $cell ?? '', $cells);

        if ($header === null) {
            if (count($cells) > 20) {
                $header = $cells;
            }

            continue;
        }

        if (count($cells) === count($header)) {
            $rows[] = array_combine($header, $cells);
        }
    }

    fclose($handle);

    return $rows;
}

it('entrega el registro diario por trabajador y periodo, con volumen', function (): void {
    // Veinticinco personas por doce dias: trescientos tramos. No es «un mes de
    // 500 empleados», pero si suficiente para que el orden del fichero deje de
    // depender del plan de ejecucion y para que la escritura recorra varios
    // lotes del cursor.
    $contexto = contextoDeExportacionLegal();
    $employees = [];

    for ($i = 0; $i < 25; $i++) {
        $employees[] = WorkforceFixtures::employee($contexto['site']);
    }

    $escritos = tramosEnBloqueParaExportacion($contexto['site'], $employees, '2026-03-02', 12);

    $export = exportacionLegal('2026-03-02', '2026-03-13');

    expect($escritos)->toBe(300)
        ->and($export->tally->shiftEntries)->toBe(300)
        ->and($export->tally->corrections)->toBe(0)
        ->and($export->tally->employees)->toBe(25);

    $filas = filasDeExportacion($export->path);

    expect($filas)->toHaveCount(300);

    // Cada fila se sostiene sola: persona, jornada, entrada, salida, duracion y
    // total del dia. Un fichero en el que la persona solo apareciera en la
    // primera fila de su bloque se rompe en cuanto alguien ordena por otra
    // columna, y ordenar es lo primero que hace quien recibe una tabla.
    foreach ($filas as $fila) {
        expect($fila['Trabajador'])->not->toBe('')
            ->and($fila['Jornada'])->toMatch('/^2026-03-\d{2}$/')
            ->and($fila['Entrada (hora local)'])->toMatch('/^2026-03-\d{2} 09:00$/')
            ->and($fila['Salida (hora local)'])->toMatch('/^2026-03-\d{2} 17:00$/')
            ->and($fila['Duracion (HH:MM)'])->toBe('08:00')
            ->and($fila['Total de la jornada (HH:MM)'])->toBe('08:00')
            // Regla dura 3: la marca almacenada va ademas de la local, y en
            // marzo Madrid es UTC+1.
            ->and($fila['Entrada (UTC)'])->toMatch('/^2026-03-\d{2}T08:00:00Z$/');
    }

    // Orden garantizado y estable: por codigo de empleado y jornada. Es contrato
    // del puerto, no cosmetica: un documento que se entrega a la Inspeccion se
    // lee de arriba abajo.
    $claves = array_map(
        static fn (array $fila): string => $fila['Codigo de empleado'].$fila['Jornada'],
        $filas,
    );
    $ordenadas = $claves;
    sort($ordenadas);

    expect($claves)->toBe($ordenadas);
})->group('RF-IN-05', 'RL-06', 'RL-03');

it('incluye las correcciones con su autor, su fecha y su motivo', function (): void {
    // Lo que hace defendible este registro es que cada cambio consta. Un informe
    // que solo enseñara el resultado final seria indistinguible de uno reescrito
    // (RN-13, RL-04, regla dura 5).
    $contexto = contextoDeExportacionLegal();
    $employee = WorkforceFixtures::employee($contexto['site']);

    $abierto = tramoDeJornadaExportable($contexto['site'], $employee, '2026-03-10', '2026-03-10 06:00', null);

    $corregido = app(CorrectShiftHandler::class)->handle(new CorrectShiftCommand(
        shiftEntryUuid: $abierto,
        clockedInAt: null,
        clockedOutAt: Instants::utc('2026-03-10 14:00'),
        reason: CorrectionReason::fromCode('OLVIDO_FICHAJE_SALIDA'),
        performedByUserId: $contexto['author']->id,
    ));

    $export = exportacionLegal('2026-03-10', '2026-03-10', $employee);

    expect($export->tally->shiftEntries)->toBe(1)
        ->and($export->tally->corrections)->toBe(1);

    $filas = filasDeExportacion($export->path);
    $tramos = array_values(array_filter($filas, static fn (array $f): bool => $f['Tipo'] === 'TRAMO'));
    $correcciones = array_values(array_filter($filas, static fn (array $f): bool => $f['Tipo'] === 'CORRECCION'));

    // La version SUSTITUIDA no aparece como tramo: lo que decia esta en la
    // columna «Antes». Repetirla contaria dos veces el mismo trabajo.
    expect($tramos)->toHaveCount(1)
        ->and($tramos[0]['Identificador de tramo'])->toBe($corregido->shiftEntryUuid)
        ->and($tramos[0]['Total de la jornada (HH:MM)'])->toBe('08:00');

    expect($correcciones)->toHaveCount(1);
    $correccion = $correcciones[0];

    expect($correccion['Correccion: autor'])->toBe($contexto['author']->name)
        ->and($correccion['Correccion: identificador del autor'])->toBe($contexto['author']->uuid)
        // El codigo del Anexo C va sin traducir: tiene que leerse igual aqui,
        // en `shift_corrections` y en `audit_log`.
        ->and($correccion['Correccion: motivo'])->toBe('OLVIDO_FICHAJE_SALIDA')
        ->and($correccion['Correccion: accion'])->toBe('Cierre de tramo')
        ->and($correccion['Correccion: momento (hora local)'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/')
        ->and($correccion['Correccion: momento (UTC)'])->toMatch('/Z$/')
        // Antes: abierto, sin salida y sin duracion. Despues: cerrado a las
        // 15:00 locales con ocho horas.
        ->and($correccion['Correccion: antes'])->toContain('2026-03-10 07:00')
        ->and($correccion['Correccion: despues'])->toContain('(08:00)');
})->group('RF-IN-05', 'RL-06', 'RL-04');

it('enseña los tramos anulados sin sumarlos al total de la jornada', function (): void {
    // Nada se oculta (regla dura 5) y nada se cuenta dos veces (RN-06): el
    // escaneo duplicado figura con su estado y su motivo, y el dia sigue sumando
    // ocho horas.
    $contexto = contextoDeExportacionLegal();
    $employee = WorkforceFixtures::employee($contexto['site']);

    tramoDeJornadaExportable($contexto['site'], $employee, '2026-03-11', '2026-03-11 06:00', '2026-03-11 14:00');
    $duplicado = tramoDeJornadaExportable($contexto['site'], $employee, '2026-03-11', '2026-03-11 15:00', '2026-03-11 15:10');

    app(VoidShiftHandler::class)->handle(new VoidShiftCommand(
        shiftEntryUuid: $duplicado,
        reason: CorrectionReason::fromCode('ERROR_DE_ESCANEO_DUPLICADO'),
        performedByUserId: $contexto['author']->id,
    ));

    $export = exportacionLegal('2026-03-11', '2026-03-11', $employee);
    $filas = filasDeExportacion($export->path);
    $tramos = array_values(array_filter($filas, static fn (array $f): bool => $f['Tipo'] === 'TRAMO'));

    expect($tramos)->toHaveCount(2);

    $anulado = array_values(array_filter($tramos, static fn (array $f): bool => $f['Estado'] === 'Anulado'));

    expect($anulado)->toHaveCount(1)
        ->and($anulado[0]['Duracion (HH:MM)'])->toBe('00:10');

    foreach ($tramos as $tramo) {
        expect($tramo['Total de la jornada (HH:MM)'])->toBe('08:00');
    }
})->group('RF-IN-05', 'RL-06', 'RN-06');

it('escribe un turno de noche como un solo tramo en su jornada de inicio', function (): void {
    // Regla dura 4 y RN-05. Un 22:00 → 06:00 partido a medianoche daria dos
    // tramos de cuatro horas en dos dias distintos, y el total de un mes dejaria
    // de cuadrar con el del siguiente.
    $contexto = contextoDeExportacionLegal();
    $employee = WorkforceFixtures::employee($contexto['site']);

    tramoDeJornadaExportable($contexto['site'], $employee, '2026-03-12', '2026-03-12 21:00', '2026-03-13 05:00');

    // El periodo termina el 12: el turno entra entero aunque salga el 13.
    $export = exportacionLegal('2026-03-12', '2026-03-12', $employee);
    $filas = filasDeExportacion($export->path);

    expect($filas)->toHaveCount(1)
        ->and($filas[0]['Jornada'])->toBe('2026-03-12')
        ->and($filas[0]['Entrada (hora local)'])->toBe('2026-03-12 22:00')
        ->and($filas[0]['Salida (hora local)'])->toBe('2026-03-13 06:00')
        ->and($filas[0]['Duracion (HH:MM)'])->toBe('08:00');

    // Y no reaparece en el dia siguiente: si lo hiciera, dos exportaciones
    // consecutivas sumarian sus horas dos veces.
    expect(filasDeExportacion(exportacionLegal('2026-03-13', '2026-03-13', $employee)->path))->toBe([]);
})->group('RF-IN-05', 'RN-05', 'RL-06');

it('produce un CSV en UTF-8 con BOM y sin una sola hora decimal', function (): void {
    // RL-06: formato tabular legible y tratable. Las tres decisiones —BOM, punto
    // y coma y `HH:MM`— son las que hacen que el fichero se abra con acentos y
    // en columnas en Excel y en LibreOffice.
    $contexto = contextoDeExportacionLegal();
    $employee = WorkforceFixtures::employee($contexto['site']);

    tramoDeJornadaExportable($contexto['site'], $employee, '2026-03-14', '2026-03-14 05:30', '2026-03-14 13:00');

    $export = exportacionLegal('2026-03-14', '2026-03-14', $employee);
    $contents = (string) file_get_contents($export->path);

    expect(str_starts_with($contents, "\xEF\xBB\xBF"))->toBeTrue('El fichero no lleva marca de orden de bytes.')
        ->and(mb_check_encoding($contents, 'UTF-8'))->toBeTrue()
        // El fichero declara sus criterios antes de la tabla: un documento legal
        // que no dice que contiene no se puede contrastar dos años despues.
        ->and($contents)->toContain('Criterios de inclusion')
        ->and($contents)->toContain('Art. 34.9')
        // Con acentos y comillas españolas de verdad, que es lo que el BOM
        // protege.
        ->and($contents)->toContain('«Antes»');

    $filas = filasDeExportacion($export->path);

    expect($filas)->toHaveCount(1)
        ->and($filas[0]['Duracion (HH:MM)'])->toBe('07:30')
        // La misma duracion en decimal seria «7,5», que ademas se lee «75» con
        // separador de miles español.
        ->and($filas[0]['Duracion (HH:MM)'])->not->toContain(',')
        ->and($filas[0]['Duracion (HH:MM)'])->not->toContain('.');
})->group('RF-IN-05', 'RL-06');

it('deja constancia en audit_log de quien exporto, que periodo y cuantos', function (): void {
    // Regla dura 6 y RS-05: descargar el registro horario de la plantilla es un
    // acceso a datos personales de terceros y no puede ser anonimo. Lo que se
    // apunta son cifras, nunca la lista de personas: `audit_log` se conserva
    // cuatro años y no puede acabar siendo una segunda copia de la plantilla
    // (regla dura 21).
    $contexto = contextoDeExportacionLegal();
    $employee = WorkforceFixtures::employee($contexto['site']);

    tramoDeJornadaExportable($contexto['site'], $employee, '2026-03-15', '2026-03-15 06:00', '2026-03-15 14:00');

    exportacionLegal('2026-03-01', '2026-03-31');

    /** @var object{payload: string, subject_type: string, action: string}|null $asiento */
    $asiento = DB::table('audit_log')
        ->where('action', 'legal_export.generated')
        ->orderByDesc('id')
        ->first();

    expect($asiento)->not->toBeNull();

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $asiento?->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($asiento?->subject_type)->toBe('legal_export')
        ->and($payload['period_from'])->toBe('2026-03-01')
        ->and($payload['period_to'])->toBe('2026-03-31')
        ->and($payload['scope'])->toBe('all')
        ->and($payload['employee_uuid'])->toBeNull()
        ->and($payload['shift_entry_rows'])->toBe(1)
        ->and($payload['employees_exported'])->toBe(1);

    // Ni un nombre en el asiento (regla dura 21).
    expect((string) $asiento?->payload)->not->toContain('De Prueba');
})->group('RS-05', 'RL-04', 'RF-IN-05');

it('apunta el alcance cuando la exportacion es de una sola persona', function (): void {
    // Es lo que permite responder «¿quien pidio el registro de esta persona?»
    // ante una reclamacion. Un `employee_uuid` no convierte la tabla en un
    // directorio de plantilla: es el alcance, no una lista.
    $contexto = contextoDeExportacionLegal();
    $employee = WorkforceFixtures::employee($contexto['site']);

    tramoDeJornadaExportable($contexto['site'], $employee, '2026-03-16', '2026-03-16 06:00', '2026-03-16 14:00');

    exportacionLegal('2026-03-16', '2026-03-16', $employee);

    /** @var object{payload: string}|null $asiento */
    $asiento = DB::table('audit_log')
        ->where('action', 'legal_export.generated')
        ->orderByDesc('id')
        ->first();

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $asiento?->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($payload['scope'])->toBe('employee')
        ->and($payload['employee_uuid'])->toBe($employee);
})->group('RS-05', 'RF-IN-05');

it('recorre el origen sin materializarlo, que es lo que sostiene el streaming', function (): void {
    // El doc 02 §3.1 pide de las exportaciones una sola cosa: «no carga en
    // memoria un mes de 500 empleados». Quien la sostiene es este puerto, no el
    // escritor: si devolviera un array, el array estaria entero en memoria antes
    // de escribir la primera linea —cualquiera que sea la libreria que formatee
    // la fila— y nada fallaria hasta el dia del requerimiento.
    $contexto = contextoDeExportacionLegal();
    $employee = WorkforceFixtures::employee($contexto['site']);

    tramoDeJornadaExportable($contexto['site'], $employee, '2026-03-17', '2026-03-17 06:00', '2026-03-17 14:00');

    // El cursor de servidor exige transaccion, que es la que abre el caso de uso.
    DB::transaction(function () use ($employee): void {
        $records = app(LegalExportSource::class)->records(
            LegalExportPeriod::between('2026-03-17', '2026-03-17'),
            LegalExportScope::employee($employee),
        );

        expect($records)->toBeInstanceOf(Generator::class)
            ->and(iterator_to_array($records, false))->toHaveCount(1);
    });
})->group('RF-IN-05', 'RL-06');

it('escribe el mismo documento en ingles si la instalacion trabaja en ingles', function (): void {
    // Nada especifico de un cliente vive en el codigo (regla dura 13, ADR-017):
    // los rotulos salen de `lang/` y el idioma es configuracion. Lo que NO se
    // traduce es el codigo de motivo del Anexo C, porque es un catalogo cerrado
    // que tiene que leerse igual aqui, en `shift_corrections` y en `audit_log`.
    $contexto = contextoDeExportacionLegal();
    $employee = WorkforceFixtures::employee($contexto['site']);

    $tramo = tramoDeJornadaExportable($contexto['site'], $employee, '2026-03-18', '2026-03-18 06:00', null);

    app(CorrectShiftHandler::class)->handle(new CorrectShiftCommand(
        shiftEntryUuid: $tramo,
        clockedInAt: null,
        clockedOutAt: Instants::utc('2026-03-18 14:00'),
        reason: CorrectionReason::fromCode('OLVIDO_FICHAJE_SALIDA'),
        performedByUserId: $contexto['author']->id,
    ));

    App::setLocale('en');

    $contents = (string) file_get_contents(exportacionLegal('2026-03-18', '2026-03-18', $employee)->path);

    expect($contents)->toContain('Working time record')
        ->toContain('Inclusion criteria')
        ->toContain('Daily total (HH:MM)')
        ->toContain('CORRECTION;')
        // El codigo del Anexo C, intacto.
        ->toContain('OLVIDO_FICHAJE_SALIDA');

    expect($contents)->not->toContain('Criterios de inclusion');
})->group('RF-IN-05', 'RL-06');

it('entrega un fichero con sus criterios aunque el periodo no tenga jornadas', function (): void {
    // «No hay nada» tambien es una afirmacion que hay que poder entregar, y con
    // los criterios dentro para que se entienda que se busco.
    $export = exportacionLegal('2026-01-01', '2026-01-31');

    expect($export->tally->rows())->toBe(0)
        ->and($export->tally->employees)->toBe(0)
        ->and(file_exists($export->path))->toBeTrue();

    $contents = (string) file_get_contents($export->path);

    expect($contents)->toContain('Criterios de inclusion')
        ->and($contents)->toContain('del 2026-01-01 al 2026-01-31');
})->group('RF-IN-05', 'RL-06');
