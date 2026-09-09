<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Console;

use App\Modules\Compliance\Application\Command\RecordSystemEventCommand as RecordSystemEvent;
use App\Modules\Compliance\Application\UseCase\RecordSystemEvent as RecordSystemEventUseCase;
use App\Modules\Compliance\Domain\Exception\ComplianceDomainException;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\SystemEventPayload;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

/**
 * `php artisan compliance:record-system-event` — el asiento de auditoria que
 * deja el instalador (**RF-PD-10**, RL-04, RS-07, regla dura 6, tarea 5.7).
 *
 * ## Quien lo llama y por que existe
 *
 * Lo llama `infra/scripts/update.sh`, no el producto. Es el **puerto de
 * entrada** del unico hecho auditable que ocurre fuera de la aplicacion: que la
 * instalacion cambio de version, o que se restauro una copia y con ella se
 * descarto un intervalo que puede contener fichajes reales.
 *
 * ## Un comando y no dos, con `--data` y no con opciones tipadas
 *
 * **Un comando.** Las dos acciones se escriben en el mismo momento del mismo
 * proceso, comparten actor, sujeto, validacion y modo de fallo. Dos comandos
 * serian dos sitios donde equivocarse de actor.
 *
 * **`--data` con JSON y no una opcion por campo.** La lista de claves de cada
 * accion ya esta declarada, cerrada y probada en el dominio
 * ({@see SystemEventPayload}).
 * Repetirla aqui como `--from-version`, `--to-version`, `--reason`… seria una
 * segunda copia de esa lista en una capa que no manda, y dos listas cerradas
 * divergen: el dia que se anada una clave, la firma del comando se quedaria
 * corta y el fallo aparecerian meses despues, en la primera actualizacion que
 * la necesitara. Con JSON, el dominio es el unico que decide que se admite y el
 * comando solo transporta.
 *
 * **`--data=-` lee de la entrada estandar**, y es la forma recomendada para el
 * script: `docker compose exec -T` ya pasa `stdin`, y asi el JSON no viaja por
 * la linea de comandos, donde el entrecomillado de `bash` es una fuente de
 * errores y donde otro proceso podria leerlo en la lista de procesos.
 *
 * ## Codigos de salida
 *
 * - `0` — asiento escrito. Imprime en `stdout` una linea JSON con `id`, `hash` y
 *   `prev_hash` para que el instalador la copie en su informe.
 * - `1` — **el payload no se admite** y no se ha escrito nada: accion
 *   desconocida, JSON invalido, clave fuera de la lista, campo con pinta de dato
 *   personal. Es un error de quien invoca, no de la instalacion.
 * - `2` — **no se pudo escribir**: la base de datos no responde, la particion
 *   del ano no existe, la conexion se cayo. El hecho ocurrio y no hay asiento.
 *
 * El instalador **no deshace nada** por un `1` ni por un `2`: una actualizacion
 * correcta no se tira atras porque su asiento fallara —seria cambiar un problema
 * de trazabilidad por una perdida de datos—. Lo que hace es dejarlo escrito en
 * su informe y salir con el codigo que corresponda.
 */
final class RecordSystemEventCommand extends Command
{
    /** No se pudo escribir el asiento, pero el hecho ocurrio. */
    private const int EXIT_NOT_RECORDED = 2;

    protected $signature = 'compliance:record-system-event
        {action : system.updated | system.restored_from_backup}
        {--data= : JSON con los datos del asiento. «-» lo lee de la entrada estandar}';

    protected $description = 'Deja en audit_log que la instalacion se actualizo o que se restauro una copia (RF-PD-10)';

    public function handle(RecordSystemEventUseCase $record): int
    {
        $name = (string) $this->argument('action');
        $action = AuditAction::tryFrom($name);

        if ($action === null || ! $action->requiresSystemActor()) {
            $this->error('Accion desconocida: «'.$name.'». Solo se admiten las acciones «system.*» del catalogo.');

            return self::FAILURE;
        }

        try {
            $data = $this->data();
        } catch (JsonException $exception) {
            $this->error('El valor de --data no es JSON valido: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($data === null) {
            $this->error('--data debe ser un objeto JSON con los datos del asiento.');

            return self::FAILURE;
        }

        try {
            $entry = $record->handle(new RecordSystemEvent($action, $data));
        } catch (ComplianceDomainException $exception) {
            // El dominio ha dicho que no: la lista cerrada, la forma de un campo
            // o el filtro de datos personales. No se ha escrito nada.
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            // Cualquier otra cosa es que NO se pudo escribir. Se distingue del
            // caso anterior a proposito: uno lo arregla quien invoca y el otro
            // es un incidente de la instalacion.
            $this->error('No se pudo escribir el asiento: '.$exception->getMessage());

            return self::EXIT_NOT_RECORDED;
        }

        $this->line((string) json_encode([
            'id' => $entry->id,
            'action' => $action->value,
            'hash' => $entry->hash,
            'prev_hash' => $entry->previousHash,
            'occurred_at' => $entry->draft->occurredAt->format('Y-m-d\TH:i:s.u\Z'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    /**
     * El JSON de `--data`, o de la entrada estandar si vale `-`.
     *
     * Devuelve `null` cuando el JSON es valido pero no es un objeto: una lista o
     * un numero sueltos no son un payload, y dejar que llegaran al dominio
     * produciria un mensaje de error sobre claves desconocidas en lugar de decir
     * lo que pasa.
     *
     * @return array<array-key, mixed>|null
     *
     * @throws JsonException
     */
    private function data(): ?array
    {
        $raw = $this->option('data');
        $json = is_string($raw) ? trim($raw) : '';

        if ($json === '-') {
            $stdin = file_get_contents('php://stdin');
            $json = trim($stdin === false ? '' : $stdin);
        }

        if ($json === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        return $decoded;
    }
}
