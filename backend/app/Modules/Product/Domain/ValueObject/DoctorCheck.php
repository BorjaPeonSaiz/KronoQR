<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Una comprobacion de `product:doctor` ya redactada (contrato `DoctorCheck`,
 * **RF-PD-13**).
 *
 * ## `fix` es el campo que justifica el comando
 *
 * «No puedo entrar a arreglarlo»: el sistema corre en un servidor del cliente al
 * que el fabricante no tiene acceso (ADR-016, ADR-020). Un informe que dijera
 * «la cola tiene 8.000 trabajos pendientes» y nada mas obliga a una llamada. Por
 * eso `fix` es obligatorio en todo lo que no sea `ok` y esta escrito para quien
 * no conoce este sistema: el comando que hay que ejecutar, entero y copiable.
 */
final readonly class DoctorCheck
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $id,
        public DoctorStatus $status,
        public string $summary,
        public ?string $fix,
        public array $details = [],
    ) {}

    /**
     * @return array{id: string, status: string, summary: string, fix: string|null, details: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'summary' => $this->summary,
            'fix' => $this->fix,
            'details' => $this->details,
        ];
    }
}
