<?php

declare(strict_types=1);

namespace Tests\Support\Product;

use App\Modules\Shared\Application\Port\ErrorEventSink;
use App\Modules\Shared\Domain\ValueObject\ErrorReport;
use RuntimeException;

/**
 * Doble de {@see ErrorEventSink} que recuerda lo que se le mando guardar.
 *
 * Es la segunda implementacion del puerto que justifica que el puerto exista
 * (doc 02 §3.5): con ella se puede afirmar **que un error se capto, con que
 * origen y con que severidad** sin depender de la tabla, de la huella ni del
 * saneado, que son otra tarea y tienen sus propias pruebas.
 *
 * Lo que se comprueba con esto es la CAPTACION, que es la parte que puede
 * romperse sin que nadie lo note: alguien anade un origen y se olvida del oyente,
 * o mete una excepcion de negocio en el filtro, y el panel de errores se queda
 * mudo o se llena de ruido sin que falle nada.
 *
 * ## `acceptUpTo()` existe por `acknowledge(n)`
 *
 * El contrato del puerto dice que el recuento devuelto es un **prefijo**: el
 * cliente vacia de su buffer exactamente los `n` mas antiguos. Sin poder simular
 * un sink que solo guarda algunos —o ninguno—, no hay forma de comprobar que el
 * latido responde `client_errors_accepted: 0` cuando la base de datos no
 * responde, que es justo el caso en el que la tablet no debe perder nada.
 */
final class InMemoryErrorEventSink implements ErrorEventSink
{
    /** @var list<ErrorReport> Lo persistido, en orden de llegada. */
    public array $reports = [];

    /** Cuantos acepta como maximo por lote; `null` es «todos». */
    private ?int $limit = null;

    /**
     * Un sink que solo guarda los `$count` primeros de cada lote. Con `0` simula
     * una base de datos que no responde: el puerto no lanza, devuelve cuantos
     * pudo.
     */
    public function acceptUpTo(int $count): self
    {
        $this->limit = $count;

        return $this;
    }

    public function record(ErrorReport $report): bool
    {
        if ($this->limit === 0) {
            return false;
        }

        $this->reports[] = $report;

        return true;
    }

    public function recordAll(array $reports): int
    {
        $accepted = 0;

        foreach ($reports as $report) {
            if ($this->limit !== null && $accepted >= $this->limit) {
                break;
            }

            if (! $this->record($report)) {
                break;
            }

            $accepted++;
        }

        return $accepted;
    }

    public function isEmpty(): bool
    {
        return $this->reports === [];
    }

    /**
     * El unico informe recibido. Falla en voz alta si hay cero o mas de uno: una
     * prueba que afirma sobre «el primero» de una lista que no sabe cuantos tiene
     * pasa igual cuando la captacion duplica.
     */
    public function only(): ErrorReport
    {
        return \count($this->reports) === 1
            ? $this->reports[0]
            : throw new RuntimeException('Se esperaba exactamente un error captado, hay '.\count($this->reports).'.');
    }

    /**
     * El informe en la posicion pedida, o un fallo con el recuento real. Sin
     * esto, una prueba que mira `$sink->reports[1]` cuando solo se capto uno
     * fallaria por «indice indefinido» en vez de por lo que de verdad pasa.
     */
    public function at(int $index): ErrorReport
    {
        return $this->reports[$index]
            ?? throw new RuntimeException('No hay error captado en la posicion '.$index.'; hay '.\count($this->reports).'.');
    }

    public function forget(): void
    {
        $this->reports = [];
    }
}
