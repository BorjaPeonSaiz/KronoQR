<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\ValueObject\AbsenceCategory;
use App\Modules\Workforce\Domain\Model\Absence;
use App\Modules\Workforce\Domain\ValueObject\AbsenceType;
use Tests\Architecture\Support\Repo;

/*
 * **EL CATALOGO DE TIPOS DE AUSENCIA ESTA ESCRITO TRES VECES, Y LAS TRES TIENEN
 * QUE DECIR LO MISMO** (RF-GP-04, decision 2 de la ficha 3.10).
 *
 * ## Por que hay tres copias y por que ninguna sobra
 *
 *   1. `Workforce\Domain\ValueObject\AbsenceType` — el catalogo del dominio que
 *      registra la ausencia.
 *   2. `Reporting\Domain\ValueObject\AbsenceCategory` — el mismo catalogo visto
 *      por el informe y por la metrica. Existe aparte porque **`Reporting` no
 *      puede importar nada de `Workforce`** (doc 02 §1.6, y Deptrac lo verifica).
 *   3. La constante `TYPES` de la migracion `2026_09_22_100000_absences`, que
 *      compone el `CHECK` de la columna. Existe aparte porque **el esquema de
 *      una instalacion no puede depender de una clase de la aplicacion**: una
 *      base de datos restaurada tiene que valer sin el codigo al lado.
 *
 * ## Que pasa si se separan, y por que no se notaria
 *
 * Nada, durante meses. Si `Workforce` admitiera un quinto tipo que el `CHECK` no
 * conoce, el alta reventaria con un `500`; si el `CHECK` admitiera uno que
 * `Reporting` no conoce, **el informe dejaria de contar esos dias como
 * justificados sin decir nada** y el absentismo de esa persona subiria sin
 * causa visible. Ninguno de los dos fallos apunta a su origen.
 *
 * La migracion se lee como TEXTO —con la expresion regular de su constante— y no
 * se ejecuta: es una prueba de arquitectura y tiene que poder hablar de un
 * fichero que ni siquiera se carga.
 */

/** La migracion que crea la tabla; el nombre es corto por el precedente de contratos. */
const MIGRACION_DE_AUSENCIAS = 'backend/database/migrations/2026_09_22_100000_absences.php';

/**
 * Los valores de la constante `TYPES` de la migracion, en orden.
 *
 * @return list<string>
 */
function tiposDelEsquema(): array
{
    $codigo = Repo::contents(MIGRACION_DE_AUSENCIAS);

    preg_match('/private const array TYPES = \[(.*?)\];/s', $codigo, $bloque);

    preg_match_all("/'([a-z_]+)'/", $bloque[1] ?? '', $valores);

    return $valores[1];
}

it('encuentra la constante del esquema, para que la comparacion signifique algo', function (): void {
    // Red de seguridad de la propia prueba: si alguien renombra la constante o
    // compone la lista de otra forma, las comparaciones de abajo pasarian
    // comparando contra un array vacio.
    expect(tiposDelEsquema())->not->toBe([]);
})->group('RF-GP-04');

it('el catalogo del dominio dice lo mismo que el CHECK de la columna', function (): void {
    expect(AbsenceType::names())->toBe(
        tiposDelEsquema(),
        'El enum `AbsenceType` y la constante `TYPES` de la migracion se han separado. Un tipo que el '
        .'dominio admite y el `CHECK` no reventaria el alta con un `500`; al reves, una fila escrita por '
        .'fuera de la API no se podria leer.',
    );
})->group('RF-GP-04');

it('el catalogo del informe dice lo mismo que el CHECK de la columna', function (): void {
    // La comparacion va contra el ESQUEMA y no contra `AbsenceType`, y no es un
    // rodeo: Deptrac prohibe que `Reporting` importe nada de `Workforce`, asi
    // que la migracion es el unico sitio que los dos modulos pueden nombrar sin
    // cruzar la frontera — y es ademas el que decide que filas existen.
    expect(AbsenceCategory::values())->toBe(
        tiposDelEsquema(),
        'El enum `AbsenceCategory` de `Reporting` y la constante `TYPES` de la migracion se han '
        .'separado. Un tipo que el esquema admite y el informe no conoce deja de contar como ausencia '
        .'justificada **en silencio**: el absentismo de esa persona sube sin causa visible.',
    );
})->group('RF-GP-04');

it('el techo de la nota es el mismo en el dominio y en el esquema', function (): void {
    /*
     * La cuarta copia del mismo problema, y la que destapo la revision de
     * seguridad: la carga por fichero no pasa por el `FormRequest`, asi que si
     * el techo del dominio fuera mayor que el del `CHECK`, una nota demasiado
     * larga llegaria al `INSERT` y el mensaje de la `QueryException` —con la
     * nota entera en los bindings— acabaria en `storage/logs` (regla dura 21).
     */
    $codigo = Repo::contents(MIGRACION_DE_AUSENCIAS);

    preg_match('/private const int MAX_TEXT = (\d+);/', $codigo, $coincidencia);

    expect($coincidencia[1] ?? '')->toBe(
        (string) Absence::MAX_NOTE_LENGTH,
        'El techo de la nota del dominio y el del `CHECK` de la columna se han separado. Si el del '
        .'dominio es mayor, una nota demasiado larga llega al motor y su contenido acaba en el mensaje '
        .'de la excepcion, que se escribe en el log tecnico sin sanear.',
    );
})->group('RF-GP-04', 'RS-08');
