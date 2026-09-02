<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\ParallelRequests;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;

/*
 * **Dos `PATCH /api/v1/settings` a la vez no pueden dejar la instalacion en un
 * estado imposible** (RF-PD-01).
 *
 * ## El fallo que esto impide
 *
 * Leer el estado actual **fuera** de la transaccion abre una ventana entre la
 * lectura y la escritura, y dos administradores guardando a la vez la
 * aprovechan:
 *
 *   A: `{LOCALE_DEFAULT: "en", LOCALE_AVAILABLE: ["en"]}`  — valido cuando A leyo
 *   B: `{LOCALE_DEFAULT: "es"}`                            — valido cuando B leyo
 *
 * Las dos son correctas por separado; juntas dejan `LOCALE_DEFAULT = "es"` con
 * `LOCALE_AVAILABLE = ["en"]`, que es exactamente el estado que el producto
 * declara imposible y que ninguna clave puede detectar sola. El sintoma llega
 * despues: una instalacion que arranca en un idioma que no ofrece.
 *
 * La cierra `pg_advisory_xact_lock` tomado **dentro** de la transaccion y antes
 * de leer, con la lectura saltandose la cache. El candado serializa a los
 * escritores de configuracion —no a los lectores, que son el camino de fichaje—
 * y se suelta al confirmar o revertir.
 *
 * ## Que se afirma, y por que no es «B recibe 422»
 *
 * El desenlace de cada peticion depende del orden en que el planificador las
 * despache, que no es determinista: si B llega primero, las dos son validas y
 * las dos responden `200`. Lo que **si** es invariante, con candado o sin el, es
 * el estado final — y sin candado ese estado puede ser imposible. Eso es lo que
 * se comprueba, junto con que quien pierde la carrera recibe un `422` y no un
 * `500`: revalida contra lo confirmado y responde como si el cuerpo estuviera mal,
 * que para quien lo envio es la verdad.
 *
 * **Procesos de verdad y no un bucle** (ver `ParallelRequests`): en secuencia
 * dentro de un proceso, estas pruebas pasarian igual sin candado.
 *
 * ## Cual de las dos es la puerta, medido
 *
 * Se comprobo quitando el candado. **La segunda falla siempre** —seis escritores,
 * cinco asientos: una escritura se pierde—, y es la que sostiene esta garantia.
 * La primera **no reprodujo la carrera en veinte intentos**: la ventana entre la
 * lectura y el `UPDATE` es de microsegundos y hace falta que dos procesos caigan
 * justo dentro. Se conserva igualmente porque afirma la invariante que de verdad
 * importa —el estado final es coherente— y porque cazaria cualquier cambio que
 * ensanchara esa ventana; pero conviene saber que **no** es ella la que da la
 * señal, para no confiar en un verde que puede ser casualidad.
 */

uses(CommittedDatabase::class);

/** Seis escritores alternando dos cuerpos incompatibles entre si. */
const ESCRITORES_DE_CONFIGURACION = 6;

/**
 * El primer valor de `ATTENDANCE_MAX_SHIFT_HOURS` que escriben los seis, cada
 * uno el suyo: 13, 14, ... 18.
 *
 * **Trece y no ocho, y no es un numero cualquiera.** El valor de serie del
 * catalogo es 12 (RN-08), y un `PATCH` que pide el valor que ya rige responde
 * `200` sin dejar asiento —no ha cambiado nada que apuntar—. Con la serie
 * anterior, 8..13, uno de los seis escritores pedia exactamente 12: si el
 * planificador le daba el candado el primero, no cambiaba nada, y la prueba
 * contaba cinco asientos de seis. Un fallo de uno de cada seis ejecuciones que
 * no dependia del codigo bajo prueba sino de su propio dato.
 *
 * El maximo del catalogo es 24, asi que los seis caben con holgura.
 */
const PRIMER_MAXIMO_DE_TRAMO = 13;

