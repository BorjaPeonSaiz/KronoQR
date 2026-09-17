<?php

declare(strict_types=1);

use App\Modules\Product\Domain\Exception\InvalidComplianceProfileValue;
use App\Modules\Product\Domain\ValueObject\ComplianceProfileField;
use App\Modules\Product\Domain\ValueObject\ComplianceProfileSnapshot;
use App\Modules\Product\Domain\ValueObject\ComplianceProfileSource;
use App\Modules\Shared\Domain\ValueObject\ComplianceRule;
use App\Modules\Shared\Domain\ValueObject\ComplianceRuleSuspension;

/*
 * El perfil de cumplimiento como objeto de valor (RF-PD-07, regla dura 14).
 *
 * Dominio puro: ni framework ni base de datos. Lo que se fija aqui es que **no
 * pueda representarse un perfil imposible** y que un cambio produzca un objeto
 * nuevo sin tocar el anterior, que es lo que permite escribir el asiento con el
 * valor de antes y el de despues.
 *
 * La validacion vive aqui y no solo en el `FormRequest` porque hay caminos que
 * no pasan por HTTP —el asistente de puesta en marcha y la consola— y la
 * garantia no puede depender de por donde se entre.
 */

function spanishProfileSnapshot(): ComplianceProfileSnapshot
{
    return new ComplianceProfileSnapshot(
        id: 1,
        name: 'ES-hosteleria',
        jurisdiction: 'ES',
        minRestHours: 12,
        maxDailyHours: 9,
        maxWeeklyHours: 40,
        breakRequiredAfterHours: 6,
        weekStartsOn: 1,
        holidayCalendar: [],
        retentionYears: 4,
        isDefault: true,
        source: ComplianceProfileSource::InstallationDefault,
    );
}

/**
 * La suspension que rige en una instalacion que **no** ficha la pausa, que es la
 * de serie (`ATTENDANCE_BREAK_CLOCKING=disabled`, decision 7 de la ficha 3.5).
 *
 * Nombre propio del fichero: en Pest las funciones de un fichero de prueba son
 * globales para toda la suite.
 */
function withoutBreakClocking(): ComplianceRuleSuspension
{
    return ComplianceRuleSuspension::forInstallation(breakClockingEnabled: false);
}

function withBreakClocking(): ComplianceRuleSuspension
{
    return ComplianceRuleSuspension::forInstallation(breakClockingEnabled: true);
}

it('aplica un cambio sin tocar el perfil anterior', function (): void {
    // El asiento declara un valor anterior y uno nuevo: si `with()` mutara, el
    // «antes» se perderia y el trail diria que el umbral no cambio.
    $before = spanishProfileSnapshot();
    $after = $before->with(['min_rest_hours' => 10]);

    expect($after->minRestHours)->toBe(10)
        ->and($before->minRestHours)->toBe(12)
        // Lo que no viaja no cambia: es un `PATCH`.
        ->and($after->maxDailyHours)->toBe(9)
        ->and($after->name)->toBe('ES-hosteleria');
})->group('RF-PD-07');

it('conserva lo que no es editable aunque se pida cambiarlo', function (): void {
    // `id`, `jurisdiction`, `is_default` y el origen de la resolucion no son
    // campos del catalogo: `with()` los arrastra tal cual.
    $after = spanishProfileSnapshot()->with(['retention_years' => 6]);

    expect($after->id)->toBe(1)
        ->and($after->jurisdiction)->toBe('ES')
        ->and($after->isDefault)->toBeTrue()
        ->and($after->source)->toBe(ComplianceProfileSource::InstallationDefault);
})->group('RF-PD-07');

