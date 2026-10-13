<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Domain\Event\DailyTotalsRecalculated;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Attendance\Infrastructure\Projection\DailyTotalsProjector;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Http\Api;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Time\Instants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **La descarga en blanco del portal es un año entero** (V3-PO-2, 2.2.1;
 * RF-ID-05, RL-05) y este es su volumen: 366 jornadas de una persona con cuatro
 * tramos cada una —turno partido con dos pausas—, 1.464 tramos, descargadas sin
 * `from` en los dos formatos.
 *
 * ## Que se defiende
 *
 * Hasta la 2.2.1 el valor por omision de la descarga era el mes de la pantalla.
 * Pasar a un año multiplica por doce lo que sale por esta puerta **sin que nadie
 * lo pida expresamente**: basta con dejar el campo en blanco. El riesgo que el
 * plan de correcciones apunta (PR19) es el PDF, que Chromium compone entero en
 * memoria antes de devolver un byte. Por eso se mide el **tiempo** y el
 * **tamaño** de los dos ficheros contra RNF-P-05: «< 5 s sincrono; asincrono si
 * supera 10 s».
 *
 * - **El CSV se afirma contra los 5 s**: sale en medio segundo y no depende de
 *   nada que no sea la base de datos.
 * - **El PDF, contra los 10 s**, que es la frontera a partir de la cual
 *   RNF-P-05 obligaria a generarlo en diferido. Medido el 13-10-2026 en el
 *   entorno de desarrollo: 3,9 s aislado y 6,9 s con otra suite corriendo a la
 *   vez. Afirmar 5 s seria una prueba que falla segun la carga de la maquina;
 *   afirmar 10 s es la que avisa el dia que la descarga en blanco deba dejar
 *   de ser sincrona.
 *
 * Las cifras medidas se imprimen con `KRONOQR_PRINT_PLAN=1`, como en
 * `PeriodReportExportVolumeTest`.
 *
 * ## Las jornadas se escriben con el agregado
 *
 * Y no por SQL: son 366 y no quince mil, y asi la proyeccion y los tramos son
 * exactamente los que produciria el quiosco, con los dos cambios de hora del
 * año dentro del rango (30-03-2025 y 26-10-2025).
 */

uses(RefreshDatabase::class);

/** Hoy, en UTC: el 20 de marzo de 2026 por la tarde, tambien en Madrid. */
const MY_WORK_DAYS_EXPORT_YEAR_TODAY = '2026-03-20 19:00:00';

/** Los cuatro tramos de cada jornada, en hora de Madrid: 555 minutos. */
const MY_WORK_DAYS_EXPORT_YEAR_SHIFTS = [
    ['06:00', '09:00'],
    ['09:30', '12:00'],
    ['16:00', '18:00'],
    ['18:15', '20:00'],
];

/** Objetivo de una exportacion sincrona (RNF-P-05), en segundos: el del CSV. */
const MY_WORK_DAYS_EXPORT_YEAR_BUDGET_SECONDS = 5.0;

/** Frontera de RNF-P-05 a partir de la cual se generaria en diferido: la del PDF. */
const MY_WORK_DAYS_EXPORT_YEAR_SYNC_LIMIT_SECONDS = 10.0;

/**
 * Una persona con su portal abierto y 366 jornadas de cuatro tramos que terminan
 * hoy. El reloj se detiene DESPUES de abrir la sesion, por lo mismo que en
 * `MyWorkDaysTest`.
 *
 * @return array{token: string, employee: string}
 */
