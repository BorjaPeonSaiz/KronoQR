<?php

declare(strict_types=1);

use App\Modules\Shared\Infrastructure\Export\CsvDialect;

/*
 * Las cinco decisiones de codificacion del CSV del producto (RL-06, RF-IN-05,
 * RF-ID-05, RL-05).
 *
 * **Por que se prueban los bytes y no la clase.** Lo que se entrega a la
 * Inspeccion de Trabajo y lo que un empleado se descarga del portal son ficheros:
 * lo unico que importa de esta clase es la secuencia exacta de caracteres que
 * deja en el manejador. Una prueba que afirmara `CsvDialect::DELIMITER === ';'`
 * no comprobaria nada — repetiria la constante.
 *
 * Cada caso de abajo describe un modo de fallo que ya ha ocurrido o que rompe el
 * fichero en la hoja de calculo de quien lo abre.
 */

/**
 * Escribe unas filas en memoria y devuelve los bytes resultantes.
 *
 * `php://memory` y no un fichero temporal: no hay que limpiar nada y la prueba no
 * depende del disco.
 *
 * @param  list<array<array-key, string>>  $rows
 */
function csvBytes(array $rows, bool $withBom = false): string
{
    $handle = fopen('php://memory', 'r+');

    expect($handle)->not->toBeFalse();

    /** @var resource $handle */
    if ($withBom) {
        CsvDialect::writeByteOrderMark($handle);
    }

    foreach ($rows as $row) {
        CsvDialect::writeRow($handle, $row);
    }

    rewind($handle);
    $bytes = stream_get_contents($handle);
    fclose($handle);

    return is_string($bytes) ? $bytes : '';
}

it('separa las celdas con punto y coma y termina la linea con CRLF', function (): void {
    // El fin de linea es el hallazgo que motivo esta clase: los dos escritores
    // del producto habian divergido en `\n` frente a `\r\n` sin que nada fallara.
    // Se escribe el literal esperado byte a byte, no una constante de la clase
    // bajo prueba.
    expect(csvBytes([['Fecha', 'Entrada', 'Duracion']]))
        ->toBe("Fecha;Entrada;Duracion\r\n");
})->group('RL-06', 'RF-IN-05');

it('entrecomilla un campo que lleva el separador dentro', function (): void {
    // Sin comillas, un departamento llamado «Recepcion; turno de noche» partiria
    // la fila en dos columnas y descuadraria todas las siguientes.
    expect(csvBytes([['Recepcion; turno de noche', '8']]))
        ->toBe("\"Recepcion; turno de noche\";8\r\n");
})->group('RL-06');

it('duplica la comilla doble embebida, como manda el RFC 4180', function (): void {
    // La forma estandar de escapar una comilla dentro de un campo es repetirla.
    // Cualquier hoja de calculo la entiende; la barra invertida de PHP, no.
    expect(csvBytes([['Motivo "urgente"', 'x']]))
        ->toBe("\"Motivo \"\"urgente\"\"\";x\r\n");
})->group('RL-06');

it('no aplica el escapado propietario de PHP a un motivo con barra invertida', function (): void {
    // El caso concreto que el docblock de la clase señala: un `reason_text` de una
    // correccion (RN-13) que contenga `\"`. Con el escapado por omision de PHP,
    // la barra invertida se cuela en el fichero y la comilla deja de duplicarse,
    // asi que el campo se rompe en cualquier lector conforme al RFC.
    //
    // El valor entra en PHP como  \"  y tiene que salir como  "\""" :
    // la barra se conserva literal y la comilla se duplica.
    expect(csvBytes([['\\"', 'x']]))
        ->toBe("\"\\\"\"\";x\r\n");
})->group('RL-06', 'RN-13');

it('deja intacto un campo corriente, sin comillas de mas', function (): void {
    // El control negativo del entrecomillado: si la clase encomillara todo, los
    // tres casos de arriba pasarian igual y no comprobarian nada.
    expect(csvBytes([['Amrani', '2026-03-29']]))
        ->toBe("Amrani;2026-03-29\r\n");
})->group('RL-06');

