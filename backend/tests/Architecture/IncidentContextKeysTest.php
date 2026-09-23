<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;
use Tests\Architecture\Support\Repo;

/*
 * El **catalogo cerrado** de `incidents.context`: que claves puede llevar cada
 * tipo de incidencia y de que tipo es cada una (RS-05, RL-11, RN-18).
 *
 * ## Por que existe
 *
 * `incidents.context` es JSONB y viaja entero: a la bandeja del panel, a la
 * exportacion legal (RL-11) y al asiento de `audit_log` de la apertura. Nada
 * valida lo que se mete ahi —ni el esquema, que solo dice «objeto», ni el
 * contrato, que desde RN-18 admite entero **o** cadena—, asi que una clave nueva
 * entra sin que falle nada y se publica sin que nadie la haya mirado. Si un dia
 * lleva el nombre de alguien, la fuga no aparece en ninguna prueba: aparece en
 * una exportacion.
 *
 * Es la misma idea de {@see ClientErrorContextKeysTest} sobre `error_events`, y
 * la condicion con la que `arquitecto-dominio` ratifico que el contexto admita
 * cadenas: si deja de ser «solo enteros», que la garantia la sostenga una lista
 * revisada y no el tipo.
 *
 * ## Como se extraen las claves
 *
 * **Del codigo, no de una segunda lista escrita a mano.** Se recorren los
 * modulos con `scandir` (ver {@see ModuleTree}, por el *bind mount*), se busca
 * cada `AnomalyType::X` y se lee el **primer array literal** que aparece en la
 * misma sentencia: es donde los dos sitios que construyen hallazgos —
 * `AnomalyDetectionPolicy` y `DetectAttendanceAnomalies`— ponen el contexto.
 *
 * La extraccion es **conservadora**: si una llamada no lleva array literal, no
 * aporta claves. Puede dejar pasar un caso raro; nunca produce un falso
 * positivo, que es lo que convierte una prueba de arquitectura en ruido que
 * alguien acaba silenciando.
 */

/**
 * El catalogo, por tipo de incidencia.
 *
 * `int` o `string`. Las cadenas llevan ademas su longitud maxima, que es la del
 * esquema `IncidentContext` del contrato: sin ella, «cadena» seria una puerta
 * abierta a meter prosa —y la prosa es donde acaban los nombres—.
 *
 * **Anadir una entrada aqui es la revision**: quien la anada tiene que poder
 * decir por que ese dato no identifica a nadie (regla dura 21).
 *
 * @return array<string, array<string, array{type: string, maxLength?: int}>>
 */
