<?php

declare(strict_types=1);

namespace Tests\Support\Observability;

use App\Support\Scheduling\LogScheduledCommandFailure;
use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Un registrador PSR-3 que se queda con lo que le llega, **sin framework
 * detras**.
 *
 * ## Por que existe teniendo `CapturedLog` y `Log::spy()`
 *
 * Los dos necesitan la aplicacion arrancada: uno engancha un handler al canal
 * por defecto y el otro sustituye el *facade*. La suite `Unit` no arranca el
 * framework a proposito (`tests/Pest.php`), asi que una pieza que recibe su
 * `LoggerInterface` por parametro —como
 * {@see LogScheduledCommandFailure}— no se puede probar
 * con ninguno de los dos sin cambiarla de suite.
 *
 * No sustituye a `CapturedLog`: aquel comprueba lo que sale **despues** de los
 * processors de Monolog (el `trace_id`, las claves de `Context`), y eso solo
 * existe dentro de Monolog. Aqui se comprueba lo que el codigo **pide** que se
 * escriba.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $lines = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->lines[] = [
            'level' => \is_string($level) ? $level : (string) json_encode($level),
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * La primera linea escrita, o el fallo con su motivo. Devolver `null` y
     * dejar que la asercion siguiente reviente con «property on null» ocultaria
     * cual es el problema.
     *
     * @return array{level: string, message: string, context: array<string, mixed>}
     */
    public function first(): array
    {
        return $this->lines[0] ?? throw new \RuntimeException('No se ha escrito ninguna linea de log.');
    }
}