it('rechaza un umbral fuera del rango de su campo', function (string $field, int $value): void {
    // El error peligroso es el silencioso: `max_daily_hours = 90` no rompe nada
    // y apaga RN-11 hasta que alguien compara una nomina con el convenio.
    expect(fn (): ComplianceProfileSnapshot => spanishProfileSnapshot()->with([$field => $value]))
        ->toThrow(InvalidComplianceProfileValue::class);
})->with([
    'jornada diaria de 90 horas' => ['max_daily_hours', 90],
    'jornada diaria a cero' => ['max_daily_hours', 0],
    'descanso de 25 horas' => ['min_rest_hours', 25],
    'pausa negativa' => ['break_required_after_hours', -1],
    'semana de 200 horas' => ['max_weekly_hours', 200],
    'semana que empieza el dia 8' => ['week_starts_on', 8],
    'semana que empieza el dia 0' => ['week_starts_on', 0],
    'retencion de 100 anos' => ['retention_years', 100],
    'retencion a cero' => ['retention_years', 0],
])->group('RF-PD-07');

it('acepta los limites exactos de cada campo', function (string $field, int $value): void {
    // El limite es cerrado por los dos lados: el maximo admitido se admite. Sin
    // esta prueba, un `<` por un `<=` pasaria inadvertido y rechazaria un perfil
    // legitimo.
    expect(spanishProfileSnapshot()->with([$field => $value])->valueOf(ComplianceProfileField::from($field)))
        ->toBe($value);
})->with([
    'un descanso de una hora' => ['min_rest_hours', 1],
    'un descanso de 24 horas' => ['min_rest_hours', 24],
    'una jornada diaria de 24 horas' => ['max_daily_hours', 24],
    'una semana de 168 horas' => ['max_weekly_hours', 168],
    'la semana empieza el lunes' => ['week_starts_on', 1],
    'la semana empieza el domingo' => ['week_starts_on', 7],
    'un ano de retencion' => ['retention_years', 1],
    'cincuenta anos de retencion' => ['retention_years', 50],
])->group('RF-PD-07');

it('rechaza dejar la jornada semanal por debajo de la diaria', function (): void {
    // La invariante que ningun campo puede comprobar solo: la peticion cambia la
    // diaria y la semanal viene de la fila que ya hay. Se comprueba sobre el
    // resultado, que es lo que impide que el orden de los campos deje un perfil
    // imposible.
    expect(fn (): ComplianceProfileSnapshot => spanishProfileSnapshot()->with(['max_weekly_hours' => 8]))
        ->toThrow(InvalidComplianceProfileValue::class);
})->group('RF-PD-07');

it('acepta la jornada semanal exactamente igual a la diaria', function (): void {
    // El limite es «menor que», no «menor o igual».
    expect(spanishProfileSnapshot()->with(['max_weekly_hours' => 9])->maxWeeklyHours)->toBe(9);
})->group('RF-PD-07');

it('rechaza un umbral que llega como texto, aunque sea un numero', function (): void {
    // Un JSON escrito a mano con comillas convertiria un error tipografico en un
    // umbral legal silenciosamente distinto del que se cree haber puesto.
    expect(fn (): ComplianceProfileSnapshot => spanishProfileSnapshot()->with(['min_rest_hours' => '10']))
        ->toThrow(InvalidComplianceProfileValue::class);
})->group('RF-PD-07');

it('recorta el nombre del convenio y rechaza el vacio', function (): void {
    expect(spanishProfileSnapshot()->with(['name' => '  Convenio de Cantabria  '])->name)
        ->toBe('Convenio de Cantabria')
        ->and(fn (): ComplianceProfileSnapshot => spanishProfileSnapshot()->with(['name' => '   ']))
        ->toThrow(InvalidComplianceProfileValue::class);
})->group('RF-PD-07');

it('rechaza un nombre mas largo que la columna', function (): void {
    expect(fn (): ComplianceProfileSnapshot => spanishProfileSnapshot()->with(['name' => str_repeat('a', 65)]))
        ->toThrow(InvalidComplianceProfileValue::class)
        ->and(spanishProfileSnapshot()->with(['name' => str_repeat('a', 64)])->name)
        ->toBe(str_repeat('a', 64));
})->group('RF-PD-07');

