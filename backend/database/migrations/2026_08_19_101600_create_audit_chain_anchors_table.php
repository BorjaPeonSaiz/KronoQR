<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `audit_chain_anchors` — el sello de cada particion de `audit_log` antes de
 * soltarla (doc 01 §5.5, ADR-027).
 *
 * **Que problema resuelve.** A los cuatro años (RL-02) la particion mas antigua
 * se suelta. La primera fila superviviente apunta entonces, con su `prev_hash`,
 * a una fila que ya no existe. Sin ancla, `compliance:verify-audit-chain`
 * denunciaria rotura **todos los dias y de forma permanente**. Con ancla, busca
 * ese `prev_hash` como `last_hash` de un sello, lo encuentra y sigue: la
 * diferencia entre *«faltan filas»* y *«faltan filas que alguien registro que
 * iba a quitar, y encajan»*.
 *
 * **Nace en la Fase 1 aunque la purga sea de la 2.10** porque el verificador
 * tiene que entender las anclas **desde el primer dia**: si el codigo que las
 * resuelve llegara con la purga, la primera purga real seria tambien la primera
 * vez que se ejecuta ese camino.
 *
 * **Permisos.** La escribe el rol de mantenimiento, que es quien suelta la
 * particion. La aplicacion solo la **lee**: el verificador corre con el rol de
 * runtime y necesita resolver el `last_hash`, pero nadie mas tiene por que poder
 * sellar nada. `sealed_by` guarda el rol de base de datos que sello, no una
 * persona.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('audit_chain_anchors', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // UNIQUE: un año se sella una vez. Un segundo sello del mismo año
            // significaria que la particion se solto dos veces, y el segundo
            // sello taparia al primero.
            $table->integer('partition_year')->unique('audit_chain_anchors_partition_year_unique');

            $table->text('first_hash');
            $table->text('last_hash');
            $table->bigInteger('row_count');
            $table->timestampTz('sealed_at', 6);
            $table->text('sealed_by');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE audit_chain_anchors
                ADD CONSTRAINT audit_chain_anchors_chk_hash_format
                CHECK (first_hash ~ '^[0-9a-f]{64}$' AND last_hash ~ '^[0-9a-f]{64}$')
        SQL);

        // Una particion vacia no se sella: no hay cadena que anclar.
        DB::statement(<<<'SQL'
            ALTER TABLE audit_chain_anchors
                ADD CONSTRAINT audit_chain_anchors_chk_row_count
                CHECK (row_count > 0)
        SQL);

        // Es la consulta del verificador: «¿este prev_hash huerfano es el final
        // sellado de una particion?».
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX audit_chain_anchors_last_hash_index
                ON audit_chain_anchors (last_hash)
        SQL);

        $anchors = '"'.AuditLogSchema::ANCHORS_TABLE.'"';
        $application = '"'.AuditLogSchema::applicationRole().'"';
        $maintenance = '"'.AuditLogSchema::maintenanceRole().'"';

        // La migracion 099000 otorga las cuatro operaciones a toda tabla nueva.
        // Aqui se deshace: la aplicacion lee el ancla, no la escribe.
        DB::statement('REVOKE ALL ON TABLE '.$anchors.' FROM PUBLIC');
        DB::statement('REVOKE ALL ON TABLE '.$anchors.' FROM '.$application);
        DB::statement('GRANT SELECT ON TABLE '.$anchors.' TO '.$application);
        DB::statement('GRANT SELECT, INSERT ON TABLE '.$anchors.' TO '.$maintenance);
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE audit_chain_anchors_id_seq TO '.$maintenance);
        DB::statement('REVOKE ALL ON SEQUENCE audit_chain_anchors_id_seq FROM '.$application);
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists('audit_chain_anchors');
    }
};
