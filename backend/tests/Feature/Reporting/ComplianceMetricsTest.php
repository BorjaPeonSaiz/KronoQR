<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Reporting\PeriodReportFixtures;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Las dos metricas de la vista de cumplimiento (doc 02 §8.2, RF-PA-06, decision
 * 10 de la ficha 3.4).
 *
 *   compliance_findings_last_week{rule}
 *   compliance_employees_affected_last_week
 *
 * **Se comprueba el fichero que lee `node-exporter`**, no una llamada a un doble:
 * si el formato de exposicion esta mal —una etiqueta sin escapar, media
 * metrica—, `node-exporter` **descarta el fichero entero** y no avisa.
 *
 * Y se comprueba que las cifras salen de **la misma consulta que la pantalla**:
 * una consulta propia «mas barata» para la metrica seria una segunda definicion
 * de lo que es un incumplimiento, y el dia que discreparan Grafana diria una cosa
 * y el panel otra delante del mismo cliente.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('observability.metrics.enabled', true);
    config()->set('observability.metrics.textfile_path', sys_get_temp_dir().'/kronoqr-compliance-'.bin2hex(random_bytes(4)));

    // Lunes 16 de marzo. La ultima semana completa del perfil `ES-hosteleria`
    // (`week_starts_on = 1`) es la del 9 al 15.
    FrozenTime::at('2026-03-16 04:45:00');
});

function ficheroDeMetricasDeCumplimiento(): string
{
    return rtrim(config()->string('observability.metrics.textfile_path'), '/').'/kronoqr_compliance.prom';
}

it('publica las cuatro reglas de la ultima semana completa y las personas afectadas', function (): void {
    $site = WorkforceFixtures::site('Hotel de metricas', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site, 'Cocina');

    // Dentro de la semana del 9 al 15: descanso de 9 h el martes y jornada de
    // 9 h 30. Dos hallazgos, una persona.
    $employee = WorkforceFixtures::employee($site, $department, 'active', 'Youssef', 'Amrani');
    PeriodReportFixtures::workDay($site, $employee, '2026-03-09', '2026-03-09 09:00', '2026-03-09 17:00');
    PeriodReportFixtures::workDay($site, $employee, '2026-03-10', '2026-03-10 02:00', '2026-03-10 11:30');

    // Y una jornada de la semana EN CURSO que tambien incumpliria: no debe contar,
    // porque la semana todavia puede crecer.
    $otra = WorkforceFixtures::employee($site, $department, 'active', 'Lucia', 'Ferrer');
    PeriodReportFixtures::workDay($site, $otra, '2026-03-16', '2026-03-16 07:00', '2026-03-16 18:00');

    expect(Artisan::call('reporting:compliance-metrics'))->toBe(0);

    $contenido = (string) file_get_contents(ficheroDeMetricasDeCumplimiento());

    expect($contenido)
        ->toContain('# TYPE compliance_findings_last_week gauge')
        ->toContain('compliance_findings_last_week{rule="insufficient_rest"} 1')
        ->toContain('compliance_findings_last_week{rule="daily_excess"} 1')
        // Las cuatro se escriben, tambien las que estan a cero: una serie que
        // desaparece es indistinguible de una que nunca tuvo nada, y el cero es
        // justo lo que se mira.
        ->toContain('compliance_findings_last_week{rule="missing_break"} 0')
        ->toContain('compliance_findings_last_week{rule="weekly_excess"} 0')
        ->toContain('# TYPE compliance_employees_affected_last_week gauge')
        ->toContain('compliance_employees_affected_last_week 1')
        // Que semana se midio, para que unas cifras congeladas no se lean igual
        // que una semana tranquila: el lunes 9 a medianoche UTC.
        ->toContain('compliance_metrics_week_start_seconds 1773014400');

    // Y el `# HELP` de las dos, que es donde alguien busca la explicacion a las
    // seis de la mañana.
    expect($contenido)
        ->toContain('# HELP compliance_findings_last_week ')
        ->toContain('# HELP compliance_employees_affected_last_week ');
})->group('RF-PA-06', 'RN-17');

