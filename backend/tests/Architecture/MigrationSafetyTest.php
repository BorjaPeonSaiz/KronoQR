<?php

declare(strict_types=1);

use Tests\Architecture\Support\MigrationSafety;
use Tests\Architecture\Support\Repo;

/*
 * **RNF-D-04: ninguna migracion puede requerir parada de servicio** (patron
 * expand / migrate / contract).
 *
 * ## Por que esto es una prueba y no una nota en el runbook
 *
 * KronoQR se instala en el servidor de cada cliente y se actualiza sin ventana de
 * mantenimiento. Un hotel ficha a las 06:00 y a las 22:00: una migracion que tome
 * `ACCESS EXCLUSIVE` sobre `shift_entries` deja sin fichar a quien este pasando su
 * tarjeta en ese momento, y la regla dura 19 dice que el quiosco nunca bloquea al
 * empleado. Quien despliega no paga ese coste; lo paga quien esta en la puerta de
 * servicio.
 *
 * El error clasico —`ADD COLUMN ... NOT NULL` sin `DEFAULT`— no se ve en una
 * revision de codigo, porque en la base de datos de desarrollo, que suele estar
 * vacia, funciona. Solo falla en la del cliente, que tiene doscientas mil filas.
 *
 * ## Que comprueba y que no
 *
 * **Patrones conocidos, no exhaustividad**, y esa limitacion es deliberada
 * (ver {@see MigrationSafety}). Lo que se garantiza es que los errores clasicos no
 * pasan. Lo que no se garantiza es que no exista una forma creativa de bloquear
 * una tabla, y por eso el runbook de despliegue sigue haciendo falta.
 *
 * ## El detector se prueba a si mismo
 *
 * La primera prueba de este fichero pasaria igual si el detector no encontrara
 * nada nunca —hoy todas las migraciones son correctas—. La segunda existe para
 * eso: le da al detector una migracion con cada error clasico dentro y exige que
 * los señale. Sin ella, esta suite seria una puerta pintada.
 */

/**
 * Las migraciones del repositorio, como conjunto de datos con su nombre.
 *
 * @return array<string, string>
 */
function migrationFiles(): array
{
    $files = glob(Repo::file('backend/database/migrations').'/*.php') ?: [];

    $dataset = [];

    foreach ($files as $file) {
        $dataset[basename($file)] = $file;
    }

    return $dataset;
}

it('encuentra migraciones que analizar', function (): void {
    // El control que impide que todo lo de abajo pase por vacio. Si alguien mueve
    // el directorio de migraciones, la puerta tiene que decirlo en vez de dar por
    // buenas cero migraciones.
    expect(migrationFiles())->not->toBe([]);
})->group('RNF-D-04');

/**
 * **Deuda registrada, no excepciones permanentes.**
 *
 * Estas dos migraciones de la Fase 1 añaden restricciones sobre tablas que YA
 * tienen datos sin `NOT VALID`, asi que la validacion recorre la tabla entera con
 * `ACCESS EXCLUSIVE`. Las encontro este detector al escribirlo — no estaban
 * anotadas en ningun sitio— y la ironia es que una de las dos usa `NOT VALID`
 * correctamente para sus tres `CHECK` y se lo salta justo en la clave ajena.
 *
 * Con una plantilla de seiscientas personas el bloqueo dura milisegundos, asi que
 * no es urgente. Pero RNF-D-04 no dice «que no bloquee mucho», y corregirlas
 * significa reescribir una migracion que quiza ya se ejecuto en algun sitio: es
 * una decision de despliegue y no la toma una prueba.
 *
 * **La lista es un trinquete, no una lista de permitidos**: se comprueba que sea
 * EXACTAMENTE esta. Añadir una migracion infractora falla, y arreglar una de estas
 * dos sin quitarla de aqui tambien. Solo se puede vaciar.
 *
 * @return array<string, list<string>>
 */
function knownMigrationDebt(): array
{
    return [
        '2026_08_20_100100_mint_credential_secret_at_print_time.php' => ['ADD CONSTRAINT sin NOT VALID'],
        '2026_08_20_100200_add_pin_provisioning_to_employees_table.php' => ['ADD CONSTRAINT sin NOT VALID'],
    ];
}

