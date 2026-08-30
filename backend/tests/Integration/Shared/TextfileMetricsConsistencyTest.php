<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Metrics\TextfileLegalExportMetrics;
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
        'app/Modules/Reporting/Infrastructure/Metrics/TextfilePresenceMetrics.php',
    ];

    // Que esten los siete: si aparece un octavo sin anadirlo aqui, esta lista
    // deja de significar «todos».
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