it('escribe una linea en blanco cuando la fila esta vacia', function (): void {
    // Es lo que separa el bloque de criterios de la tabla en la exportacion legal
    // y lo que permite que Excel reconozca la tabla al seleccionarla.
    expect(csvBytes([['Criterio', 'valor'], [], ['Fecha']]))
        ->toBe("Criterio;valor\r\n\r\nFecha\r\n");
})->group('RL-06', 'RF-IN-05');

it('antepone la marca de orden de bytes y nada mas', function (): void {
    // Sin BOM, Excel con configuracion regional española lee el fichero en
    // Windows-1252 y los apellidos con tilde salen rotos. Un documento con
    // efectos legales con los apellidos rotos no se entrega.
    expect(csvBytes([['Duracion']], withBom: true))
        ->toBe("\xEF\xBB\xBF"."Duracion\r\n");
})->group('RL-06', 'RF-IN-05');

it('neutraliza una celda que Excel ejecutaria como formula', function (string $celda): void {
    // Inyeccion de formulas CSV (OWASP): el texto libre lo escriben personas
    // —el motivo de una correccion, un apellido— y el fichero lo abre un
    // tercero: la Inspeccion, RRHH, el propio empleado. Sin la comilla simple
    // delante, `=HYPERLINK(...)` se ejecutaria en su hoja de calculo. La
    // comilla es la marca de texto de Excel: no se muestra en la celda.
    expect(csvBytes([[$celda, 'x']]))
        ->toBe("'".$celda.';x'."\r\n");
})->with([
    // Sin comillas dobles, punto y coma NI ESPACIOS a proposito: cualquiera
    // de los tres hace que fputcsv entrecomille el campo y la comparacion
    // byte a byte dejaria de leerse. El caso entrecomillado esta en la prueba
    // del tabulador.
    'igual' => '=HYPERLINK(A1)',
    'mas' => '+2+5+cmd|/C_calc!A0',
    'menos no numerico' => '-2+3+cmd|/C_calc!A0',
    'arroba' => '@SUM(1+9)*cmd|/C_calc!A0',
])->group('RL-06', 'RF-IN-05', 'RF-ID-05');

it('neutraliza tambien el tabulador y el retorno de carro iniciales', function (): void {
    // Los dos disparadores restantes no caben en el dataset de arriba porque
    // fputcsv los entrecomilla: aqui se afirma que la comilla simple queda
    // DENTRO del campo entrecomillado, que es donde Excel la lee.
    expect(csvBytes([["\t=1+1", 'x']]))
        ->toBe("\"'\t=1+1\";x\r\n");

    expect(csvBytes([["\r=1+1", 'x']]))
        ->toBe("\"'\r=1+1\";x\r\n");
})->group('RL-06', 'RF-IN-05');

it('deja intacto un numero negativo puro', function (): void {
    // `-30` es un numero, no una formula. Anteponerle la comilla lo
    // convertiria en texto y las sumas de quien recibe el fichero dejarian de
    // cuadrar — en el documento que se entrega a la Inspeccion.
    expect(csvBytes([['-30', '-1,5', '-2.75']]))
        ->toBe("-30;-1,5;-2.75\r\n");
})->group('RL-06', 'RF-IN-05');

it('no toca una formula que aparece en mitad de la celda', function (): void {
    // El disparador de Excel es el PRIMER caracter. Neutralizar apariciones
    // interiores corromperia texto legitimo («Ajuste =pactado=») sin ganar
    // nada: Excel no las ejecuta. Las comillas del literal esperado son el
    // entrecomillado por espacios de fputcsv, no de la neutralizacion.
    expect(csvBytes([['Ajuste =pactado= con RRHH', 'x']]))
        ->toBe("\"Ajuste =pactado= con RRHH\";x\r\n");
})->group('RL-06');

it('conserva el orden de las celdas aunque el array venga con claves', function (): void {
    // Los escritores componen sus filas con claves para leerse mejor. Si la clase
    // no hiciera `array_values()`, `fputcsv` seguiria escribiendo los valores en
    // orden, pero la promesa quedaria a merced de la implementacion: aqui queda
    // fijada.
    expect(csvBytes([['fecha' => '2026-03-29', 'minutos' => '480']]))
        ->toBe("2026-03-29;480\r\n");
})->group('RL-06');