function incidentContextCatalogue(): array
{
    $minutos = ['type' => 'int'];

    return [
        // RN-07: cuanto duro el tramo y cual era el minimo computable.
        'SHORT_SHIFT' => ['worked_minutes' => $minutos, 'threshold_minutes' => $minutos],
        // RN-08 y RN-11: lo trabajado y el techo que se paso.
        'LONG_SHIFT' => ['worked_minutes' => $minutos, 'threshold_minutes' => $minutos],
        // RN-12: el tramo continuo y el techo sin pausa.
        'MISSING_BREAK' => ['worked_minutes' => $minutos, 'threshold_minutes' => $minutos],
        // RN-08 sobre un turno que sigue abierto: cuanto lleva.
        'OPEN_SHIFT_EXPIRED' => ['open_minutes' => $minutos, 'threshold_minutes' => $minutos],
        // RN-10: el descanso medido entre jornadas y el minimo legal.
        'INSUFFICIENT_REST' => ['rest_minutes' => $minutos, 'threshold_minutes' => $minutos],
        // RN-15: el desfase CON SIGNO y la tolerancia vigente.
        'CLOCK_SKEW' => ['clock_skew_seconds' => $minutos, 'threshold_seconds' => $minutos],
        // RN-18. Las dos cadenas son con las que una persona encuentra el
        // fichaje en el log para corregirlo: un UUID generado por la tablet y un
        // instante en UTC. Ninguno identifica a nadie.
        'OUT_OF_ORDER_SCAN' => [
            'scan_id' => ['type' => 'string', 'maxLength' => 64],
            'occurred_at' => ['type' => 'string', 'maxLength' => 64],
            'scans' => $minutos,
        ],
        // RF-PR-06 y RN-16 (tarea 3.11). Un solo tipo de incidencia con DOS
        // formas, que `pattern` distingue, asi que esta entrada es la union de
        // las dos. Las cadenas son de tres clases y ninguna identifica a nadie:
        //
        //   - `pattern`, un valor de un catalogo cerrado de dos.
        //   - los instantes y los `scan_id`, con los que una persona encuentra
        //     los fichajes en el log para contrastarlos. Mismo argumento que en
        //     RN-18.
        //   - los rotulos de quiosco. **Un dispositivo no es una persona**: es
        //     el nombre de una sala que pone quien administra, y sin el la
        //     incidencia obliga a traducir un numero a mano. Viaja recortado a
        //     los 64 del esquema `IncidentContext` —`devices.name` admite 120—
        //     por {@see \App\Modules\Attendance\Domain\ValueObject\CredentialScan}.
        //
        // `counterpart_employee_uuid` es la otra persona del par, como UUID: sin
        // ella la incidencia no se puede revisar —nadie sabria con quien se
        // coincidio—, y el nombre lo resuelve el panel con su directorio.
        'ANOMALOUS_PATTERN' => [
            'pattern' => ['type' => 'string', 'maxLength' => 32],
            'device_id' => $minutos,
            'device_name' => ['type' => 'string', 'maxLength' => 64],
            'counterpart_employee_uuid' => ['type' => 'string', 'maxLength' => 64],
            'counterpart_count' => $minutos,
            'coincidence_days' => $minutos,
            'window_seconds' => $minutos,
            'min_repeats' => $minutos,
            'first_coincidence_at' => ['type' => 'string', 'maxLength' => 64],
            'last_coincidence_at' => ['type' => 'string', 'maxLength' => 64],
            'last_gap_seconds' => $minutos,
            'min_gap_seconds' => $minutos,
            'from_device_id' => $minutos,
            'from_device_name' => ['type' => 'string', 'maxLength' => 64],
            'to_device_id' => $minutos,
            'to_device_name' => ['type' => 'string', 'maxLength' => 64],
            'first_occurred_at' => ['type' => 'string', 'maxLength' => 64],
            'second_occurred_at' => ['type' => 'string', 'maxLength' => 64],
            'gap_seconds' => $minutos,
            'transit_seconds' => $minutos,
            'first_scan_id' => ['type' => 'string', 'maxLength' => 64],
            'second_scan_id' => ['type' => 'string', 'maxLength' => 64],
        ],
    ];
}

/**
 * Las claves que llevan «name» y SI pueden estar, con su justificacion.
 *
 * La lista negra de abajo existe para que un `employee_name` no entre en
 * `incidents.context` sin que falle nada. Estas tres son rotulos de
 * **dispositivo** —`devices.name`, el nombre de la sala donde esta la tablet—,
 * no de persona: sin ellos, la incidencia de RF-PR-06 obliga a quien la revisa a
 * traducir un identificador numerico a mano, que es justo lo que el runbook
 * `patron-anomalo-credencial.md` intenta evitar.
 *
 * **Es una lista cerrada y se declara aqui**, no un patron: la unica forma de
 * anadir una es escribir por que ese dato no identifica a nadie, que es en lo
 * que consiste la revision.
 *
 * @return list<string>
 */
function contextKeysAllowedToCarryAName(): array
{
    return ['device_name', 'from_device_name', 'to_device_name'];
}

/**
 * Palabras que no pueden aparecer **nunca** en una clave de contexto.
 *
 * No es una comprobacion de tipos: es la regla dura 21 escrita como prueba. Una
 * clave que se llame `employee_name` o `email` no falla en ningun sitio y sale
 * en la exportacion del cliente.
 *
 * @return list<string>
 */
function forbiddenContextFragments(): array
{
    return ['name', 'nombre', 'email', 'correo', 'phone', 'telefono', 'dni', 'nif', 'employee_code', 'address'];
}

/**
 * Los ficheros que pueden construir un hallazgo.
 *
 * @return list<string>
 */
function anomalySourceFiles(): array
{
    return [
        ...ModuleTree::filesIn('Attendance'),
        ...ModuleTree::filesIn('Compliance'),
    ];
}

/**
 * Claves de contexto encontradas en el codigo, por nombre de caso de
 * `AnomalyType`.
 *
 * @return array<string, list<string>>
 */
function incidentContextKeysInCode(): array
{
    $found = [];

    foreach (anomalySourceFiles() as $file) {
        $code = withoutComments((string) file_get_contents($file));

        foreach (contextLiteralsIn($code) as [$type, $keys]) {
            foreach ($keys as $key) {
                $found[$type][] = $key;
            }
        }
    }

    foreach ($found as $type => $keys) {
        $unique = array_values(array_unique($keys));
        sort($unique);
        $found[$type] = $unique;
    }

    ksort($found);

    return $found;
}

