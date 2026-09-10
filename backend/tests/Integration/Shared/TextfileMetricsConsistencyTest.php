<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Metrics\TextfileLegalExportMetrics;
use App\Modules\Reporting\Infrastructure\Metrics\TextfileAdoptionMetrics;
use App\Modules\Shared\Infrastructure\Metrics\TextfileExposition;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
 * Los siete ficheros `.prom` del producto se escriben con la misma mecanica
 * (doc 02 §8.2).
 *
 * **Por que existe esta prueba.** Es la hermana de
 * `tests/Integration/Shared/CsvFormatConsistencyTest.php` y nace del mismo
 * fallo. Siete adaptadores publican metricas por fichero —proyeccion,
 * incidencias, retencion, credenciales, presencia, auditoria y exportacion
 * legal—; su contenido es distinto a proposito, su forma de escribir no puede
 * serlo. Cuando cada uno declaraba su propio bloque de escritura, dejaron de
 * coincidir sin que fallara nada: `TextfileLegalExportMetrics` acabo siendo el
 * unico que no comprobaba el retorno de `rename()` —un `.prom` con la cifra de
 * ayer se lee en Grafana igual que una instalacion tranquila— y el unico cuyo
 * metodo de escritura no miraba `observability.metrics.enabled`. Cada adaptador
 * tenia sus pruebas y todas pasaban, porque ninguna comparaba un escritor con
 * otro.
 *
 * Asi que esto no comprueba que un fichero de metricas sea correcto —de eso se
 * ocupan `PresenceMetricsTest`, `IncidentDetectionTest`,
 * `DailyTotalsReconciliationTest`, `SigningKeyRotationTest`, `AuditLogTest` y
 * `RetentionTest`—: comprueba que **los siete son la misma mecanica**, y que esa
 * mecanica hace lo que dice.
 */

beforeEach(function (): void {
    Config::set('observability.metrics.enabled', true);
    Config::set(
        'observability.metrics.textfile_path',
        storage_path('framework/testing/textfile-'.Str::random(10)),
    );
});

afterEach(function (): void {
    $directory = rtrim(Config::string('observability.metrics.textfile_path'), '/');

    foreach (glob($directory.'/*') ?: [] as $leftover) {
        if (is_file($leftover)) {
            unlink($leftover);
        }
    }

    if (is_dir($directory)) {
        rmdir($directory);
    }
});

function ficheroDeMetricas(string $nombre): string
{
    return rtrim(Config::string('observability.metrics.textfile_path'), '/').'/'.$nombre;
}

it('crea el directorio y publica el fichero de forma atomica', function (): void {
    TextfileExposition::write('kronoqr_prueba.prom', ['prueba_total 1']);

    // Sin `.tmp` a la vista: `node-exporter` recoge el directorio entero y un
    // temporal olvidado seria una metrica duplicada o media metrica.
    expect(ficheroDeMetricas('kronoqr_prueba.prom'))->toBeFile()
        ->and(ficheroDeMetricas('kronoqr_prueba.prom.tmp'))->not->toBeFile()
        ->and(file_get_contents(ficheroDeMetricas('kronoqr_prueba.prom')))->toBe("prueba_total 1\n");
})->group('RF-PR-02');

it('no escribe nada cuando la instalacion apaga el colector', function (): void {
    // El fallo que esta prueba fija: una instalacion sin `node-exporter` seguia
    // acumulando ficheros que nadie recogia, porque el guard estaba en el
    // llamante de un adaptador y no en el escritor.
    Config::set('observability.metrics.enabled', false);

    TextfileExposition::write('kronoqr_prueba.prom', ['prueba_total 1']);

    expect(ficheroDeMetricas('kronoqr_prueba.prom'))->not->toBeFile()
        ->and(is_dir(rtrim(Config::string('observability.metrics.textfile_path'), '/')))->toBeFalse();
})->group('RF-PR-02');

