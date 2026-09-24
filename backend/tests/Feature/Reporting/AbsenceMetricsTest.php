<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Reporting\PeriodReportFixtures;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `absences_current{type}` — personas ausentes hoy, por tipo (doc 02 §8.2,
 * **RF-GP-04**, decision 8 de la ficha 3.10).
 *
 * **Se comprueba el fichero que lee `node-exporter`**, no una llamada a un
 * doble: si el formato de exposicion esta mal —una etiqueta sin escapar, media
 * metrica—, `node-exporter` **descarta el fichero entero** y no avisa.
 *
 * **Las cuatro etiquetas salen siempre**, tambien las que valen cero: una serie
 * que desaparece es indistinguible de una que nunca tuvo nada, y «hoy no hay
 * ninguna baja medica» es justo lo que se mira.
 *
 * **Y ni un nombre ni un `employee_uuid`** (regla dura 21): el tipo de ausencia
 * es dato de salud cuando dice «baja medica», y aqui va sin sujeto.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('observability.metrics.enabled', true);
    config()->set('observability.metrics.textfile_path', sys_get_temp_dir().'/kronoqr-absences-'.bin2hex(random_bytes(4)));

    // 04:55 UTC del miercoles 11 de marzo, que es la hora a la que la programa
    // `routes/console.php`. En Madrid ya son las 05:55, asi que el dia civil del
    // centro es el 11: el mismo, y esa coincidencia es lo que la prueba de abajo
    // sobre la medianoche comprueba que no es casualidad.
    FrozenTime::at('2026-03-11 04:55:00');
});

function ficheroDeMetricasDeAusencias(): string
{
    return rtrim(config()->string('observability.metrics.textfile_path'), '/').'/kronoqr_absences.prom';
}

it('publica las cuatro etiquetas, con las personas ausentes hoy en cada una', function (): void {
    $site = WorkforceFixtures::site('Hotel de ausencias', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site, 'Pisos');

    // Dos personas de vacaciones hoy y una de baja. El permiso y `other` se
    // quedan a cero, que es lo que tiene que verse.
    $uno = WorkforceFixtures::employee($site, $department, 'active', 'Youssef', 'Amrani');
    $dos = WorkforceFixtures::employee($site, $department, 'active', 'Lucia', 'Ferrer');
    $tres = WorkforceFixtures::employee($site, $department, 'active', 'Marta', 'Ruiz');

    PeriodReportFixtures::absence($uno, 'vacation', '2026-03-09', '2026-03-15');
    // Un solo dia, que es hoy: los dos extremos cuentan.
    PeriodReportFixtures::absence($dos, 'vacation', '2026-03-11', '2026-03-11');
    PeriodReportFixtures::absence($tres, 'sick_leave', '2026-03-01', '2026-03-31');

    expect(Artisan::call('reporting:absence-metrics'))->toBe(0);

    $contenido = (string) file_get_contents(ficheroDeMetricasDeAusencias());

    expect($contenido)
        ->toContain('# TYPE absences_current gauge')
        ->toContain('absences_current{type="vacation"} 2')
        ->toContain('absences_current{type="sick_leave"} 1')
        // Las que no tienen a nadie, con su cero y no ausentes.
        ->toContain('absences_current{type="leave"} 0')
        ->toContain('absences_current{type="other"} 0')
        // El `# HELP`, que es donde alguien busca la explicacion.
        ->toContain('# HELP absences_current ')
        // La hermana de frescura: que dia se midio, no cuando corrio. El
        // miercoles 11 de marzo a medianoche UTC (resto del cierre de la Fase
        // 3: sin ella, un mes sin recalculo se lee igual que un mes sin
        // ausencias).
        ->toContain('# TYPE absences_metrics_day_seconds gauge')
        ->toContain('absences_metrics_day_seconds 1773187200');

    // Ni un nombre ni un identificador de persona: una etiqueta con
    // `employee_uuid` crearia una serie por empleado (regla dura 21).
    expect($contenido)->not->toContain('Amrani');
    expect($contenido)->not->toContain($uno);
})->group('RF-GP-04');

it('escribe ceros cuando hoy no falta nadie', function (): void {
    // El caso normal de un hotel con la plantilla entera trabajando: el fichero
    // existe con sus cuatro series a cero. Si no se escribiera, el cuadro de
    // mando no distinguiria «no falta nadie» de «la tarea dejo de ejecutarse».
    $site = WorkforceFixtures::site('Hotel completo', 'Europe/Madrid');
    WorkforceFixtures::employee($site, null, 'active', 'Ana', 'Lopez');

    expect(Artisan::call('reporting:absence-metrics'))->toBe(0);

    expect((string) file_get_contents(ficheroDeMetricasDeAusencias()))
        ->toContain('absences_current{type="vacation"} 0')
        ->toContain('absences_current{type="sick_leave"} 0')
        ->toContain('absences_current{type="leave"} 0')
        ->toContain('absences_current{type="other"} 0');
})->group('RF-GP-04');

