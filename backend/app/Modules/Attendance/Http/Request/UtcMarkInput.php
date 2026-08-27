<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Request;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Las marcas de un tramo tal y como llegan por la API: instantes en UTC con
 * sufijo `Z` (regla dura 3, RN-04).
 *
 * Separado de {@see CorrectionReasonInput} porque la anulacion no lleva marcas
 * —no hay nada que rectificar— y un trait que arrastrara reglas que su usuario
 * no aplica seria un patron que nadie puede verificar.
 *
 * @phpstan-require-extends FormRequest
 */
trait UtcMarkInput
{
    /**
     * El mismo patron que el esquema `UtcTimestamp` del contrato.
     *
     * Rechaza los desplazamientos explicitos (`+02:00`) y las horas sin zona:
     * aceptarlos convertiria la zona horaria en un dato del cliente, y con
     * turnos nocturnos y cambio de hora eso es una jornada mal atribuida.
     *
     * Escrito aqui porque una regla de validacion no puede leer el YAML del
     * contrato. Las dos copias las ata una prueba, no la buena fe.
     */
    private const string UTC_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/';

    /**
     * Un instante del cuerpo, ya en UTC.
     *
     * Aqui no hay ninguna conversion de zona porque no hay nada que convertir:
     * el patron de arriba solo deja pasar la forma `...Z`, asi que lo unico que
     * se hace es construir el objeto que el dominio espera.
     */
    private function utcInstant(string $field): ?DateTimeImmutable
    {
        $value = $this->input($field);

        if (! \is_string($value) || $value === '') {
            return null;
        }

        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    /**
     * Los mensajes de las dos marcas, con un ejemplo dentro.
     *
     * Un «formato invalido» a secas obliga a quien rellena el formulario a ir a
     * buscar el contrato; con el ejemplo delante, lo arregla en el momento.
     *
     * @return array<string, string>
     */
    private function utcMarkMessages(): array
    {
        return [
            'clocked_in_at.regex' => 'La hora de entrada tiene que ir en UTC, con el sufijo Z (2026-08-14T06:00:00Z).',
            'clocked_out_at.regex' => 'La hora de salida tiene que ir en UTC, con el sufijo Z (2026-08-14T14:00:00Z).',
        ];
    }
}