it('no publica en silencio cuando el rename falla', function (): void {
    // Un `rename()` que devuelve `false` sin que nadie lo mire deja la serie
    // congelada en su ultimo valor, que es el estado que ninguna alerta detecta:
    // el `.prom` sigue ahi, con la cifra de ayer. Se provoca ocupando el destino
    // con un directorio, porque renombrar un fichero sobre un directorio falla
    // siempre.
    //
    // Se espera `Throwable` y no `RuntimeException` a proposito: el manejador de
    // errores de Laravel promociona el aviso de PHP a `ErrorException` antes de
    // que `rename()` llegue a devolver `false`, asi que la excepcion concreta
    // depende de la configuracion de `error_reporting` de la instalacion. Lo que
    // esta prueba fija es lo que importa y lo que no se cumplia: que **no se
    // vuelve del metodo como si se hubiera publicado**.
    $directorio = rtrim(Config::string('observability.metrics.textfile_path'), '/');

    mkdir($directorio.'/kronoqr_prueba.prom', 0o750, true);

    $lanzada = null;

    try {
        TextfileExposition::write('kronoqr_prueba.prom', ['prueba_total 1']);
    } catch (Throwable $exception) {
        $lanzada = $exception;
    }

    expect($lanzada)->toBeInstanceOf(Throwable::class);

    rmdir($directorio.'/kronoqr_prueba.prom');
})->group('RF-PR-02');

it('escapa las comillas del valor de una etiqueta', function (): void {
    // Un centro llamado `Hotel "El Faro"` sin escapar tira el fichero entero, y
    // con el las series de todos los demas.
    expect(TextfileExposition::escapeLabel('Hotel "El Faro"'))->toBe('Hotel \"El Faro\"')
        ->and(TextfileExposition::escapeLabel('C:\\ruta'))->toBe('C:\\\\ruta')
        ->and(TextfileExposition::escapeLabel("Sala\nnueva"))->toBe('Sala\nnueva');
})->group('RF-PR-02');

it('ningun adaptador textfile escribe su propio fichero', function (): void {
    // El trinquete. Mientras esta prueba exista, un adaptador nuevo -o uno
    // existente al que alguien le devuelva su bloque de escritura- no puede
    // volver a divergir en silencio: la escritura es de `TextfileExposition` o
    // no es.
    $adaptadores = [
        'app/Modules/Attendance/Infrastructure/Metrics/TextfileProjectionMetrics.php',
        'app/Modules/Compliance/Infrastructure/Metrics/TextfileAuditMetrics.php',
        'app/Modules/Compliance/Infrastructure/Metrics/TextfileIncidentMetrics.php',
        'app/Modules/Compliance/Infrastructure/Metrics/TextfileLegalExportMetrics.php',
        'app/Modules/Compliance/Infrastructure/Metrics/TextfileRetentionMetrics.php',
        'app/Modules/Identity/Infrastructure/Metrics/TextfileCredentialMetrics.php',
        'app/Modules/Reporting/Infrastructure/Metrics/TextfileAdoptionMetrics.php',
        'app/Modules/Reporting/Infrastructure/Metrics/TextfilePresenceMetrics.php',
    ];

    // Que esten TODOS: si aparece uno nuevo sin anadirlo aqui, esta lista deja
    // de significar «todos». Le paso a TextfileAdoptionMetrics (tarea 3.1), que
    // nacio despues de esta prueba y la dejo en rojo hasta que se anadio aqui.
    expect(glob(base_path('app/Modules/*/Infrastructure/Metrics/Textfile*Metrics.php')) ?: [])
        ->toHaveCount(count($adaptadores));

    foreach ($adaptadores as $adaptador) {
        $codigo = codigoSinComentarios(base_path($adaptador));

        expect($codigo)
            ->toContain('TextfileExposition::write(')
            ->and($codigo)->not->toContain('rename(')
            ->and($codigo)->not->toContain('file_put_contents(')
            ->and($codigo)->not->toContain('mkdir(');
    }
})->group('RF-PR-02');