it('ordena el calendario de festivos y rechaza los repetidos', function (): void {
    // Dos perfiles con los mismos festivos en distinto orden son el mismo perfil:
    // sin normalizar, reordenar la lista produciria un asiento que declara un
    // cambio de umbral legal donde no lo hubo.
    expect(spanishProfileSnapshot()->with(['holiday_calendar' => ['2026-12-25', '2026-01-01']])->holidayCalendar)
        ->toBe(['2026-01-01', '2026-12-25'])
        ->and(fn (): ComplianceProfileSnapshot => spanishProfileSnapshot()
            ->with(['holiday_calendar' => ['2026-12-25', '2026-12-25']]))
        ->toThrow(InvalidComplianceProfileValue::class);
})->group('RF-PD-07');

it('rechaza un festivo que no es una fecha ISO', function (mixed $calendar): void {
    expect(fn (): ComplianceProfileSnapshot => spanishProfileSnapshot()->with(['holiday_calendar' => $calendar]))
        ->toThrow(InvalidComplianceProfileValue::class);
})->with([
    'texto libre' => [['Navidad']],
    'formato espanol' => [['25/12/2026']],
    'dia que no existe' => [['2026-02-30']],
    'mes que no existe' => [['2026-13-01']],
    // Al ESCRIBIR, lo que no es una lista tampoco pasa: al leer se descarta en
    // silencio —una fila corrupta no puede dejar a un centro sin calcular— y
    // aqui hay alguien delante a quien decirselo.
    'no es una lista' => [['dia' => '2026-12-25']],
    'no es ni un array' => ['2026-12-25'],
    'un numero suelto' => [20261225],
    'nulo' => [null],
    'no es una cadena' => [[20261225]],
])->group('RF-PD-07');

it('acepta vaciar el calendario de festivos', function (): void {
    // Caduca cada 31 de diciembre y hay que poder rehacerlo.
    $withHolidays = spanishProfileSnapshot()->with(['holiday_calendar' => ['2026-12-25']]);

    expect($withHolidays->with(['holiday_calendar' => []])->holidayCalendar)->toBe([]);
})->group('RF-PD-07');

it('solo declara cambiado lo que de verdad cambia de valor', function (): void {
    // Abrir la pantalla y pulsar «guardar» no puede escribir un asiento: la señal
    // que importa —«alguien movio el descanso minimo»— quedaria enterrada.
    $profile = spanishProfileSnapshot();

    expect($profile->fieldsThatChange(['min_rest_hours' => 12, 'max_daily_hours' => 9]))->toBe([])
        ->and($profile->fieldsThatChange(['min_rest_hours' => 10]))
        ->toBe([ComplianceProfileField::MinRestHours])
        // Reordenar el calendario tampoco es un cambio.
        ->and($profile->fieldsThatChange(['holiday_calendar' => []]))->toBe([]);
})->group('RF-PD-07', 'RL-04');

it('separa las tres consecuencias de cada campo, que es lo que lee el asiento', function (): void {
    // «¿Cambia esto que alertas saltan?», «¿cambia esto lo que RRHH ve en la vista
    // de cumplimiento?» y «¿cambia esto que se puede borrar?» son tres preguntas
    // distintas y quien lee el trail busca una u otra.
    expect(ComplianceProfileField::MinRestHours->affectsIncidentDetection(withoutBreakClocking()))->toBeTrue()
        ->and(ComplianceProfileField::MaxDailyHours->affectsIncidentDetection(withoutBreakClocking()))->toBeTrue()
        ->and(ComplianceProfileField::RetentionYears->affectsIncidentDetection(withoutBreakClocking()))->toBeFalse()
        ->and(ComplianceProfileField::RetentionYears->affectsRetention())->toBeTrue()
        ->and(ComplianceProfileField::MinRestHours->affectsRetention())->toBeFalse()
        ->and(ComplianceProfileField::Name->affectsIncidentDetection(withoutBreakClocking()))->toBeFalse()
        ->and(ComplianceProfileField::Name->affectsRetention())->toBeFalse()
        // La tercera, de la tarea 3.4: los cuatro campos que gobiernan una regla
        // mueven lo que enseña la vista de cumplimiento, tambien el de la regla
        // suspendida —`meta.rules[]` publica su umbral— y tambien los de RN-17,
        // que no abre incidencia.
        ->and(ComplianceProfileField::MinRestHours->affectsComplianceView())->toBeTrue()
        ->and(ComplianceProfileField::BreakRequiredAfterHours->affectsComplianceView())->toBeTrue()
        ->and(ComplianceProfileField::MaxWeeklyHours->affectsComplianceView())->toBeTrue()
        ->and(ComplianceProfileField::WeekStartsOn->affectsComplianceView())->toBeTrue()
        ->and(ComplianceProfileField::Name->affectsComplianceView())->toBeFalse()
        ->and(ComplianceProfileField::RetentionYears->affectsComplianceView())->toBeFalse()
        ->and(ComplianceProfileField::HolidayCalendar->affectsComplianceView())->toBeFalse();
})->group('RF-PD-07', 'RL-04', 'RF-PA-06');

