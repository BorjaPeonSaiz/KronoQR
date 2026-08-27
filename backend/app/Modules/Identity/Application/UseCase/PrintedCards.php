<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Domain\ValueObject\CardFormat;

/**
 * El resultado de una impresion: **el PDF y la lista de lo que lleva dentro**.
 *
 * **Los bytes, y no una ruta.** El PDF de una tarjeta es un instrumento al
 * portador —quien lo tenga puede fabricar la tarjeta de otra persona y fichar por
 * ella—, asi que no se escribe en `storage/`, no se sube a ningun disco y no
 * viaja en la carga util de ningun trabajo en cola. El endpoint lo transmite y
 * muere ahi.
 *
 * **`credentials` no lleva ningun payload.** Es la lista de credenciales que se
 * acaban de acuñar, en el formato con el que se hablan por fuera —UUID publico y
 * fechas—, para que el panel pueda refrescar su tabla y para que la consola pueda
 * decir que ha impreso. El token en claro solo existe dentro del PDF.
 *
 * **`isEmpty()` no es un fallo.** `credentials:print-batch --pending` sobre un
 * centro que ya tiene todo impreso devuelve un lote vacio, y eso es exactamente
 * lo que su idempotencia significa (ADR-034): la segunda pasada no encuentra nada
 * y no produce ningun PDF.
 */
final readonly class PrintedCards
{
    /**
     * @param  list<CredentialView>  $credentials
     */
    public function __construct(
        /** Los bytes del PDF. **Nunca se registran en un log.** Vacio si no habia nada que imprimir. */
        public string $pdf,
        public CardFormat $format,
        public array $credentials,
    ) {}

    public function isEmpty(): bool
    {
        return $this->credentials === [];
    }

    public function count(): int
    {
        return \count($this->credentials);
    }

    public function fileName(): string
    {
        return $this->format->fileName();
    }
}