/**
 * El codigo de un fichero sin sus comentarios.
 *
 * Los docblocks de estos adaptadores explican **por que** la escritura ya no
 * vive en ellos, y esa explicacion nombra `rename()`. Buscar sobre el fichero en
 * crudo confundiria la explicacion con la llamada.
 */
function codigoSinComentarios(string $ruta): string
{
    $codigo = '';

    foreach (token_get_all((string) file_get_contents($ruta)) as $token) {
        if (\is_array($token) && \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $codigo .= \is_array($token) ? $token[1] : $token;
    }

    return $codigo;
}

it('la exportacion legal no se rompe si la metrica no se puede publicar, pero lo deja en el log', function (): void {
    // El asiento de auditoria y el fichero ya estan escritos cuando se llega
    // aqui: un disco lleno no puede convertir eso en un error, porque quien
    // exporto lo repetiria y duplicaria el asiento. Lo que si tiene que pasar es
    // que quede constancia -antes no quedaba ninguna-.
    $directorio = rtrim(Config::string('observability.metrics.textfile_path'), '/');

    mkdir($directorio.'/kronoqr_legal_exports.prom', 0o750, true);

    $log = Log::spy();

    (new TextfileLegalExportMetrics)->exportGenerated('all');

    $log->shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'compliance.legal_export_metric_not_published'
            // El alcance es `all` o `employee`, jamas un identificador de
            // persona, y de la excepcion solo viaja su clase (regla dura 21).
            && $context['scope'] === 'all'
            && \is_string($context['exception'])
            && $context['exception'] !== '');

    rmdir($directorio.'/kronoqr_legal_exports.prom');
})->group('RF-IN-05');

it('la exportacion legal no escribe su fichero con el colector apagado', function (): void {
    Config::set('observability.metrics.enabled', false);

    (new TextfileLegalExportMetrics)->exportGenerated('all');

    expect(ficheroDeMetricas('kronoqr_legal_exports.prom'))->not->toBeFile();
})->group('RF-IN-05');

it('la exportacion legal acumula su contador leyendo el fichero anterior', function (): void {
    $metrica = new TextfileLegalExportMetrics;

    $metrica->exportGenerated('all');
    $metrica->exportGenerated('all');
    $metrica->exportGenerated('employee');

    $contenido = (string) file_get_contents(ficheroDeMetricas('kronoqr_legal_exports.prom'));

    // Las dos series siempre, esten a cero o no: una serie que desaparece es
    // indistinguible de una que nunca ocurrio.
    expect($contenido)->toContain('legal_exports_total{scope="all"} 2')
        ->and($contenido)->toContain('legal_exports_total{scope="employee"} 1');
})->group('RF-IN-05');

/*
 * --- `workdays_complete_ratio{site}` (RF-IN-08, tarea 3.1) --------------------
 *
 * El octavo adaptador, y el unico que puede decidir **no publicar una serie**.
 * Se prueba aqui, junto a la mecanica que comparte con los otros siete, porque
 * lo que hay que fijar es exactamente eso: que la omision es deliberada y que el
 * fichero que queda sigue siendo un `.prom` que `node-exporter` puede leer.
 */

/**
 * Un validador estricto del formato de exposicion, escrito a mano.
 *
 * **Por que a mano y no con `promtool`.** El `.prom` lo escribe el contenedor de
 * la aplicacion y `promtool` vive en el de Prometheus: validarlo de verdad
 * exigiria un volumen compartido entre los dos y una prueba que falla cuando el
 * `stack` de observabilidad no esta levantado. Lo que se comprueba aqui es
 * justo lo que rompe un fichero de textfile en la practica —una muestra sin su
 * `# TYPE`, un valor que no es un numero, `NaN`— y no la gramatica completa.
 *
 * @return list<string> Los problemas encontrados. Vacio es valido.
 */