/**
 * El mismo fuente **sin comentarios**.
 *
 * NO ES COSMETICA: {@see arrayLiteralsAfter()} acota la busqueda al primer `;`,
 * y un punto y coma escrito dentro de un comentario **en medio de la llamada que
 * construye el hallazgo** cortaba la sentencia antes del contexto. El efecto no
 * era un fallo ruidoso: era que ese tipo de incidencia dejaba de aportar claves
 * y la comprobacion de privacidad se apagaba sola para el. Paso de verdad con
 * `anomalous_pattern` (tarea 3.11), y lo unico que lo delato fue la
 * comprobacion en la otra direccion.
 *
 * Se conservan los saltos de linea para no mover los numeros de linea.
 */
function withoutComments(string $code): string
{
    $clean = '';

    foreach (token_get_all($code) as $token) {
        if (! \is_array($token)) {
            $clean .= $token;

            continue;
        }

        $clean .= \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
            ? str_repeat("\n", substr_count($token[1], "\n"))
            : $token[1];
    }

    return $clean;
}

/**
 * Cada `AnomalyType::X` del codigo con las claves del primer array literal que
 * le sigue en la misma sentencia.
 *
 * @return list<array{0: string, 1: list<string>}>
 */
function contextLiteralsIn(string $code): array
{
    $literals = [];
    $offset = 0;

    while (preg_match('/AnomalyType::([A-Z_]+)/', $code, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $type = (string) $match[1][0];
        $from = (int) $match[0][1] + \strlen((string) $match[0][0]);
        $offset = $from;

        $keys = [];

        foreach (arrayLiteralsAfter($code, $from) as $literal) {
            if (preg_match_all("/'([a-z0-9_]+)'\\s*=>/", $literal, $found) === false) {
                continue;
            }

            /** @var list<non-empty-string> $captured */
            $captured = $found[1];
            $keys = [...$keys, ...$captured];
        }

        if ($keys !== []) {
            $literals[] = [$type, $keys];
        }
    }

    return $literals;
}

/**
 * **Todos** los corchetes equilibrados que abren despues de `$from` y antes de
 * que termine la sentencia.
 *
 * Todos y no el primero: la llamada que construye el hallazgo lleva otros
 * corchetes por delante —`$group['employeeUuid']`— y quedarse con el primero
 * dejaba fuera justo el contexto. Los que no son un array asociativo no aportan
 * ninguna clave y se descartan solos, sin tener que distinguirlos aqui.
 *
 * El corte por `;` es lo que hace conservadora la extraccion: sin el, un
 * `AnomalyType::X` suelto —una comparacion, un `match`— se llevaria el array de
 * la sentencia siguiente.
 *
 * @return list<string>
 */
function arrayLiteralsAfter(string $code, int $from): array
{
    $end = strpos($code, ';', $from);
    $limit = $end === false ? \strlen($code) : $end;

    $literals = [];
    $i = $from;

    while ($i < $limit) {
        if ($code[$i] !== '[') {
            $i++;

            continue;
        }

        $depth = 0;

        for ($j = $i; $j < $limit; $j++) {
            if ($code[$j] === '[') {
                $depth++;
            }

            if ($code[$j] === ']') {
                $depth--;

                if ($depth === 0) {
                    $literals[] = substr($code, $i, $j - $i + 1);
                    // Se salta el literal entero: los anidados ya van dentro.
                    $i = $j;

                    break;
                }
            }
        }

        $i++;
    }

    return $literals;
}

it('encuentra hallazgos que analizar, o esta prueba no comprueba nada', function (): void {
    // La red bajo la red, igual que en `ClientErrorContextKeysTest`: si la
    // extraccion dejara de encontrar codigo, todo lo de abajo pasaria vacio.
    expect(anomalySourceFiles())->not->toBeEmpty('No hay ficheros de modulo que analizar.')
        ->and(incidentContextKeysInCode())->not->toBeEmpty('No se ha extraido ninguna clave de contexto del codigo.');
})->group('RS-05', 'RL-11', 'RN-18');

it('no escribe en el contexto ninguna clave que el catalogo no declare', function (): void {
    // El fallo que impide: una clave nueva entra en `incidents.context` sin que
    // falle nada y se publica en la bandeja, en el asiento y en la exportacion
    // legal. Aqui deja de ser silenciosa.
    $catalogue = incidentContextCatalogue();
    $fuera = [];

    foreach (incidentContextKeysInCode() as $type => $keys) {
        foreach ($keys as $key) {
            if (! isset($catalogue[$type][$key])) {
                $fuera[] = $type.'.'.$key;
            }
        }
    }

    expect($fuera)->toBe([], \count($fuera).' clave(s) de `incidents.context` sin declarar en el catalogo de '
        .'esta prueba: '.implode(', ', $fuera).'. Declararla es la revision: di por que ese dato no identifica a nadie.');
})->group('RS-05', 'RL-11', 'RN-18');

it('no declara en el catalogo ninguna clave que nadie escriba', function (): void {
    // La otra direccion. Una entrada inventada no rompe nada y hace creer que el
    // contexto lleva algo que no lleva, que es como esta prueba se convierte en
    // documentacion falsa.
    $enCodigo = incidentContextKeysInCode();
    $sobran = [];

    foreach (incidentContextCatalogue() as $type => $keys) {
        foreach (array_keys($keys) as $key) {
            if (! \in_array($key, $enCodigo[$type] ?? [], true)) {
                $sobran[] = $type.'.'.$key;
            }
        }
    }

    expect($sobran)->toBe([], \count($sobran).' clave(s) declaradas que no escribe nadie: '.implode(', ', $sobran));
})->group('RS-05', 'RL-11', 'RN-18');

it('declara solo enteros y cadenas acotadas, como admite el contrato', function (): void {
    // El contrato (`IncidentContext`) admite `anyOf: [integer, string]` desde
    // RN-18. Lo que impide que «cadena» se convierta en «cualquier cosa» es esta
    // comprobacion: toda cadena declara su longitud maxima y ninguna pasa de la
    // del esquema.
    $invalidas = [];

    foreach (incidentContextCatalogue() as $type => $keys) {
        foreach ($keys as $key => $rule) {
            if (! \in_array($rule['type'], ['int', 'string'], true)) {
                $invalidas[] = $type.'.'.$key.' declara el tipo '.$rule['type'];

                continue;
            }

            if ($rule['type'] === 'string' && ($rule['maxLength'] ?? 0) > 64) {
                $invalidas[] = $type.'.'.$key.' declara una cadena de mas de 64';
            }

            if ($rule['type'] === 'string' && ! isset($rule['maxLength'])) {
                $invalidas[] = $type.'.'.$key.' es una cadena sin longitud maxima';
            }
        }
    }

    expect($invalidas)->toBe([], implode('; ', $invalidas));
})->group('RS-05', 'RL-11', 'RN-18');

it('no admite en el contexto ninguna clave que suene a dato personal', function (): void {
    // Regla dura 21 escrita como prueba, y sobre las dos listas: la del catalogo
    // y la del codigo. Un `employee_name` en el contexto no falla en ningun
    // sitio; sale en la exportacion del cliente.
    $sospechosas = [];

    $claves = [];

    foreach (incidentContextCatalogue() as $keys) {
        $claves = [...$claves, ...array_keys($keys)];
    }

    foreach (incidentContextKeysInCode() as $keys) {
        $claves = [...$claves, ...$keys];
    }

    foreach (array_unique($claves) as $key) {
        if (\in_array($key, contextKeysAllowedToCarryAName(), true)) {
            continue;
        }

        foreach (forbiddenContextFragments() as $fragment) {
            if (str_contains($key, $fragment)) {
                $sospechosas[] = $key.' contiene «'.$fragment.'»';
            }
        }
    }

    expect($sospechosas)->toBe([], implode('; ', $sospechosas));
})->group('RS-05', 'RL-11', 'RN-18');

it('mantiene el contrato de IncidentContext abierto a entero y cadena, y a nada mas', function (): void {
    // El catalogo de arriba solo vale si el contrato dice lo mismo: si alguien
    // ampliara `IncidentContext` a objetos o listas, la lista de claves seguiria
    // en verde mientras el contexto real deja de ser escalar.
    $contract = (string) file_get_contents(Repo::root().'/docs/api/openapi.yaml');
    $schema = strstr($contract, '    IncidentContext:');

    expect($schema !== false)->toBeTrue('No se encuentra el esquema IncidentContext en el contrato.');

    $fragment = (string) substr((string) $schema, 0, 4000);

    expect($fragment)->toContain('additionalProperties:')
        ->toContain('- type: integer')
        ->toContain('- type: string')
        ->toContain('maxLength: 64');

    // Los dos negativos con `str_contains` y no con `->not->toContain()`: sobre
    // una expectativa de `string|null` el analisis estatico no resuelve `->not`
    // (PHPStan 9), y una asercion que no compila no protege nada.
    expect(str_contains($fragment, '- type: object'))->toBeFalse('IncidentContext admite objetos: deja de ser escalar.')
        ->and(str_contains($fragment, '- type: array'))->toBeFalse('IncidentContext admite listas: deja de ser escalar.');
})->group('RS-05', 'RN-18');