it('no cuenta la ausencia de ayer, la de mañana, la anulada ni la de quien causo baja', function (): void {
    // Los cuatro casos que separan «quien falta HOY» de «que ausencias hay
    // guardadas». Van juntos porque el fallo es el mismo: una consulta que
    // olvidara cualquiera de los cuatro predicados contaria de mas.
    $site = WorkforceFixtures::site('Hotel de bordes', 'Europe/Madrid');

    $ayer = WorkforceFixtures::employee($site, null, 'active', 'Pedro', 'Gil');
    $manana = WorkforceFixtures::employee($site, null, 'active', 'Sara', 'Mora');
    $anulada = WorkforceFixtures::employee($site, null, 'active', 'Ivan', 'Roca');
    $cesada = WorkforceFixtures::employee($site, null, 'active', 'Nuria', 'Pla');

    PeriodReportFixtures::absence($ayer, 'vacation', '2026-03-09', '2026-03-10');
    PeriodReportFixtures::absence($manana, 'vacation', '2026-03-12', '2026-03-13');
    PeriodReportFixtures::absence($anulada, 'leave', '2026-03-11', '2026-03-11');
    PeriodReportFixtures::absence($cesada, 'sick_leave', '2026-03-11', '2026-03-11');

    // Regla dura 5: la anulada sigue en la tabla, pero ya no describe a nadie
    // ausente hoy.
    DB::table('absences')
        ->where('employee_id', DB::table('employees')->where('uuid', $anulada)->value('id'))
        ->update(['status' => 'voided', 'voided_at' => (string) now(), 'void_reason' => 'Se registro por error']);

    // Y RN-14: `terminate()` cierra la relacion el 2026-06-30, asi que esta
    // persona **si** esta de alta hoy; se le adelanta el cese para el caso.
    DB::table('employees')->where('uuid', $cesada)->update(['terminated_at' => '2026-03-01', 'status' => 'terminated']);

    expect(Artisan::call('reporting:absence-metrics'))->toBe(0);

    expect((string) file_get_contents(ficheroDeMetricasDeAusencias()))
        ->toContain('absences_current{type="vacation"} 0')
        ->toContain('absences_current{type="sick_leave"} 0')
        ->toContain('absences_current{type="leave"} 0');
})->group('RF-GP-04', 'RN-14');

it('cuenta el dia civil del centro y no el del servidor', function (): void {
    /*
     * A las 23:30 UTC del 10 de marzo, en Madrid ya es el 11. Una consulta con
     * `CURRENT_DATE` —que en el contenedor de PostgreSQL es UTC— contaria el 10
     * y dejaria fuera a quien empieza sus vacaciones el 11.
     *
     * No es un caso de laboratorio: la tarea corre de madrugada, y el producto
     * se instala en hoteles de husos que no son UTC (ADR-040, regla dura 3).
     */
    FrozenTime::at('2026-03-10 23:30:00');

    $site = WorkforceFixtures::site('Hotel de medianoche', 'Europe/Madrid');
    $empleado = WorkforceFixtures::employee($site, null, 'active', 'Jon', 'Aguirre');

    PeriodReportFixtures::absence($empleado, 'vacation', '2026-03-11', '2026-03-15');

    expect(Artisan::call('reporting:absence-metrics'))->toBe(0);

    expect((string) file_get_contents(ficheroDeMetricasDeAusencias()))
        ->toContain('absences_current{type="vacation"} 1')
        // El dia civil (11), no el del reloj del servidor en el instante en
        // que corrio (10 UTC): la misma medianoche de la prueba de arriba.
        ->toContain('absences_metrics_day_seconds 1773187200');
})->group('RF-GP-04', 'RN-09');

it('deja el fichero identico al ejecutarse dos veces', function (): void {
    /*
     * Es un *gauge* que se **recalcula entero** (regla dura 7 aplicada a la
     * instrumentacion), asi que repetir el comando —el planificador
     * reintentando, alguien lanzandolo a mano para comprobar la ruta del
     * colector— tiene que dejar exactamente el mismo fichero.
     *
     * Lo que esto descarta es un acumulador escondido: si la serie leyera su
     * valor anterior y sumara, la segunda ejecucion doblaria las cifras y el
     * cuadro de mando enseñaria el doble de gente de baja sin que nadie hubiera
     * registrado nada.
     */
    $site = WorkforceFixtures::site('Hotel repetido', 'Europe/Madrid');
    $empleado = WorkforceFixtures::employee($site, null, 'active', 'Youssef', 'Amrani');

    PeriodReportFixtures::absence($empleado, 'sick_leave', '2026-03-11', '2026-03-11');

    expect(Artisan::call('reporting:absence-metrics'))->toBe(0);

    $primera = (string) file_get_contents(ficheroDeMetricasDeAusencias());

    expect(Artisan::call('reporting:absence-metrics'))->toBe(0);

    expect((string) file_get_contents(ficheroDeMetricasDeAusencias()))->toBe($primera)
        ->and($primera)->toContain('absences_current{type="sick_leave"} 1');
})->group('RF-GP-04');

it('no imprime ningun tipo de ausencia ni ningun nombre por la salida del comando', function (): void {
    // La salida del comando acaba en el log del planificador, y una baja medica
    // es dato de salud (regla dura 21). El resumen dice que se publico y nada
    // mas.
    $site = WorkforceFixtures::site('Hotel discreto', 'Europe/Madrid');
    $empleado = WorkforceFixtures::employee($site, null, 'active', 'Marta', 'Ruiz');

    PeriodReportFixtures::absence($empleado, 'sick_leave', '2026-03-11', '2026-03-11');

    expect(Artisan::call('reporting:absence-metrics'))->toBe(0);

    $salida = Artisan::output();

    expect($salida)->not->toContain('sick_leave');
    expect($salida)->not->toContain('Ruiz');
    expect($salida)->not->toContain($empleado);
})->group('RF-GP-04', 'RS-06');

it('no escribe nada y sale con cero antes de la puesta en marcha', function (): void {
    // Sin centro no hay zona con la que resolver el dia de hoy. Un planificador
    // que fallara cada noche llenaria el log de una instalacion recien instalada
    // (RF-PD-03).
    expect(Artisan::call('reporting:absence-metrics'))->toBe(0)
        ->and(Artisan::output())->toContain('Todavia no hay centro de trabajo')
        ->and(file_exists(ficheroDeMetricasDeAusencias()))->toBeFalse();
})->group('RF-GP-04', 'RF-PD-03');
