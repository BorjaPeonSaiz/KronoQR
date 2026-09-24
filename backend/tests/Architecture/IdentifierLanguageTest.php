<?php

declare(strict_types=1);

use Tests\Architecture\Support\IdentifierLanguage;
use Tests\Architecture\Support\ModuleTree;
use Tests\Architecture\Support\Repo;

/*
 * Los identificadores de `backend/app/` se escriben en ingles (doc 02 §3.5).
 *
 * POR QUE EXISTE. La convencion estaba escrita desde el primer dia y **no la
 * verificaba nadie**, que segun la regla que gobierna esa misma seccion la
 * convertia en una sugerencia. El dano no es estetico: en cuanto convive
 * `Tramo` con `ShiftEntry`, el glosario del doc 01 §13 deja de ser el puente
 * entre el lenguaje del hotel y el del codigo y pasa a ser una traduccion
 * opcional; a partir de ahi hay dos nombres para el mismo concepto y nadie sabe
 * cual de los dos manda.
 *
 * DONDE SE APLICA Y DONDE NO (decision del 24-09-2026). En `backend/app/`, si.
 * En `backend/tests/`, no: alli los ayudantes, las constantes y los conjuntos de
 * datos van en el idioma del escenario, igual que las descripciones de `it()`,
 * porque una prueba fallida tiene que leerse sola. No es una excusa retroactiva
 * —medido: el mismo detector encuentra 1.109 identificadores en espanol en
 * `tests/` y 0 en `app/`—: son dos codigos con dos lectores distintos. La
 * contrapartida en el frontend es la regla `id-match` de ESLint sobre `src/**`
 * de las tres SPA y de `packages/web-kit`.
 *
 * QUE NO ES ESTA PRUEBA. No es un detector de espanol. Es un detector de las
 * veinte palabras del glosario, que son las unicas que tienen traduccion
 * acordada y las unicas que producen el dano de los dos nombres. `$importe` o
 * `$fecha` pasan, y esta bien que pasen: perseguirlos exigiria un diccionario y
 * acabaria con la regla desactivada.
 */

it('escribe en ingles todos los identificadores declarados en app/', function (): void {
    $app = Repo::file('backend/app');

    $offences = IdentifierLanguage::offencesUnder($app);

    expect($offences)->toBe([], 'Hay '.\count($offences).' identificadores de app/ que no estan en ingles. '
        ."El glosario del doc 01 §13 dice como se llaman: tramo -> ShiftEntry, jornada -> WorkDay, \n"
        .'credencial -> Credential, incidencia -> Incident, empleado -> Employee, quiosco -> Kiosk. '
        ."Se renombran; no se anade una excepcion. Los textos que ve una persona van en i18n:\n- "
        .implode("\n- ", $offences));
})->group('RNF-M-06');

it('ha recorrido el arbol entero de app/ y no un directorio a medias', function (): void {
    // Sin esto la prueba anterior pasa en verde el dia que `Repo::file()` apunte
    // a otro sitio o que el recorrido devuelva []. Una regla de arquitectura que
    // no mira nada no falla nunca, que es la unica forma de fallo que no se ve.
    // El suelo es 1.000 y hoy hay 1.542: no es una medida, es un minimo que solo
    // se cruza si algo se ha roto.
    $app = Repo::file('backend/app');

    $files = ModuleTree::phpFilesUnder($app);

    expect(\count($files))->toBeGreaterThan(1000);
})->group('RNF-M-06');

it('denuncia el identificador en espanol', function (string $identifier): void {
    $offence = IdentifierLanguage::offenceOf($identifier);

    expect($offence)->not->toBeNull($identifier.' deberia denunciarse y la regla lo deja pasar.');
})->with([
    'clase con palabra del glosario' => 'Tramo',
    'metodo con la palabra capitalizada' => 'getJornada',
    'variable con la palabra al principio' => 'jornadaDelEmpleado',
    'plural, que es como se escribe de verdad' => 'tramos',
    'plural en -es' => 'credencialesVigentes',
    'conector pegado a la palabra' => 'ConTramo',
    'constante en mayusculas' => 'MAX_JORNADA_HORAS',
    'constante en mayusculas y plural' => 'CODIGOS_DE_AUSENCIAS',
    'sufijo de patron sobre palabra espanola' => 'CredencialRepository',
    'caracter fuera de ASCII' => 'añoFiscal',
])->group('RNF-M-06');

