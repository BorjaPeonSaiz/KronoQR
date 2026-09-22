<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Resource;

use App\Modules\Workforce\Domain\ValueObject\AbsenceImportMessage;
use App\Modules\Workforce\Domain\ValueObject\AbsenceImportMessageCode;
use App\Modules\Workforce\Domain\ValueObject\AbsenceImportOutcome;
use App\Modules\Workforce\Domain\ValueObject\AbsenceImportReport;
use App\Modules\Workforce\Domain\ValueObject\AbsenceImportRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el esquema `AbsenceImportReport` (**RF-GP-04**).
 *
 * ## Aqui se traduce, y solo aqui
 *
 * El dominio lleva codigos ({@see AbsenceImportMessageCode}) y este recurso los
 * convierte en texto con `lang/`, en el idioma negociado. El codigo es lo estable
 * —el panel decide por el— y el texto es lo legible: si el dominio compusiera la
 * frase, el informe ingles saldria en espanol y cambiar una coma romperia la
 * logica del panel. Mismo reparto que {@see EmployeeImportResource}.
 *
 * ## Lo que nunca sale
 *
 * Ni la nota, ni el nombre de nadie. `label` es el **codigo de empleado** de la
 * linea, que es lo que hace falta para localizarla en el fichero y lo unico que
 * el fichero traia para identificar a la persona (regla dura 21).
 *
 * @property-read AbsenceImportReport $resource
 */
final class AbsenceImportResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(AbsenceImportReport $report, private readonly bool $applied)
    {
        parent::__construct($report);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AbsenceImportReport $report */
        $report = $this->resource;

        return [
            'mode' => $this->applied ? 'apply' : 'validate',
            'file' => [
                'sha256' => $report->sha256,
                'rows' => $report->rowCount(),
                // Los avisos del FICHERO, no de una fila: las columnas que no se
                // reconocen se dicen UNA vez.
                'warnings' => array_map(self::message(...), $report->warnings),
            ],
            'summary' => [
                'create' => $report->countOf(AbsenceImportOutcome::CREATE),
                'unchanged' => $report->countOf(AbsenceImportOutcome::UNCHANGED),
                'reject' => $report->countOf(AbsenceImportOutcome::REJECT),
            ],
            'rows' => array_map(self::row(...), $report->rows),
            'truncated' => $report->truncated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(AbsenceImportRow $row): array
    {
        return [
            'line' => $row->line,
            'label' => $row->label,
            'outcome' => $row->outcome->value,
            'absence_uuid' => $row->absenceUuid,
            'messages' => array_map(self::message(...), $row->messages),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function message(AbsenceImportMessage $message): array
    {
        $key = 'absences.import.messages.'.$message->code->value;
        $text = __($key, ['column' => $message->column ?? '']);

        return [
            'code' => $message->code->value,
            'severity' => $message->isWarning() ? 'warning' : 'error',
            'column' => $message->column,
            // Si falta la traduccion sale el codigo, que al menos es accionable
            // buscandolo en la guia; nunca una cadena vacia.
            'detail' => \is_string($text) && $text !== $key ? $text : $message->code->value,
        ];
    }
}
