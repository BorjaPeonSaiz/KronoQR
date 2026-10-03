<?php

declare(strict_types=1);

use App\Modules\Product\Application\UseCase\ResanitizeErrorHistory;
use App\Modules\Product\Infrastructure\Persistence\DatabaseErrorEventRepository;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

/**
 * **Vuelve a sanear el historico de errores guardado antes de la 2.2.0** y
 * recalcula sus huellas (ADR-048 decision 9, H6; RF-PD-15, RL-19, regla dura 21).
 *
 * ## Que problema resuelve
 *
 * Hasta la 2.2.0, `error_events` se saneaba solo con patrones y un nombre sin
 * comillas pasaba. Esas filas viven 90 dias y viajan en el paquete de
 * diagnostico, y su `fingerprint` es un `sha256` sin sal de un texto con el
 * nombre dentro, atacable por diccionario. Filtrar al leer no basta: hay que
 * reescribirlas. Lo hace el caso de uso `ResanitizeErrorHistory`, el mismo que
 * prueban `ResanitizeErrorHistoryTest` (unitaria e integracion): vuelve a sanear
 * cada grupo con las reglas de hoy y **funde** los que pasan a tener la misma
 * huella (suma `occurrences`, el `first_seen_at` mas antiguo, el `last_seen_at`
 * mas reciente, abierto si alguno lo estaba).
 *
 * ## Patron `/migracion-segura`: solo datos
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (migrate)** | Reescritura por bloques de 200 filas por clave, sobre una tabla acotada por el techo de grupos por origen y por la retencion de 90 dias. Ningun cambio de esquema; bloqueos de fila, no de tabla. | La version que se despliega ya escribe con las reglas nuevas, asi que lo que llegue mientras corre ya esta limpio. |
 *
 * Es **idempotente**: si se vuelve a ejecutar —una copia restaurada de antes de
 * la 2.2.0 que se migra otra vez— solo reescribe lo que no estuviera limpio.
 * No escribe en `audit_log` (diagnostico tecnico, no registro legal) y deja una
 * linea `product.error_history_resanitized` con dos recuentos.
 *
 * ## `down()` vacio, y es la decision
 *
 * **Un saneado no se deshace**: el texto que se quito ya no existe en ninguna
 * parte, y reponerlo seria volver a guardar los nombres que esta migracion
 * existe para quitar. La version anterior lee estas filas sin problema —tienen
 * las mismas columnas y los mismos tipos—, asi que revertir la aplicacion no
 * necesita nada de aqui. Es la excepcion declarada en ADR-048 a la exigencia de
 * que una migracion sea reversible, y `ResanitizeErrorHistoryTest` comprueba
 * que `migrate:rollback` la atraviesa sin error.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        $connection = DB::connection($this->getConnection());

        /** @var LoggerInterface $logger */
        $logger = app(LoggerInterface::class);

        new ResanitizeErrorHistory(
            new DatabaseErrorEventRepository($connection, $connection),
            $logger,
        )->run();
    }

    public function down(): void
    {
        // Intencionadamente vacio: ver «`down()` vacio, y es la decision» en el
        // docblock. Un saneado no se deshace.
    }
};