function problemasDelFormatoProm(string $contenido): array
{
    $problemas = [];

    foreach (explode("\n", rtrim($contenido, "\n")) as $numero => $linea) {
        $problema = problemaDeLineaProm($linea);

        if ($problema !== null) {
            $problemas[] = 'linea '.($numero + 1).' («'.$linea.'»): '.$problema;
        }
    }

    $declaradas = seriesConCabeceraDeTipo($contenido);

    // El sufijo de un histograma o de un summary cuelga de su familia; en este
    // fichero no hay ninguno, asi que la comparacion es directa.
    foreach (array_diff(array_unique(seriesPublicadas($contenido)), $declaradas) as $serie) {
        $problemas[] = 'la serie '.$serie.' se publica sin su cabecera # TYPE';
    }

    foreach (array_count_values($declaradas) as $serie => $veces) {
        if ($veces > 1) {
            $problemas[] = 'la serie '.$serie.' declara su # TYPE mas de una vez';
        }
    }

    return $problemas;
}

/**
 * El problema de UNA linea, o `null` si esta bien.
 */
function problemaDeLineaProm(string $linea): ?string
{
    if ($linea === '') {
        return 'linea en blanco';
    }

    if (str_starts_with($linea, '# TYPE ')) {
        return preg_match('/^# TYPE [a-zA-Z_:][a-zA-Z0-9_:]* (counter|gauge|histogram|summary|untyped)$/', $linea) === 1
            ? null
            : 'cabecera TYPE mal formada';
    }

    if (str_starts_with($linea, '# HELP ')) {
        return preg_match('/^# HELP [a-zA-Z_:][a-zA-Z0-9_:]* .+$/', $linea) === 1
            ? null
            : 'cabecera HELP mal formada';
    }

    if (str_starts_with($linea, '#')) {
        return null;
    }

    // `nombre{etiqueta="valor",…} numero`, con las etiquetas opcionales.
    if (preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:]*(\{(?:[a-zA-Z_][a-zA-Z0-9_]*="(?:[^"\\\\]|\\\\.)*",?)*\})? (\S+)$/', $linea, $partes) !== 1) {
        return 'muestra mal formada';
    }

    // `NaN` es sintacticamente valido en Prometheus y aqui esta prohibido a
    // proposito: es el valor que un ratio sin denominador produce, y en un panel
    // se dibuja como un hueco que nadie sabe leer.
    return is_numeric($partes[2]) && is_finite((float) $partes[2])
        ? null
        : 'el valor no es un numero finito';
}

/**
 * @return list<string> Nombres declarados con `# TYPE`, con repeticiones.
 */
function seriesConCabeceraDeTipo(string $contenido): array
{
    preg_match_all('/^# TYPE ([a-zA-Z_:][a-zA-Z0-9_:]*) /m', $contenido, $coincidencias);

    return $coincidencias[1];
}

/**
 * @return list<string> Nombres de las muestras publicadas, con repeticiones.
 */
function seriesPublicadas(string $contenido): array
{
    preg_match_all('/^([a-zA-Z_:][a-zA-Z0-9_:]*)[{ ]/m', $contenido, $coincidencias);

    return $coincidencias[1];
}

it('no publica serie para el centro que no tuvo ninguna jornada', function (): void {
    // La decision central del adaptador. Cero se lee como «ese dia nadie cerro
    // su jornada» —una alarma que despierta a alguien— y lo que ocurre de verdad
    // es que el centro estaba cerrado. Y dividir por cero daria `NaN`, que en
    // Grafana es un hueco que nadie sabe interpretar.
    (new TextfileAdoptionMetrics)->publish(
        bySite: [1 => ['complete' => 0, 'total' => 0], 2 => ['complete' => 3, 'total' => 4]],
        workDate: '2026-03-14',
        at: new DateTimeImmutable('2026-03-15T02:30:00+00:00'),
    );

    $contenido = (string) file_get_contents(ficheroDeMetricas('kronoqr_adoption.prom'));

    expect($contenido)->not->toContain('workdays_complete_ratio{site="1"}');
    expect($contenido)->not->toContain('workdays_complete_ratio_work_days{site="1"}');
    expect($contenido)->not->toContain('NaN');

    expect($contenido)
        ->toContain('workdays_complete_ratio{site="2"} 0.750000')
        ->toContain('workdays_complete_ratio_work_days{site="2"} 4');
})->group('RF-IN-08');