function anoCompletoDeTramos(): array
{
    config()->set('identity.portal.rate_limit_per_minute', 10_000);

    $site = WorkforceFixtures::site('Hotel con un año de historico');
    $employee = WorkforceFixtures::employee($site);
    $token = PortalLogins::open($employee);

    FrozenTime::at(MY_WORK_DAYS_EXPORT_YEAR_TODAY);

    $repositorio = app(WorkDayRepository::class);
    $proyector = app(DailyTotalsProjector::class);
    $primero = new DateTimeImmutable('2025-03-20', new DateTimeZone('UTC'));

    for ($dia = 0; $dia < 366; $dia++) {
        $fecha = $primero->modify('+'.$dia.' days')->format('Y-m-d');

        $jornada = WorkDay::start($employee, $site, WorkDate::fromIsoDate($fecha, Instants::madrid()));

        foreach (MY_WORK_DAYS_EXPORT_YEAR_SHIFTS as [$entrada, $salida]) {
            $jornada->clockIn(Str::uuid7()->toString(), Instants::inMadrid($fecha.' '.$entrada), ScanOrigin::QR_KIOSK);
            $jornada->clockOut(Instants::inMadrid($fecha.' '.$salida), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
        }

        $repositorio->save($jornada);

        foreach ($jornada->releaseEvents() as $evento) {
            if ($evento instanceof DailyTotalsRecalculated) {
                $proyector->handle($evento);
            }
        }
    }

    DB::statement('ANALYZE shift_entries');
    DB::statement('ANALYZE daily_totals');

    return ['token' => $token, 'employee' => $employee];
}

/**
 * Descarga sin `from` y devuelve el cuerpo, los segundos que tardo la peticion
 * entera —consulta, composicion y emision— y la respuesta.
 *
 * @param  array<string, string>  $parametros
 * @return array{body: string, seconds: float, response: TestResponse<Response>}
 */
function descargarAnoEnBlanco(string $token, array $parametros): array
{
    $inicio = hrtime(true);

    $respuesta = Api::as($token)->get('/api/v1/me/export', $parametros);
    $base = $respuesta->baseResponse;

    if ($base instanceof StreamedResponse) {
        ob_start();
        $base->sendContent();
        $cuerpo = (string) ob_get_clean();
    } else {
        $cuerpo = (string) $base->getContent();
    }

    return ['body' => $cuerpo, 'seconds' => (hrtime(true) - $inicio) / 1e9, 'response' => $respuesta];
}

function anoCompletoHayChromium(): bool
{
    $configured = getenv('LARAVEL_PDF_CHROME_PATH');

    if (is_string($configured) && $configured !== '') {
        return is_executable($configured);
    }

    return is_executable('/usr/bin/chromium') || is_executable('/usr/bin/chromium-browser');
}

it('descarga en blanco un año de 1.464 tramos en CSV dentro del presupuesto y cuadrando', function (): void {
    App::setLocale('es');
    $contexto = anoCompletoDeTramos();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $descarga = descargarAnoEnBlanco($contexto['token'], []);

    $sentencias = \count(DB::getRawQueryLog());
    DB::disableQueryLog();

    $descarga['response']->assertStatus(200)
        ->assertHeader('Content-Disposition', 'attachment; filename=mi-registro-horario-2025-03-20_2026-03-20.csv');

    $tramos = 0;
    $minutos = 0;

    foreach (explode("\r\n", $descarga['body']) as $linea) {
        if (! str_starts_with($linea, 'TRAMO;')) {
            continue;
        }

        $tramos++;
        // `duration` es la sexta columna de `PersonalRecordCsv::COLUMNS`, `HH:MM`.
        [$horas, $resto] = explode(':', explode(';', $linea)[5]);
        $minutos += ((int) $horas * 60) + (int) $resto;
    }

    if (getenv('KRONOQR_PRINT_PLAN') !== false) {
        fwrite(STDOUT, 'V3-PO-2 CSV: '.$tramos.' tramos, '.round(\strlen($descarga['body']) / 1024, 1)
            .' KiB, '.round($descarga['seconds'], 3).' s, '.$sentencias." sentencias\n");
    }

    // 366 jornadas por cuatro tramos, y 366 x 555 minutos (RN-06): el año
    // entero, con los dos cambios de hora dentro, y ni un tramo de mas.
    expect($tramos)->toBe(1_464)
        ->and($minutos)->toBe(203_130)
        // Un año no puede ser una consulta por dia: el mismo techo que el de los
        // noventa dias de `MyWorkDaysVolumeTest`.
        ->and($sentencias)->toBeLessThan(20)
        ->and($descarga['seconds'])->toBeLessThan(MY_WORK_DAYS_EXPORT_YEAR_BUDGET_SECONDS);
})->group('RF-ID-05', 'RL-05', 'RN-06', 'RNF-P-05');

it('descarga en blanco un año de 1.464 tramos en PDF sellado dentro del presupuesto', function (): void {
    // El riesgo de PR19: Chromium compone el documento entero antes de devolver
    // los bytes. Se mide con el motor real, no con el doble de feature.
    App::setLocale('es');
    $contexto = anoCompletoDeTramos();

    $descarga = descargarAnoEnBlanco($contexto['token'], ['format' => 'pdf']);

    $descarga['response']->assertStatus(200)
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Disposition', 'attachment; filename=mi-registro-horario-2025-03-20_2026-03-20.pdf');

    $bytes = \strlen($descarga['body']);
    $paginas = preg_match_all('#/Type\s*/Page[^s]#', $descarga['body']);

    if (getenv('KRONOQR_PRINT_PLAN') !== false) {
        fwrite(STDOUT, 'V3-PO-2 PDF: '.$paginas.' paginas, '.round($bytes / 1024, 1)
            .' KiB, '.round($descarga['seconds'], 3)." s\n");
    }

    expect($descarga['body'])->toStartWith('%PDF-')
        // Un año de tramos ocupa varias paginas: si saliera una sola, el
        // documento se habria cortado.
        ->and($paginas)->toBeGreaterThan(10)
        // Un PDF que se descarga desde datos moviles y se envia por correo: por
        // debajo de 5 MiB para un año entero. Medido en el bloque 8 de la 2.2.1:
        // 4,5 MiB con 4 tramos al dia (margen ~10 %). No sale de ningun requisito:
        // el peso del PDF anual queda como riesgo de PR19 en el bloque 23.
        ->and($bytes)->toBeLessThan(5 * 1_048_576)
        ->and($descarga['seconds'])->toBeLessThan(MY_WORK_DAYS_EXPORT_YEAR_SYNC_LIMIT_SECONDS);
})->group('RF-ID-05', 'RL-05', 'RNF-P-05')->skip(! anoCompletoHayChromium(), 'Chromium no esta instalado en este contenedor.');