it('escribe ceros cuando la semana no tuvo ningun incumplimiento', function (): void {
    // El caso normal de un hotel que cumple: el fichero existe con sus series a
    // cero. Si no se escribiera, el cuadro de mando no distinguiria «nadie
    // incumplio» de «la tarea dejo de ejecutarse».
    $site = WorkforceFixtures::site('Hotel tranquilo', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site, 'Recepcion');
    $employee = WorkforceFixtures::employee($site, $department, 'active', 'Ana', 'Lopez');

    PeriodReportFixtures::workDay($site, $employee, '2026-03-09', '2026-03-09 09:00', '2026-03-09 16:00');

    expect(Artisan::call('reporting:compliance-metrics'))->toBe(0);

    expect((string) file_get_contents(ficheroDeMetricasDeCumplimiento()))
        ->toContain('compliance_findings_last_week{rule="insufficient_rest"} 0')
        ->toContain('compliance_employees_affected_last_week 0');
})->group('RF-PA-06');

it('deja el fichero identico al ejecutarse dos veces el mismo dia', function (): void {
    /*
     * Son *gauges* que se **recalculan enteros** sobre una semana ya cerrada
     * (regla dura 7 aplicada a la instrumentacion), asi que repetir el comando
     * —el planificador reintentando, alguien lanzandolo a mano para comprobar la
     * ruta del colector— tiene que dejar exactamente el mismo fichero.
     *
     * Lo que esto descarta es un acumulador escondido: si alguna de las tres
     * series leyera su valor anterior y sumara, la segunda ejecucion doblaria las
     * cifras y el cuadro de mando enseñaria una semana con el doble de
     * incumplimientos sin que nadie hubiera fichado nada.
     */
    $site = WorkforceFixtures::site('Hotel repetido', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site, 'Cocina');
    $employee = WorkforceFixtures::employee($site, $department, 'active', 'Youssef', 'Amrani');

    PeriodReportFixtures::workDay($site, $employee, '2026-03-09', '2026-03-09 09:00', '2026-03-09 17:00');
    PeriodReportFixtures::workDay($site, $employee, '2026-03-10', '2026-03-10 02:00', '2026-03-10 11:30');

    expect(Artisan::call('reporting:compliance-metrics'))->toBe(0);

    $primera = (string) file_get_contents(ficheroDeMetricasDeCumplimiento());

    expect(Artisan::call('reporting:compliance-metrics'))->toBe(0);

    expect((string) file_get_contents(ficheroDeMetricasDeCumplimiento()))->toBe($primera)
        // Y con las cifras de una sola pasada, no del doble.
        ->and($primera)->toContain('compliance_findings_last_week{rule="insufficient_rest"} 1')
        ->and($primera)->toContain('compliance_employees_affected_last_week 1');
})->group('RF-PA-06');

it('deja un asiento de divulgacion, porque ha leido el cumplimiento de toda la plantilla', function (): void {
    // Consecuencia asumida de usar la MISMA consulta que la pantalla (RS-05): el
    // proceso ha leido el cumplimiento de todo el centro, y que eso quede escrito
    // una vez al dia es exactamente lo que RL-15 quiere ver. El asiento sigue sin
    // llevar ningun nombre (regla dura 21).
    $site = WorkforceFixtures::site('Hotel auditado', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site, 'Pisos');
    $employee = WorkforceFixtures::employee($site, $department, 'active', 'Marta', 'Ruiz');

    PeriodReportFixtures::workDay($site, $employee, '2026-03-09', '2026-03-09 09:00', '2026-03-09 16:00');

    expect(Artisan::call('reporting:compliance-metrics'))->toBe(0);

    $asientos = DB::table('audit_log')->where('action', 'personal_data.accessed')->get();

    expect($asientos)->toHaveCount(1);

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) ($asientos->first()->payload ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['dataset'] ?? null)->toBe('compliance_summary')
        ->and($payload['from'] ?? null)->toBe('2026-03-09')
        ->and($payload['to'] ?? null)->toBe('2026-03-15')
        ->and($payload['scope'] ?? null)->toBe('all')
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('Ruiz');
})->group('RF-PA-06', 'RS-05');

it('no escribe nada y sale con cero antes de la puesta en marcha', function (): void {
    // Sin centro no hay perfil con el que evaluar ni zona con la que resolver la
    // semana. Un planificador que fallara cada noche llenaria el log de una
    // instalacion recien instalada (RF-PD-03).
    expect(Artisan::call('reporting:compliance-metrics'))->toBe(0)
        ->and(Artisan::output())->toContain('Todavia no hay centro de trabajo')
        ->and(file_exists(ficheroDeMetricasDeCumplimiento()))->toBeFalse();
})->group('RF-PA-06', 'RF-PD-03');