it('publica un fichero que node-exporter puede leer', function (): void {
    (new TextfileAdoptionMetrics)->publish(
        bySite: [1 => ['complete' => 299, 'total' => 300], 7 => ['complete' => 0, 'total' => 2]],
        workDate: '2026-03-14',
        at: new DateTimeImmutable('2026-03-15T02:30:00+00:00'),
    );

    $contenido = (string) file_get_contents(ficheroDeMetricas('kronoqr_adoption.prom'));

    expect(problemasDelFormatoProm($contenido))->toBe([])
        // Seis decimales: un hotel de trescientas personas necesita distinguir
        // una jornada suelta sin cerrar, y `299/300` en dos decimales es `1.00`.
        ->and($contenido)->toContain('workdays_complete_ratio{site="1"} 0.996667')
        // Cero SI se publica cuando hay jornadas y ninguna cerro: ahi el cero
        // significa algo y es exactamente lo que hay que ver.
        ->and($contenido)->toContain('workdays_complete_ratio{site="7"} 0.000000')
        // La fecha medida como numero y no como etiqueta: como etiqueta seria
        // una serie nueva cada dia.
        ->and($contenido)->toContain('adoption_metrics_work_date_seconds '.strtotime('2026-03-14T00:00:00+00:00'))
        ->and($contenido)->toContain('adoption_metrics_timestamp_seconds '.strtotime('2026-03-15T02:30:00+00:00'));
})->group('RF-IN-08');

it('un dia sin ninguna jornada en ningun centro sigue publicando la fecha medida', function (): void {
    // Sin muestras de ratio, pero con `adoption_metrics_work_date_seconds`: es
    // la unica forma de distinguir «ayer no hubo actividad» de «la tarea
    // programada lleva una semana caida», que sin ella se leen igual.
    (new TextfileAdoptionMetrics)->publish(
        bySite: [],
        workDate: '2026-03-14',
        at: new DateTimeImmutable('2026-03-15T02:30:00+00:00'),
    );

    $contenido = (string) file_get_contents(ficheroDeMetricas('kronoqr_adoption.prom'));

    expect(problemasDelFormatoProm($contenido))->toBe([]);

    expect($contenido)
        ->not->toContain('workdays_complete_ratio{')
        ->toContain('adoption_metrics_work_date_seconds '.strtotime('2026-03-14T00:00:00+00:00'));
})->group('RF-IN-08');

it('detecta un fichero de metricas invalido, para que el validador de arriba signifique algo', function (): void {
    // Sin esto, `problemasDelFormatoProm()` podria devolver siempre una lista
    // vacia y las dos pruebas anteriores pasarian sin comprobar nada.
    expect(problemasDelFormatoProm("workdays_complete_ratio{site=\"1\"} 0.5\n"))
        ->toBe(['la serie workdays_complete_ratio se publica sin su cabecera # TYPE'])
        ->and(problemasDelFormatoProm("# TYPE r gauge\nr NaN\n"))
        ->toBe(['linea 2 («r NaN»): el valor no es un numero finito'])
        ->and(problemasDelFormatoProm("# TYPE r gauge\nr{site=1} 2\n"))
        ->toBe(['linea 2 («r{site=1} 2»): muestra mal formada'])
        // Dos `# TYPE` de la misma serie tiran el fichero entero en el analizador
        // de Prometheus, y con el las series de todos los demas.
        ->and(problemasDelFormatoProm("# TYPE r gauge\n# TYPE r counter\nr 1\n"))
        ->toBe(['la serie r declara su # TYPE mas de una vez']);
})->group('RF-IN-08');
