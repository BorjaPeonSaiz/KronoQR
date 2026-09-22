<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;
use Tests\Architecture\Support\Repo;

/*
 * **NADA DE LO QUE SE SABE DE UNA AUSENCIA PUEDE ACABAR EN UN LOG TECNICO**
 * (regla dura 21, RF-GP-04, decision 6 de la ficha 3.10).
 *
 * ## Por que esta prueba existe y por que es de arquitectura
 *
 * El registro de una baja medica es **dato relativo a la salud** del art. 9 del
 * RGPD. Este producto tiene un sitio donde ese dato puede vivir —`audit_log`,
 * que es el registro legal, con acceso restringido y sin la nota— y dos donde no
 * puede aparecer jamas:
 *
 *   1. **Los logs tecnicos**, que se rotan, se copian y se leen por encima.
 *   2. **`error_events`**, que viaja al fabricante dentro del paquete de
 *      diagnostico (RF-PD-15, ADR-020). Si lleva PII, se ha filtrado.
 *
 * La linea por la que un dato llega al segundo sitio empieza casi siempre en el
 * primero: alguien añade un `Log::debug($absence)` para entender un caso raro y
 * se queda ahi. Por eso la regla se comprueba **sobre el texto de los ficheros**
 * y no sobre el comportamiento: lo que hay que impedir es que la linea se
 * escriba, no que se ejecute.
 *
 * ## La frontera: `Workforce`, y el controlador de la carga como unica excepcion
 *
 * Todo fichero de `app/Modules/Workforce/` cuyo nombre contenga `Absence` no
 * puede nombrar `Log::`, `logger(` ni `report(`. La unica excepcion es
 * `AbsenceImportController`, que **si** registra una linea: cifras, la huella
 * del fichero y nada mas —ni un codigo de empleado, ni un tipo de ausencia, ni
 * una nota, ni el nombre del fichero—. Es lo que permite responder «¿cuando se
 * cargo el cuadrante y cuantas ausencias entraron?» desde un paquete anonimizado.
 *
 * Esa excepcion se declara aqui **con su lista de claves permitidas**, no con un
 * permiso en blanco: si alguien añade `type` o `note` a ese `info()`, esta
 * prueba lo dice.
 */

/** El unico fichero de ausencias que puede escribir en el log, y solo cifras. */
const CONTROLADOR_DE_CARGA = 'Workforce/Http/Controller/AbsenceImportController.php';

/**
 * El comando programado que publica la serie de ausentes de hoy.
 *
 * Escribe por la salida de consola —no por el log— y aun asi entra en esta
 * prueba: lo que sale por `stdout` de un comando programado acaba en el `cron`
 * del servidor del cliente y de ahi en cualquier sitio. Se acota igual que el
 * controlador de la carga: con lista de cadenas permitidas.
 */
const COMANDO_DE_METRICAS = 'Reporting/Infrastructure/Console/AbsenceMetricsCommand.php';

/** El listener que sella los tres hechos en `audit_log`. */
const LISTENER_DE_AUDITORIA = 'Compliance/Infrastructure/Listener/RecordAbsenceChange.php';

/**
 * Las claves que ese unico registro puede llevar. Cifras, modo y huella.
 *
 * @var list<string>
 */
const CLAVES_PERMITIDAS_EN_EL_LOG = [
    'mode',
    'file_sha256',
    'rows',
    'create',
    'unchanged',
    'reject',
    'truncated',
];

/**
 * Todos los ficheros del producto que hablan de ausencias, por su nombre.
 *
 * ## Tres modulos y no uno, desde la revision de la 3.10
 *
 * La primera version solo recorria `Workforce`, y era media frontera: el dato de
 * salud lo tocan tambien **`Reporting`** —que lo agrega en la serie
 * `absences_current{type}` y en las tres columnas del informe por periodo— y
 * **`Compliance`**, que es quien compone el asiento y por tanto quien mas cerca
 * esta de escribir la nota por error.
 *
 * `Absenteeism*` entra ademas de `Absence*`: la regla del informe se llama asi y
 * es la que decide que dias cuentan como no justificados.
 *
 * Se lee el arbol como TEXTO, como el resto de las pruebas de arquitectura: hay
 * que poder hablar de ficheros que ni siquiera se cargan.
 *
 * @return list<string> Rutas relativas a `app/Modules/`.
 */
