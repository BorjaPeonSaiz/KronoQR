<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\LogoImage;
use LogicException;

/**
 * El resultado de mirar un fichero de logotipo: o la imagen, o el motivo por el
 * que no vale (RF-PD-08, tarea 5.8).
 *
 * **Un resultado y no una excepcion** porque los dos desenlaces son normales y
 * se tratan distinto en cada consumidor: al GUARDAR la ruta, un rechazo es un
 * `422` con el motivo traducido; al LEERLA para dibujar, el mismo rechazo es
 * «sigue sin logotipo» y ni siquiera se cuenta. Con una excepcion, el segundo
 * caso obligaria a un `try/catch` en cada sitio que imprime algo, y el que se
 * olvidara dejaria a un cliente sin poder imprimir tarjetas por un fichero
 * borrado.
 */
final readonly class LogoInspection
{
    private function __construct(
        public ?LogoImage $image,
        public ?LogoRejection $rejection,
    ) {}

    public static function accepted(LogoImage $image): self
    {
        return new self($image, null);
    }

    public static function rejected(LogoRejection $rejection): self
    {
        return new self(null, $rejection);
    }

    public function isAccepted(): bool
    {
        return $this->image instanceof LogoImage;
    }

    /**
     * El motivo, cuando se sabe que fue rechazada.
     *
     * La otra cara de {@see image()}, y existe por lo mismo: quien ya comprobo
     * {@see isAccepted()} no tiene por que volver a tratar un nulo que no puede
     * darse. Sin este metodo, el `422` de la configuracion acabaria con un
     * motivo de reserva escrito a mano que ninguna prueba cubriria.
     */
    public function rejection(): LogoRejection
    {
        return $this->rejection ?? throw new LogicException('Se ha pedido el motivo de una inspeccion aceptada.');
    }
}
