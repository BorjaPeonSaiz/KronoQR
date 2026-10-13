<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El suelo legal de `compliance_profiles.retention_years` en España, y la forma
 * de `jurisdiction`, declarados en el esquema (RL-02, RF-PD-07, ADR-057
 * enmienda del 10-10-2026, bloque 5 de la 2.2.1).
 *
 * ## Enmienda el «no hay tope inferior» de `2026_09_06_100000`
 *
 * Aquella migracion no puso suelo a `retention_years` porque `1` es un error
 * tipografico peligroso en España y un valor legitimo en otra jurisdiccion, y
 * el esquema no podia distinguirlos. Con la jurisdiccion si puede: el art. 34.9
 * ET obliga a conservar el registro **cuatro años**, y un perfil cuyo pais es
 * `ES` no admite menos. Aquel fichero no se reescribe (ya esta aplicado en
 * toda instalacion): solo gana una linea que remite aqui.
 *
 * ## Dos `CHECK`, y por que dos
 *
 * - `compliance_profiles_chk_jurisdiction_format`: ISO 3166-1 alfa-2 con
 *   subdivision ISO 3166-2 opcional (`ES`, `ES-CN`). Sin el, `es` o `ESP`
 *   esquivarian el suelo español; con el, PHP (`Jurisdiction::country()`) y SQL
 *   (`split_part(…, '-', 1)`) leen el pais igual sin normalizar nada.
 * - `compliance_profiles_chk_retention_floor_es`: `ES` y sus subdivisiones, al
 *   menos 4 años.
 *
 * **El dato esta duplicado a proposito.** El catalogo de suelos
 * (`config/compliance.php`, `retention_floors`) manda para PHP; este `CHECK` es
 * la red de quien edita la fila a mano. Cada pais del catalogo con suelo mayor
 * que `default` tiene su `compliance_profiles_chk_retention_floor_<pais>`, y la
 * prueba de integracion «el catalogo y el esquema dicen lo mismo» pone en rojo
 * un pais añadido al catalogo sin su migracion.
 *
 * ## La comprobacion previa, y su mensaje
 *
 * Antes de validar se buscan filas que incumplirian. Si hay alguna, la
 * migracion falla con un mensaje que nombra el perfil y dice como arreglarlo, y
 * la transaccion revierte: **no se ha cambiado nada**. `update.sh --check-only`
 * hace la misma consulta contra la base actual antes de empezar, asi que en una
 * actualizacion normal este fallo no llega a verse.
 *
 * Se comprueba **despues** de añadir las restricciones `NOT VALID` y dentro de
 * la misma transaccion: el `ACCESS EXCLUSIVE` del `ADD` impide que una
 * escritura cuele una fila mala entre la comprobacion y el `VALIDATE`, y desde
 * el `ADD` toda fila nueva ya se comprueba.
 *
 * ## Patron `/migracion-segura`: un paso EXPAND puro
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Esta migracion: dos `CHECK` `NOT VALID`, validados fuera de la transaccion. Nada se renombra, nada se borra. | La version anterior ya escribia filas validas: el panel no deja cambiar `jurisdiction` y el perfil de serie es `ES` con 4. |
 * | **2 (migrate)** | *No aplica.* | — |
 * | **3 (contract)** | *No aplica.* | — |
 *
 * ## `down()`
 *
 * Suelta las dos restricciones. No toca datos: la vuelta atras es total.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /**
     * Los `VALIDATE` van fuera de la transaccion que crea los `CHECK`: ver
     * {@see LimitsMigrationLocks}.
     *
     * @var bool
     */
    public $withinTransaction = false;

    /** @var array<string, string> Nombre => expresion de cada `CHECK`, en el orden en que se crean. */
    private const array CHECKS = [
        'compliance_profiles_chk_jurisdiction_format' => "jurisdiction ~ '^[A-Z]{2}(-[A-Z0-9]{1,3})?\$'",
        'compliance_profiles_chk_retention_floor_es' => "split_part(jurisdiction, '-', 1) <> 'ES' OR retention_years >= 4",
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            // Idempotente: si un `VALIDATE` se interrumpe, el siguiente
            // `migrate` vuelve a entrar aqui.
            foreach (self::CHECKS as $name => $expression) {
                DB::statement('ALTER TABLE compliance_profiles DROP CONSTRAINT IF EXISTS '.$name);
                DB::statement('ALTER TABLE compliance_profiles ADD CONSTRAINT '.$name.' CHECK ('.$expression.') NOT VALID');
            }

            $this->assertEveryProfileWithinTheFloor();
        });

        foreach (array_keys(self::CHECKS) as $name) {
            $this->validateConstraint('compliance_profiles', $name);
        }
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            foreach (array_reverse(array_keys(self::CHECKS)) as $name) {
                DB::statement('ALTER TABLE compliance_profiles DROP CONSTRAINT IF EXISTS '.$name);
            }
        });
    }

    /**
     * La misma consulta que `update.sh --check-only`. La primera fila que
     * incumple aborta con su mensaje; la excepcion revierte la transaccion.
     */
    private function assertEveryProfileWithinTheFloor(): void
    {
        /** @var list<object{id: int, jurisdiction: string, retention_years: int}> $rows */
        $rows = DB::select(<<<'SQL'
            SELECT id, jurisdiction, retention_years
              FROM compliance_profiles
             WHERE jurisdiction !~ '^[A-Z]{2}(-[A-Z0-9]{1,3})?$'
                OR (split_part(jurisdiction, '-', 1) = 'ES' AND retention_years < 4)
             ORDER BY id
            SQL);

        foreach ($rows as $row) {
            if (preg_match('/\A[A-Z]{2}(-[A-Z0-9]{1,3})?\z/', $row->jurisdiction) !== 1) {
                throw new RuntimeException(
                    'compliance_profiles: la jurisdicción «'.$row->jurisdiction.'» del perfil #'.$row->id
                    .' no tiene forma ISO 3166 (ES, ES-CN). Corrígela con el rol de migración antes de '
                    .'actualizar. No se ha cambiado nada.'
                );
            }

            throw new RuntimeException(
                'compliance_profiles: el perfil #'.$row->id.' ('.$row->jurisdiction.') conserva '
                .$row->retention_years.' años y el suelo legal de ES es 4 (RL-02, art. 34.9 ET). Súbelo '
                .'desde el panel (Configuración → Perfil de cumplimiento) y vuelve a actualizar. No se ha '
                .'cambiado nada.'
            );
        }
    }
};