it('nunca deja el idioma por defecto fuera de los idiomas disponibles', function (): void {
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    $respuestas = ParallelRequests::run(
        ESCRITORES_DE_CONFIGURACION,
        static function (int $indice) use ($token): mixed {
            // Los pares dejan la instalacion solo en ingles; los impares
            // intentan poner el castellano por defecto. Cada cuerpo es valido
            // contra el estado que el anterior deja o no deja.
            $cuerpo = $indice % 2 === 0
                ? ['LOCALE_DEFAULT' => 'en', 'LOCALE_AVAILABLE' => ['en']]
                : ['LOCALE_DEFAULT' => 'es', 'LOCALE_AVAILABLE' => ['es', 'en']];

            return Api::as($token)->patch('/api/v1/settings', ['settings' => $cuerpo]);
        },
    );

    $codigos = array_map(static fn (array $r): int => $r['status'], $respuestas);

    // Ni un 500: quien pierde la carrera revalida contra lo confirmado y
    // responde 422, que es lo que quien lo envio puede corregir.
    expect(array_diff($codigos, [200, 422]))->toBe([])
        ->and($codigos)->toContain(200);

    // **La invariante.** Sin candado, este es el estado que se puede persistir.
    // Sin fila rige el valor de serie del catalogo, que es coherente por
    // construccion.
    $default = storedSetting('LOCALE_DEFAULT') ?? 'es';
    $available = storedSetting('LOCALE_AVAILABLE') ?? ['es', 'en'];

    expect($available)->toBeArray()
        ->and($default)->toBeString()
        ->and($available)->toContain($default);
})->group('RF-PD-01', 'RQ-11');

it('no pierde ni duplica asientos cuando varios cambian el mismo umbral a la vez', function (): void {
    // El candado serializa, no descarta: cada escritor que de verdad cambia el
    // valor deja su asiento, y ninguno queda sin el. Un asiento perdido seria un
    // cambio del calculo que nadie puede explicar (regla dura 6).
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
    $valores = range(PRIMER_MAXIMO_DE_TRAMO, PRIMER_MAXIMO_DE_TRAMO + ESCRITORES_DE_CONFIGURACION - 1);

    // La premisa, comprobada y no supuesta: ninguno de los seis pide el valor
    // que ya rige, porque ese no cambiaria nada y no dejaria asiento. Si un dia
    // cambia el valor de serie del catalogo, esto lo dice aqui y con su motivo,
    // en vez de convertirse en «esperaba 6, hubo 5».
    expect($valores)->not->toContain(storedSetting('ATTENDANCE_MAX_SHIFT_HOURS') ?? 12);

    $respuestas = ParallelRequests::run(
        ESCRITORES_DE_CONFIGURACION,
        static fn (int $indice): mixed => Api::as($token)->patch('/api/v1/settings', [
            'settings' => ['ATTENDANCE_MAX_SHIFT_HOURS' => PRIMER_MAXIMO_DE_TRAMO + $indice],
        ]),
    );

    expect(array_map(static fn (array $r): int => $r['status'], $respuestas))
        ->toBe(array_fill(0, ESCRITORES_DE_CONFIGURACION, 200));

    // Seis valores distintos, seis cambios reales, seis asientos. La fila final
    // es la del ultimo que confirmo, y esta entre los seis.
    $asientos = DB::table('audit_log')->where('action', 'calculation_setting.changed')->count();

    expect($asientos)->toBe(ESCRITORES_DE_CONFIGURACION);

    $final = storedSetting('ATTENDANCE_MAX_SHIFT_HOURS');

    expect($final)->toBeInt()
        ->toBeGreaterThanOrEqual(PRIMER_MAXIMO_DE_TRAMO)
        ->toBeLessThanOrEqual(PRIMER_MAXIMO_DE_TRAMO + ESCRITORES_DE_CONFIGURACION - 1);
})->group('RF-PD-01', 'RL-04');

/**
 * El valor guardado de una clave, decodificado, o `null` si no hay fila.
 *
 * Se lee de la tabla y no de la API a proposito: lo que estas pruebas
 * comprueban es lo que quedo PERSISTIDO tras la carrera, y la respuesta de la
 * API resuelve la cascada y taparia una fila incoherente con el valor de serie.
 */
function storedSetting(string $key): mixed
{
    $json = DB::table('installation_settings')->where('key', $key)->value('value');

    return is_string($json) ? json_decode($json, true, 512, JSON_THROW_ON_ERROR) : null;
}