it('deja pasar el identificador correcto en ingles', function (string $identifier): void {
    $offence = IdentifierLanguage::offenceOf($identifier);

    expect($offence)->toBeNull($identifier.' es correcto y la regla lo denuncia: es un falso positivo.');
})->with([
    // Las traducciones que manda el glosario del doc 01 §13.
    'la traduccion de tramo' => 'ShiftEntry',
    'la traduccion de jornada' => 'WorkDay',
    'la traduccion de credencial' => 'CredentialRepository',
    // Palabras inglesas que EMPIEZAN como una palabra del glosario y terminan en
    // minuscula: el limite de segmento del `camelCase` es lo unico que las salva,
    // y si alguien lo quita, estas cuatro se ponen rojas antes que el arbol.
    'Turnover no es Turno' => 'TurnoverRate',
    'centroid no es centro' => 'centroidDistance',
    'Informer no es Informe' => 'InformerAgent',
    'Contract no es Contrato' => 'ContractType',
])->group('RNF-M-06');

it('no intenta detectar espanol en general: un conector suelto no es una infraccion', function (string $connector): void {
    // El limite de la heuristica, escrito como prueba para que sea una decision
    // y no un descuido. `Con`, `Del` o `Para` sueltos son el principio de
    // `Content`, `Delete` y `Parameter`, y denunciarlos habria puesto en rojo
    // medio armazon de Laravel el primer dia.
    $offence = IdentifierLanguage::offenceOf($connector);

    expect($offence)->toBeNull();
})->with(IdentifierLanguage::CONNECTORS)->group('RNF-M-06');

it('no mira dentro de las cadenas, los comentarios ni los docblocks', function (): void {
    // Es la razon de tokenizar en lugar de usar una expresion regular sobre el
    // texto: este fragmento esta lleno de espanol correcto —el porque en un
    // comentario, una clave de i18n, el nombre de una tabla y el de una columna—
    // y no declara ni un identificador infractor.
    $source = <<<'PHP'
        <?php

        /**
         * Una jornada no se parte a medianoche: el tramo es uno solo.
         */
        final class WorkDayTotals
        {
            // La incidencia se traduce por la clave, no por el nombre del empleado.
            public const string TABLE = 'daily_totals';

            public function label(): string
            {
                return __('attendance.jornada_abierta').' '.'shift_entries.occurred_at';
            }
        }
        PHP;

    $declared = IdentifierLanguage::declaredInSource($source);
    $offences = array_filter(array_map(
        static fn (array $d): ?string => IdentifierLanguage::offenceOf($d['name']),
        $declared,
    ));

    expect($offences)->toBe([]);
    expect($declared)->not->toBe([], 'Si no se ha reconocido ningun identificador, el cero de arriba no dice nada.');
})->group('RNF-M-06');

it('no denuncia el caso de un enum respaldado que escribe su propio valor', function (): void {
    // `UserRole::EMPLEADO = 'empleado'` y los codigos del Anexo C
    // (`CorrectionReasonCode`) no son nombres elegidos por nadie: son datos que
    // ya estan en `roles.name` y en `shift_corrections.reason_code`, salen por la
    // API y los enumera el requisito. Traducir el caso dejando el valor produce
    // justo los dos nombres que la regla persigue.
    $source = <<<'PHP'
        <?php

        enum UserRole: string
        {
            case EMPLEADO = 'empleado';
        }
        PHP;

    $declared = IdentifierLanguage::declaredInSource($source);

    // El nombre del enum si se mira —y esta en ingles—; el caso no.
    expect(array_column($declared, 'name'))->toBe(['UserRole']);
})->group('RNF-M-06');

it('denuncia el caso de enum en espanol cuyo nombre no es su propio valor', function (): void {
    // La otra mitad, y la que mantiene estrecha la exclusion de arriba: aqui si
    // hay dos nombres para la misma cosa, que es la unica cosa que se prohibe.
    $source = <<<'PHP'
        <?php

        enum EntryKind: string
        {
            case TRAMO = 'shift_entry';
        }
        PHP;

    $declared = IdentifierLanguage::declaredInSource($source);

    expect(array_column($declared, 'name'))->toBe(['EntryKind', 'TRAMO']);
    expect(IdentifierLanguage::offenceOf('TRAMO'))->not->toBeNull();
})->group('RNF-M-06');

it('denuncia el caso de un enum sin respaldo escrito en espanol', function (): void {
    // Sin valor respaldado no hay dato al que atenerse: el nombre lo eligio
    // alguien, y se elige en ingles.
    $source = <<<'PHP'
        <?php

        enum EntryKind
        {
            case Tramo;
        }
        PHP;

    $declared = IdentifierLanguage::declaredInSource($source);

    expect(array_column($declared, 'name'))->toBe(['EntryKind', 'Tramo']);
    expect(IdentifierLanguage::offenceOf('Tramo'))->not->toBeNull();
})->group('RNF-M-06');
