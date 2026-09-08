<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Telemetry;

use App\Modules\Product\Application\Port\TelemetryStateStore;
use App\Modules\Product\Application\UseCase\SendTelemetryHandler;
use App\Modules\Product\Domain\ValueObject\TelemetryState;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * `storage/app/telemetry/state.json` (**RF-PD-12**, ficha 5.10 punto 8).
 *
 * ## Un fichero y no una tabla
 *
 * No es un dato del negocio: no tiene que salir en la exportacion integra, no
 * tiene que entrar en una copia de seguridad y no tiene versiones. Y sobre todo:
 * **borrarlo tiene que ser un `rm`**, porque estrenar identidad ante el
 * fabricante es una operacion que el cliente debe poder hacer solo y sin
 * permiso de nadie (ADR-020).
 *
 * ## `installation_id` es un UUID v4 y no un v7
 *
 * El v7 lleva dentro el instante en que se genero. Aqui eso seria decirle al
 * fabricante el dia y la hora exactos en que la instalacion arranco por primera
 * vez, gratis y sin que aparezca en la tabla de campos. El v4 es aleatorio
 * entero y no dice nada de nadie.
 *
 * ## Permisos cerrados, como el paquete de diagnostico
 *
 * Directorio `0700`, fichero `0600`. No lleva secretos, pero lleva el
 * identificador con el que el fabricante reconoce la instalacion, y no hay
 * ninguna razon para que lo lea otro usuario del servidor.
 *
 * ## Un fichero ilegible se trata como ausente
 *
 * Se acuña una identidad nueva y se sigue. La alternativa —lanzar— dejaria sin
 * telemetria a la instalacion por un JSON truncado, y no hay nada aqui que no se
 * pueda perder: los contadores volverian a empezar y el primer envio iria con
 * `usage_7d` a `null`, que es exactamente lo que significa.
 *
 * ## Escritura atomica
 *
 * Se escribe en un temporal del mismo directorio y se renombra. Un corte de luz
 * a mitad de `file_put_contents()` dejaria un JSON truncado, y aunque el punto
 * anterior lo sobrevive, sobrevivirlo cuesta la identidad de la instalacion.
 */
final readonly class FileTelemetryStateStore implements TelemetryStateStore
{
    private const int DIRECTORY_MODE = 0700;

    private const int FILE_MODE = 0600;

    public function __construct(
        private string $path,
        private LoggerInterface $logger,
    ) {}

    public function establish(): TelemetryState
    {
        $state = $this->stored();

        if ($state instanceof TelemetryState) {
            return $state;
        }

        $minted = $this->provisional();
        $this->save($minted);

        return $minted;
    }

    public function stored(): ?TelemetryState
    {
        return $this->read();
    }

    public function provisional(): TelemetryState
    {
        return new TelemetryState(Str::uuid()->toString());
    }

    /**
     * Escritura atomica, y **con constancia si no se puede escribir**.
     *
     * ## Por que un fallo no puede quedarse en silencio
     *
     * Porque el silencio es indistinguible del funcionamiento normal y produce
     * un sintoma que nadie sabria explicar: con el directorio sin permisos de
     * escritura, `establish()` acuña una identidad nueva **cada semana** -el
     * fabricante ve una instalacion distinta cada lunes- y `usage_7d` va siempre
     * a `null`, porque nunca hay acumulado anterior con el que restar. Sin esta
     * linea, la unica pista seria un panel raro en casa del fabricante.
     *
     * ## `notice` y no `error`, y sin la ruta
     *
     * `notice` por lo mismo que un envio fallido (ver {@see SendTelemetryHandler}):
     * no se ha roto nada del registro, y un `error` semanal enseña a ignorar el
     * log. La ruta no entra en el mensaje -es una ruta del servidor del cliente,
     * y este log acaba en el paquete de diagnostico-: basta la operacion que
     * fallo y, cuando la hay, la clase de la excepcion.
     *
     * **No lanza.** Un envio que no puede anotar su resultado ya se hizo, y
     * convertirlo en excepcion romperia la tarea del planificador por algo
     * accesorio.
     */
    public function save(TelemetryState $state): void
    {
        $directory = \dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, self::DIRECTORY_MODE, true) && ! is_dir($directory)) {
            $this->unwritable('mkdir');

            return;
        }

        @chmod($directory, self::DIRECTORY_MODE);

        $json = json_encode($state->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            $this->unwritable('json_encode');

            return;
        }

        $temporary = $this->path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temporary, $json.\PHP_EOL, LOCK_EX) === false) {
            $this->unwritable('file_put_contents');

            return;
        }

        @chmod($temporary, self::FILE_MODE);

        if (! @rename($temporary, $this->path)) {
            @unlink($temporary);
            $this->unwritable('rename');
        }
    }

    /**
     * El aviso, con la operacion que fallo y **sin la ruta**.
     */
    private function unwritable(string $operation): void
    {
        $this->logger->notice('product.telemetry_state_unwritable', [
            'operation' => $operation,
            // `error_get_last()` trae el mensaje del `@` que acaba de fallar, y
            // ese mensaje lleva la ruta. Solo se copia el TIPO.
            'reason' => self::lastErrorType(),
            // La consecuencia, escrita, para que quien lea el log no tenga que
            // deducirla: es lo que hace util este aviso.
            'effect' => 'installation_id_not_persisted',
        ]);
    }

    private static function lastErrorType(): string
    {
        $last = error_get_last();

        return $last === null ? 'unknown' : (string) $last['type'];
    }

    private function read(): ?TelemetryState
    {
        $decoded = $this->decoded();

        if ($decoded === null) {
            return null;
        }

        $id = self::text($decoded, 'installation_id');

        if ($id === null) {
            return null;
        }

        return new TelemetryState(
            installationId: $id,
            lastAttemptAt: self::instant($decoded['last_attempt_at'] ?? null),
            lastSuccessAt: self::instant($decoded['last_success_at'] ?? null),
            lastFailure: self::text($decoded, 'last_failure'),
            counters: self::counters($decoded['counters'] ?? null),
            countersAt: self::instant($decoded['counters_at'] ?? null),
        );
    }

    /**
     * Una clave del fichero como texto no vacio, o `null`.
     *
     * @param  array<string, mixed>  $decoded
     */
    private static function text(array $decoded, string $key): ?string
    {
        $value = $decoded[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * El contenido del fichero, o `null` si no lo hay o no es un objeto JSON.
     *
     * Separado de {@see self::read()} para que cada uno tenga una sola razon
     * para devolver `null`: aqui, «no se puede leer»; alli, «no hay identidad
     * dentro».
     *
     * @return array<string, mixed>|null
     */
    private function decoded(): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }

        $raw = @file_get_contents($this->path);

        if ($raw === false) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        /** @var array<string, mixed>|null */
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, int>
     */
    private static function counters(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $counters = [];

        foreach ($value as $key => $count) {
            if (is_string($key) && is_int($count)) {
                $counters[$key] = $count;
            }
        }

        return $counters;
    }

    private static function instant(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }
}
