<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\ClientErrorCode;
use App\Modules\Shared\Domain\ValueObject\ErrorLevel;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use Tests\Architecture\Support\Repo;

/*
 * **EL CATALOGO DE CODIGOS DE CLIENTE ES UNO, Y ESTA ESCRITO TRES VECES**
 * (RF-PD-15, tarea 5.12, decision 3 tras la revision de seguridad).
 *
 * ## Que problema resuelve
 *
 * Desde la revision, `code` no se valida contra un patron sino contra una lista
 * cerrada por origen ({@see ClientErrorCode}). Eso corta de raiz dos cosas -que
 * una sesion de portal fabrique un `critical` con un codigo de quiosco, y que un
 * codigo libre cree una huella nueva por envio- pero **crea una dependencia
 * peligrosa**: la lista de PHP y los tipos de TypeScript de los clientes tienen
 * que decir exactamente lo mismo.
 *
 * Si se separan, el fallo no es visible: la tablet emite un codigo nuevo, el
 * servidor lo rechaza con `400`, y como el latido es lo unico que se rechaza,
 * **el sintoma es que dejan de llegar errores**, que es indistinguible de que no
 * haya errores. Justo la avería que este histórico existe para evitar.
 *
 * Por eso la comparacion es **exacta en los dos sentidos**: sobra un codigo en
 * PHP, falla; falta uno, falla.
 *
 * ## Se leen los ficheros de TypeScript como TEXTO
 *
 * Como el resto de las pruebas de arquitectura. Aqui ademas es la unica opcion:
 * un tipo union de TypeScript no existe en tiempo de ejecucion ni de PHP ni de
 * JavaScript. Se extraen los literales del bloque de cada tipo, que es lo que
 * hay, y por eso el patron exige el prefijo (`kiosk.`, `web.`): un comentario del
 * bloque con una cadena entre comillas no entra.
 */

/** El tipo union del reporter de la PWA de la tablet. */
const CATALOGO_DEL_QUIOSCO = 'frontend-kiosk/src/shared/telemetry/errorReporter.ts';

/** El del reporter compartido por el panel y el portal. */
const CATALOGO_WEB = 'packages/web-kit/src/clientErrors.ts';

/**
 * Los literales de un tipo union de TypeScript, ordenados y sin repetidos.
 *
 * Se acota primero al bloque del tipo -desde `export type <nombre> =` hasta la
 * primera linea en blanco o la siguiente sentencia `export`- para que un literal
 * de otro sitio del fichero no se cuele. Dentro del bloque se aceptan los
 * literales que empiezan por el prefijo del catalogo y nada mas.
 *
 * @return list<string>
 */
function literalesDelTipo(string $file, string $type, string $prefix): array
{
    $source = str_replace("\r\n", "\n", Repo::contents($file));
    $start = mb_strpos($source, 'export type '.$type.' =');

    if ($start === false) {
        throw new RuntimeException('No existe el tipo '.$type.' en '.$file.'.');
    }

    $block = mb_substr($source, $start);
    $end = mb_strpos($block, "\n\n");
    $block = $end === false ? $block : mb_substr($block, 0, $end);

    preg_match_all("/'(".preg_quote($prefix, '/')."[a-z0-9_.]+)'/", $block, $matches);

    /** @var list<string> $codes */
    $codes = array_values(array_unique($matches[1]));

    sort($codes);

    return $codes;
}

/**
 * @return list<string>
 */
function catalogoDe(ErrorSource $source): array
{
    $codes = ClientErrorCode::forSource($source);

    sort($codes);

    return $codes;
}

it('mantiene el catalogo del quiosco identico al tipo del reporter de la tablet', function (): void {
    // Copia literal, no un subconjunto. Un codigo que el servidor conoce y la
    // tablet no emite es una fila que nunca aparecera; uno que la tablet emite y
    // el servidor no conoce tumba el latido entero con `400` y se lleva por
    // delante los otros 49 errores del lote.
    expect(catalogoDe(ErrorSource::Kiosk))
        ->toBe(literalesDelTipo(CATALOGO_DEL_QUIOSCO, 'ClientErrorCode', 'kiosk.'));
})->group('RF-PD-15');

it('mantiene el catalogo web identico al tipo del reporter compartido', function (): void {
    expect(catalogoDe(ErrorSource::Admin))
        ->toBe(literalesDelTipo(CATALOGO_WEB, 'WebErrorCode', 'web.'));
})->group('RF-PD-15');

it('da el mismo catalogo al panel y al portal, que comparten reporter', function (): void {
    // `web-kit` es uno y lo usan las dos SPA: dos listas distintas para el mismo
    // fichero de origen serian dos formas de que una de las dos se quedara atras.
    expect(catalogoDe(ErrorSource::Portal))->toBe(catalogoDe(ErrorSource::Admin));
})->group('RF-PD-15');

it('no admite ningun codigo de cliente para los cuatro origenes del servidor', function (ErrorSource $source): void {
    // `api`, `worker`, `scheduler` y `console` no llegan por un endpoint de
    // cliente: su catalogo vacio es lo que hace que `isKnown()` rechace cualquier
    // intento de reportar «como si» fuera el servidor.
    expect(ClientErrorCode::forSource($source))->toBe([])
        ->and(ClientErrorCode::isKnown($source, 'kiosk.camera.unavailable'))->toBeFalse();
})->with([
    'api' => [ErrorSource::Api],
    'worker' => [ErrorSource::Worker],
    'scheduler' => [ErrorSource::Scheduler],
    'console' => [ErrorSource::Console],
])->group('RF-PD-15');

it('deja fuera del catalogo del quiosco los codigos de las SPA y al reves', function (): void {
    // El cruce que la revision de seguridad encontro: una sesion de portal
    // enviando `kiosk.camera.unavailable` fabricaba una fila `critical` que
    // despierta al IT del cliente.
    expect(ClientErrorCode::isKnown(ErrorSource::Portal, 'kiosk.camera.unavailable'))->toBeFalse()
        ->and(ClientErrorCode::isKnown(ErrorSource::Admin, 'kiosk.camera.unavailable'))->toBeFalse()
        ->and(ClientErrorCode::isKnown(ErrorSource::Kiosk, 'web.vue_error'))->toBeFalse();
})->group('RF-PD-15', 'RS-03');

it('solo declara como critico codigos que el catalogo del quiosco contiene', function (): void {
    // La lista de severidad de `ErrorLevel` y el catalogo son dos listas; si una
    // nombra algo que la otra no tiene, ese `critical` es inalcanzable y la tabla
    // del runbook miente.
    $desconocidos = array_values(array_filter(
        ErrorLevel::criticalClientCodes(),
        static fn (string $code): bool => ! ClientErrorCode::isKnown(ErrorSource::Kiosk, $code),
    ));

    expect($desconocidos)->toBe([]);
})->group('RF-PD-15');