function ficherosDeAusencias(): array
{
    $ficheros = [];

    foreach (['Workforce', 'Reporting'] as $modulo) {
        foreach (ModuleTree::filesIn($modulo) as $file) {
            $relative = ModuleTree::relative($file, ModuleTree::root());
            $nombre = basename($relative);

            if (str_contains($nombre, 'Absence') || str_contains($nombre, 'Absenteeism')) {
                $ficheros[] = $relative;
            }
        }
    }

    // Y el listener de `Compliance`, que no casa por carpeta porque su modulo
    // tiene muchos mas ficheros de auditoria y solo este habla de ausencias.
    $ficheros[] = LISTENER_DE_AUDITORIA;

    sort($ficheros);

    return $ficheros;
}

it('encuentra los ficheros de ausencias de los tres modulos', function (): void {
    // Red de seguridad de la propia prueba: si el patron dejara de casar
    // —alguien renombra la carpeta, alguien escribe `Ausencia`— las
    // comprobaciones de abajo pasarian recorriendo una lista vacia.
    $ficheros = ficherosDeAusencias();

    expect(\count($ficheros))->toBeGreaterThan(20);

    // Uno por modulo, para que el recorrido no pueda quedarse a medias sin que
    // esta prueba lo vea.
    expect($ficheros)->toContain('Workforce/Domain/Model/Absence.php');
    expect($ficheros)->toContain(CONTROLADOR_DE_CARGA);
    expect($ficheros)->toContain('Reporting/Domain/Policy/AbsenteeismRule.php');
    expect($ficheros)->toContain(COMANDO_DE_METRICAS);
    expect($ficheros)->toContain(LISTENER_DE_AUDITORIA);
})->group('RF-GP-04', 'RS-08');

it('ningun fichero de ausencias escribe en el log, salvo el de la carga', function (): void {
    $infractores = [];

    foreach (ficherosDeAusencias() as $relative) {
        if ($relative === CONTROLADOR_DE_CARGA) {
            continue;
        }

        $codigo = (string) file_get_contents(ModuleTree::root().'/'.$relative);

        foreach (['Log::', 'logger(', 'report(', 'error_log(', 'var_dump('] as $llamada) {
            if (str_contains($codigo, $llamada)) {
                $infractores[] = $relative.' → '.$llamada;
            }
        }
    }

    expect($infractores)->toBe(
        [],
        'Estos ficheros de ausencias escriben en el log: '.implode(', ', $infractores)
        .'. Una baja medica es dato de salud (regla dura 21) y lo que entra en un log tecnico acaba '
        .'en `error_events`, que viaja al fabricante dentro del paquete de diagnostico (ADR-020). '
        .'Lo que haya que dejar escrito va a `audit_log`, por su evento y su listener.',
    );
})->group('RF-GP-04', 'RS-08');

it('el comando de metricas solo escribe frases fijas por la consola', function (): void {
    /*
     * Lo que sale por `stdout` de un comando programado acaba en el `cron` del
     * servidor del cliente, que es un fichero de texto sin rotar ni sanear. El
     * comando recorre la plantilla para contar quien esta ausente hoy **por
     * tipo**, asi que tiene delante exactamente lo que no puede escribir.
     *
     * La regla es la mas estricta posible y por eso se puede comprobar con una
     * expresion regular: **ninguna cadena de las que imprime puede llevar
     * interpolacion ni concatenacion**. Una frase fija no puede filtrar nada.
     */
    $codigo = Repo::contents('backend/app/Modules/'.COMANDO_DE_METRICAS);

    preg_match_all('/\$this->(?:info|line|warn|error|comment)\((.*?)\);/s', $codigo, $llamadas);

    $conDatos = array_values(array_filter(
        $llamadas[1],
        // Una frase fija es `'…'` entera: ni `$`, ni `.`, ni `"` con llaves.
        static fn (string $argumento): bool => preg_match("/^'[^'\\\\$]*'$/", trim($argumento)) !== 1,
    ));

    expect($conDatos)->toBe(
        [],
        'El comando de metricas de ausencias imprime algo que no es una frase fija: '
        .implode(' | ', $conDatos).'. Lo que sale por la consola de un comando programado acaba en el '
        .'`cron` del servidor del cliente, sin rotar ni sanear (regla dura 21). Las cifras van a la '
        .'serie de Prometheus, que es agregada y sin nombres.',
    );
})->group('RF-GP-04', 'RS-08');