it('no afirma que el limite semanal mueva la deteccion, porque RN-17 no abre incidencia', function (): void {
    /*
     * La distincion que estrena la tarea 3.4, y que no es la misma que la
     * suspension de RN-12.
     *
     * `max_weekly_hours` y `week_starts_on` gobiernan RN-17, que **por definicion**
     * no abre incidencia: el art. 34.1 ET fija la jornada semanal en computo
     * anual, asi que una semana por encima se señala en la vista y no en la
     * bandeja. Es permanente, no un «todavia no» como el de RN-12: por eso
     * `governsSuspendedRule()` es `false` y `affectsComplianceView()` es `true`.
     */
    expect(ComplianceProfileField::MaxWeeklyHours->affectsIncidentDetection(withoutBreakClocking()))->toBeFalse()
        ->and(ComplianceProfileField::WeekStartsOn->affectsIncidentDetection(withoutBreakClocking()))->toBeFalse()
        ->and(ComplianceProfileField::MaxWeeklyHours->governsSuspendedRule(withoutBreakClocking()))->toBeFalse()
        ->and(ComplianceProfileField::WeekStartsOn->governsSuspendedRule(withoutBreakClocking()))->toBeFalse()
        ->and(ComplianceRule::MaximumWeeklyWorkingTime->opensIncident())->toBeFalse()
        ->and(ComplianceRule::MinimumRestBetweenWorkDays->opensIncident())->toBeTrue()
        ->and(ComplianceRule::MaximumDailyWorkingTime->opensIncident())->toBeTrue()
        ->and(ComplianceRule::BreakInContinuousShift->opensIncident())->toBeTrue();
})->group('RF-PD-07', 'RN-17', 'RL-04');

it('no afirma que el umbral de la pausa mueva la deteccion mientras RN-12 este suspendida', function (): void {
    // **El defecto que esto fija.** `break_required_after_hours` gobierna RN-12,
    // y RN-12 se evalua pero **no abre incidencias** mientras el fichaje de
    // pausa siga desactivado en la instalacion (ADR-024, RF-AT-12). Escribir
    // `affects_incident_detection: true` en `audit_log` era afirmar algo falso
    // dentro de un registro con valor legal, y la pantalla prometia jornadas
    // marcadas que no se marcan.
    expect(ComplianceProfileField::BreakRequiredAfterHours->affectsIncidentDetection(withoutBreakClocking()))->toBeFalse()
        // Pero el matiz se conserva: no es que el campo no gobierne nada, es que
        // lo que gobierna esta suspendido. Sin esta distincion, el asiento seria
        // indistinguible del de un cambio de nombre del convenio.
        ->and(ComplianceProfileField::BreakRequiredAfterHours->governsSuspendedRule(withoutBreakClocking()))->toBeTrue()
        ->and(ComplianceProfileField::Name->governsSuspendedRule(withoutBreakClocking()))->toBeFalse()
        ->and(ComplianceProfileField::MinRestHours->governsSuspendedRule(withoutBreakClocking()))->toBeFalse();
})->group('RF-PD-07', 'RN-12', 'RL-04');

