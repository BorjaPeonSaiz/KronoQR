<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Resource;

use App\Modules\Workforce\Domain\ValueObject\ImportMessage;
use App\Modules\Workforce\Domain\ValueObject\ImportMessageCode;
use App\Modules\Workforce\Domain\ValueObject\ImportOutcome;
use App\Modules\Workforce\Domain\ValueObject\ImportReport;
use App\Modules\Workforce\Domain\ValueObject\ImportRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el esquema `EmployeeImportReport` (**RF-GP-05**).
 *
 * ## Aqui se traduce, y solo aqui
 *
 * El dominio lleva codigos ({@see ImportMessageCode})
 * y este recurso los convierte en texto con `lang/`, en el idioma negociado. El
 * codigo es lo estable —el panel decide por el— y el texto es lo legible: si el
 * dominio compusiera la frase, el informe ingles saldria en espanol y cambiar
 * una coma romperia la logica del panel.
 *
 * ## El nombre viaja aqui, y en ningun otro sitio
 *
 * `label` sale del fichero para que quien importa pueda localizar la linea: «la
 * linea 14 se rechaza» no sirve para arreglar nada. Viaja en una respuesta
 * autenticada de RRHH y **no entra en `error_events`, ni en el log tecnico, ni
 * en el asiento de auditoria del lote** (regla dura 21).
 *
 * ## Lo que nunca sale
 *
 * El documento de identidad, ni en claro ni hasheado. El objeto de dominio lo
 * lleva hasta la insercion y este recurso no lo mira: no hay ninguna via por la
 * que un DNI pueda aparecer en una respuesta.
 *
 * @property-read ImportReport $resource
 */
final class EmployeeImportResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(ImportReport $report, private readonly bool $applied)
    {
        parent::__construct($report);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ImportReport $report */
        $report = $this->resource;

        return [
            'mode' => $this->applied ? 'apply' : 'validate',
            'file' => [
                'sha256' => $report->sha256,
                'rows' => $report->rowCount(),
                // Los avisos del FICHERO, no de una fila: las columnas que no se
                // reconocen se dicen UNA vez. Repetidos en cada linea eran ciento
                // veinte mensajes identicos que sepultaban los rechazos de verdad.
                'warnings' => array_map(self::message(...), $report->warnings),
            ],
            'summary' => [
                'create' => $report->countOf(ImportOutcome::CREATE),
                'update' => $report->countOf(ImportOutcome::UPDATE),
                'unchanged' => $report->countOf(ImportOutcome::UNCHANGED),
                'reject' => $report->countOf(ImportOutcome::REJECT),
            ],
            'rows' => array_map(self::row(...), $report->rows),
            'truncated' => $report->truncated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(ImportRow $row): array
    {
        return [
            'line' => $row->line,
            'label' => $row->label,
            'outcome' => $row->outcome->value,
            'employee_uuid' => $row->employeeUuid,
            'changes' => $row->changes,
            'messages' => array_map(self::message(...), $row->messages),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function message(ImportMessage $message): array
    {
        $key = 'import.messages.'.$message->code->value;
        $text = __($key, ['column' => $message->column ?? '']);

        return [
            'code' => $message->code->value,
            'severity' => $message->isWarning() ? 'warning' : 'error',
            'column' => $message->column,
            // Si falta la traduccion sale el codigo, que al menos es accionable
            // buscandolo en la guia; nunca una cadena vacia. Que falte es un
            // defecto del producto y lo caza la prueba de idioma del endpoint.
            'detail' => \is_string($text) && $text !== $key ? $text : $message->code->value,
        ];
    }
}