it('el listener de auditoria no mete la nota en el asiento', function (): void {
    /*
     * `audit_log` **si** puede llevar el tipo —es el registro legal, con acceso
     * restringido, y sin el el asiento no describe el hecho— pero nunca la nota,
     * que puede ser un diagnostico (decision 6 de la ficha 3.10). El evento ni
     * siquiera la transporta: lleva `hasNote`. Esta prueba fija que nadie
     * reintroduzca la nota por la puerta de atras el dia que el evento cambie.
     */
    $codigo = Repo::contents('backend/app/Modules/'.LISTENER_DE_AUDITORIA);

    foreach (["'note'", '->note', '$note'] as $prohibido) {
        expect(str_contains($codigo, $prohibido))->toBeFalse(
            'El listener de auditoria de ausencias nombra «'.$prohibido.'». El asiento lleva `has_note` '
            .'y nunca el contenido: `audit_log` se conserva cuatro años y se enseña en una inspeccion.',
        );
    }

    // Y la mitad que hace significativa la afirmacion: `has_note` si esta.
    expect(str_contains($codigo, "'has_note'"))->toBeTrue();
})->group('RF-GP-04', 'RS-08');

it('el registro de la carga lleva cifras y la huella, y ninguna clave mas', function (): void {
    // El permiso del controlador NO es en blanco. Si alguien añade `type` o
    // `note` a ese `info()`, el paquete de diagnostico pasaria a llevar la
    // categoria de ausencia de gente identificable por su fichero.
    $codigo = (string) file_get_contents(ModuleTree::root().'/'.CONTROLADOR_DE_CARGA);

    preg_match("/\\\$logger->info\((.*?)\]\);/s", $codigo, $coincidencia);

    $bloque = $coincidencia[1] ?? '';

    expect($bloque)->not->toBe('', 'El controlador de la carga ya no registra lo que esta prueba vigila.');

    preg_match_all("/'([a-z0-9_]+)'\s*=>/", $bloque, $claves);

    $declaradas = array_values(array_unique($claves[1]));

    sort($declaradas);

    $permitidas = CLAVES_PERMITIDAS_EN_EL_LOG;

    sort($permitidas);

    expect($declaradas)->toBe(
        $permitidas,
        'El registro de la carga de ausencias lleva claves que no estan permitidas. Solo cifras, el modo '
        .'y la huella del fichero: ni un codigo de empleado, ni un tipo —una baja medica es dato de '
        .'salud—, ni una nota, ni el nombre del fichero, que lo pone quien sube.',
    );
})->group('RF-GP-04', 'RS-08');

it('ninguna excepcion de ausencias compone su mensaje con el tipo ni con la nota', function (): void {
    /*
     * El mensaje de una excepcion es lo que acaba en `error_events.message`, y
     * de ahi en el paquete de diagnostico. Las excepciones de este dominio
     * nombran el `uuid` publico, las fechas y —solo `AbsenceRequiresNote`— el
     * tipo `other`, que no es dato de salud y sin el el mensaje no explicaria
     * por que hace falta el texto.
     *
     * Lo que **ninguna** puede hacer es interpolar la nota o una propiedad de
     * tipo del modelo.
     */
    $infractores = [];

    foreach (ModuleTree::filesIn('Workforce/Domain/Exception') as $file) {
        $relative = ModuleTree::relative($file, ModuleTree::root());

        if (! str_contains(basename($relative), 'Absence')) {
            continue;
        }

        $codigo = (string) file_get_contents($file);

        foreach (['$note', '->note', '$absence->type', 'employeeName', '$firstName', '$lastName'] as $prohibido) {
            if (str_contains($codigo, $prohibido)) {
                $infractores[] = $relative.' → '.$prohibido;
            }
        }
    }

    expect($infractores)->toBe(
        [],
        'Estas excepciones de ausencias componen su mensaje con datos que no pueden salir: '
        .implode(', ', $infractores).'. El mensaje acaba en `error_events` y de ahi en el paquete de '
        .'diagnostico (regla dura 21).',
    );
})->group('RF-GP-04', 'RS-08');