it('vuelve a afirmar que el umbral de la pausa mueve la deteccion en cuanto el hotel la ficha', function (): void {
    // **La otra rama, que hasta la tarea 3.5 no se podia ejercitar** porque la
    // suspension era una constante privada (trampa apuntada en `HANDOFF.md`).
    // Activar `ATTENDANCE_BREAK_CLOCKING` devuelve el campo a `true` sin que
    // nadie edite `ComplianceProfileField`: el asiento y la pantalla vuelven a
    // decir la verdad solos.
    expect(ComplianceProfileField::BreakRequiredAfterHours->affectsIncidentDetection(withBreakClocking()))->toBeTrue()
        ->and(ComplianceProfileField::BreakRequiredAfterHours->governsSuspendedRule(withBreakClocking()))->toBeFalse()
        // Y no arrastra a nadie: RN-17 sigue sin abrir incidencia, porque eso no
        // es una suspension sino lo que la regla significa.
        ->and(ComplianceProfileField::MaxWeeklyHours->affectsIncidentDetection(withBreakClocking()))->toBeFalse()
        ->and(ComplianceProfileField::MinRestHours->affectsIncidentDetection(withBreakClocking()))->toBeTrue();
})->group('RF-PD-07', 'RN-12', 'RF-AT-12', 'RL-04');

it('deriva la suspension de un unico sitio, para que el ajuste la reactive sin tocar Product', function (bool $breakClocking): void {
    // La propiedad que hace que esto no vuelva a divergir: la respuesta se
    // **deriva** de la suspension que se reciba, no se enumera aqui. Si alguien
    // reintrodujera un literal en `ComplianceProfileField`, una de las dos
    // vueltas de esta prueba se pondria roja.
    $suspension = ComplianceRuleSuspension::forInstallation($breakClocking);

    expect($suspension->suspended())->toBe(
        $breakClocking ? [] : [ComplianceRule::BreakInContinuousShift],
    );

    foreach (ComplianceProfileField::cases() as $field) {
        $rule = $field->complianceRule();

        // Las dos condiciones se derivan, ninguna se enumera: la regla dice si
        // abre incidencia y la suspension dice si esa apertura esta suspendida
        // en esta instalacion.
        expect($field->affectsIncidentDetection($suspension))->toBe(
            $rule instanceof ComplianceRule && $rule->opensIncident() && ! $suspension->isSuspended($rule),
        );
    }
})->with([
    'sin fichaje de pausa' => [false],
    'con fichaje de pausa' => [true],
])->group('RF-PD-07', 'RN-12', 'RN-17', 'RF-AT-12');

it('declara cuales de sus campos todavia no aplica ninguna regla', function (): void {
    // Prometer un efecto que hoy no existe es peor que no ofrecer el campo: el
    // panel lo dice a partir de aqui, no de una lista propia.
    //
    // **Queda uno.** La tarea 3.4 estreno `max_weekly_hours` y `week_starts_on`
    // con RN-17; `holiday_calendar` sigue sin consumidor a proposito, porque
    // ninguna de las cuatro reglas lee festivos —la semanal mide la semana
    // trabajada, no los dias laborables— y lo estrena la 3.10 (ausencias).
    $pending = array_values(array_filter(
        ComplianceProfileField::cases(),
        static fn (ComplianceProfileField $field): bool => $field->hasNoConsumerYet(),
    ));

    expect($pending)->toBe([ComplianceProfileField::HolidayCalendar]);
})->group('RF-PD-07');

it('rechaza justo por encima del maximo de cada campo', function (string $field, int $value): void {
    // El vecino del limite, y no un valor absurdo: con solo «200 horas
    // semanales» probado, subir el tope de 168 a 169 pasaria inadvertido — y ese
    // tope existe para que un error tipográfico no apague una regla legal. Es la
    // otra mitad de la prueba de los límites exactos.
    expect(fn (): ComplianceProfileSnapshot => spanishProfileSnapshot()->with([$field => $value]))
        ->toThrow(InvalidComplianceProfileValue::class);
})->with([
    'descanso de 25 horas' => ['min_rest_hours', 25],
    'jornada diaria de 25 horas' => ['max_daily_hours', 25],
    'pausa a las 25 horas' => ['break_required_after_hours', 25],
    'semana de 169 horas' => ['max_weekly_hours', 169],
    'la semana empieza el dia 8' => ['week_starts_on', 8],
    'retencion de 51 anos' => ['retention_years', 51],
])->group('RF-PD-07');
