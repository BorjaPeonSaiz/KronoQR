<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\UtcInstant;
use DateTimeImmutable;

/**
 * Lo unico que la telemetria guarda entre un envio y el siguiente
 * (`storage/app/telemetry/state.json`, **RF-PD-12**, ficha 5.10 punto 8).
 *
 * ## `installationId` es aleatorio, y eso es la decision
 *
 * Un UUID v4 acuñado la primera vez que se ejecuta `product:telemetry`. **No es
 * el `license_id`, ni la huella de la clave, ni nada derivado de la razon
 * social**: identifica a la instalacion ante el fabricante sin identificar al
 * cliente. Borrar el fichero estrena identidad, y eso tambien es deliberado —el
 * cliente puede cortar la continuidad cuando quiera sin apagar nada—.
 *
 * ## `lastFailure` lleva la CLASE, jamas el mensaje
 *
 * `ConnectionException` o `http_502`. Un mensaje de excepcion de red lleva el
 * host, el puerto y a veces la URL entera con su token; este fichero lo lee
 * quien administra la instalacion, pero tambien acaba en el paquete de
 * diagnostico si algun dia se recoge, y la regla dura 21 no admite ese «si».
 *
 * ## `counters` es la linea de salida del periodo
 *
 * Las series de Redis son **acumuladas desde que se instalo**, y lo que la ficha
 * llama `usage_7d` es lo ocurrido en el periodo. Sin guardar el acumulado del
 * envio anterior no hay forma de restar, y el documento diria «uso de la semana»
 * llevando el total de cuatro años. Solo avanza **cuando un envio sale bien**:
 * si una semana falla, la siguiente cubre las dos y no se pierde nada.
 *
 * Son cuatro numeros agregados, sin etiquetas y sin nada de nadie: la misma
 * informacion que ya iba dentro del documento.
 */
final readonly class TelemetryState
{
    /**
     * @param  array<string, int>  $counters  Acumulados del ultimo envio correcto.
     */
    public function __construct(
        public string $installationId,
        public ?DateTimeImmutable $lastAttemptAt = null,
        public ?DateTimeImmutable $lastSuccessAt = null,
        public ?string $lastFailure = null,
        public array $counters = [],
        public ?DateTimeImmutable $countersAt = null,
    ) {}

    /**
     * @param  array<string, int>  $counters
     */
    public function succeeded(DateTimeImmutable $at, array $counters): self
    {
        return new self($this->installationId, $at, $at, null, $counters, $at);
    }

    public function failed(DateTimeImmutable $at, string $failure): self
    {
        return new self(
            $this->installationId,
            $at,
            $this->lastSuccessAt,
            $failure,
            $this->counters,
            $this->countersAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'installation_id' => $this->installationId,
            'last_attempt_at' => UtcInstant::format($this->lastAttemptAt),
            'last_success_at' => UtcInstant::format($this->lastSuccessAt),
            'last_failure' => $this->lastFailure,
            'counters' => $this->counters,
            'counters_at' => UtcInstant::format($this->countersAt),
        ];
    }
}