it('no aplica en up() ningun patron que exija parada de servicio', function (string $file): void {
    $up = MigrationSafety::upCodeOf((string) file_get_contents($file));

    $violaciones = MigrationSafety::violationsIn($up);

    $explicacion = [];

    foreach ($violaciones as $nombre) {
        $explicacion[] = $nombre.' → '.MigrationSafety::blockingPatterns()[$nombre]['why'];
    }

    // La deuda registrada se compara valor a valor, no se ignora: una migracion
    // de la lista que empeore —que añada un patron mas— falla igual.
    $esperado = knownMigrationDebt()[basename($file)] ?? [];

    expect($violaciones)->toBe($esperado, basename($file)."\n".implode("\n", $explicacion));
})->with(migrationFiles())->group('RNF-D-04');

it('mantiene la deuda de migraciones acotada a las dos conocidas', function (): void {
    // El trinquete. Sin esta prueba, la lista de arriba seria un cajon donde
    // meter lo que estorbe: cualquiera podria añadir una linea y seguir en verde.
    // Aqui se afirma cuantas son, asi que ampliarla exige tocar esta cifra y
    // explicarlo.
    expect(knownMigrationDebt())->toHaveCount(2);
})->group('RNF-D-04');

it('señala cada error clasico cuando alguien lo comete', function (string $expected, string $up): void {
    // La prueba del detector. Cada caso es la version equivocada de algo que el
    // repositorio hoy hace bien, escrita a mano aqui para no tener que romper una
    // migracion de verdad para comprobarlo.
    expect(MigrationSafety::violationsIn($up))->toContain($expected);
})->with([
    'columna obligatoria sin valor por omision' => [
        'ADD COLUMN NOT NULL sin DEFAULT',
        "DB::statement('ALTER TABLE employees ADD COLUMN pin_hash text NOT NULL');",
    ],
    'columna retirada en la misma version' => [
        'DROP COLUMN en up()',
        "DB::statement('ALTER TABLE credentials DROP COLUMN legacy_token');",
    ],
    'columna retirada con el constructor de esquemas' => [
        'dropColumn de Blueprint en up()',
        "Schema::table('credentials', fn (Blueprint \$t) => \$t->dropColumn('legacy_token'));",
    ],
    'cambio de tipo en sitio' => [
        'ALTER COLUMN TYPE',
        "DB::statement('ALTER TABLE shift_entries ALTER COLUMN duration_minutes TYPE bigint');",
    ],
    'cambio de tipo con el constructor de esquemas' => [
        'change() de Blueprint',
        "Schema::table('shift_entries', fn (Blueprint \$t) => \$t->integer('duration_minutes')->change());",
    ],
    'renombrado de columna' => [
        'RENAME de tabla o de columna',
        "DB::statement('ALTER TABLE shift_entries RENAME COLUMN started_at TO clock_in_at');",
    ],
    'restriccion validada de golpe' => [
        'ADD CONSTRAINT sin NOT VALID',
        "DB::statement('ALTER TABLE employees ADD CONSTRAINT employees_chk_pin CHECK (pin_hash IS NOT NULL)');",
    ],
])->group('RNF-D-04');

it('no confunde con un error el patron expand correcto', function (string $up): void {
    // El control negativo del detector, y el que impide que se vuelva inservible:
    // un analizador que marcara tambien la forma correcta obligaria a silenciarlo,
    // y una puerta silenciada no existe. Estos tres fragmentos son literalmente lo
    // que hacen hoy las migraciones de la Fase 1.
    expect(MigrationSafety::violationsIn($up))->toBe([]);
})->with([
    'columna nullable, relleno, y NOT NULL despues' => [
        "DB::statement('ALTER TABLE credentials ADD COLUMN IF NOT EXISTS uuid uuid');"
        ."DB::statement('UPDATE credentials SET uuid = gen_random_uuid() WHERE uuid IS NULL');"
        ."DB::statement('ALTER TABLE credentials ALTER COLUMN uuid SET NOT NULL');",
    ],
    'columna obligatoria con valor por omision' => [
        "DB::statement('ALTER TABLE employees ADD COLUMN locale text NOT NULL DEFAULT \\'es\\'');",
    ],
    'restriccion NOT VALID y validada aparte' => [
        "DB::statement('ALTER TABLE employees ADD CONSTRAINT employees_chk_pin CHECK (pin_hash IS NOT NULL) NOT VALID');"
        ."DB::statement('ALTER TABLE employees VALIDATE CONSTRAINT employees_chk_pin');",
    ],
    'tabla nueva con columnas obligatorias' => [
        "DB::statement('CREATE TABLE shift_corrections (id bigserial PRIMARY KEY, reason text NOT NULL)');",
    ],
])->group('RNF-D-04');
