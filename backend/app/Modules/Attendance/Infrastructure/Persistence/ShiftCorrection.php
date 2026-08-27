<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Fila de `shift_corrections` (doc 01 §5.5). **Detalle de persistencia**: el
 * hecho que describe vive en el evento de dominio `ShiftCorrected`, y es ese —no
 * esto— lo que sale de este modulo hacia arriba.
 *
 * **Sin `updated_at` y sin `$timestamps`.** No es una comodidad: esta tabla es un
 * libro y solo se inserta. Una correccion no se corrige —se hace otra, y quedan
 * las dos (RN-13, regla dura 5)— asi que una columna «ultima modificacion» no
 * describiria nada que pueda ocurrir. Con `$timestamps = true`, Eloquent
 * intentaria escribir `updated_at` y la insercion fallaria contra una tabla que
 * no la tiene, que es preferible a tenerla y no saber que significa.
 *
 * Aqui no hay ningun metodo que anule, corrija o reescriba nada: el unico camino
 * para escribir una fila es {@see DatabaseShiftCorrectionLedger}, que la deriva
 * del evento de dominio.
 *
 * @property int $id
 * @property int $shift_entry_id
 * @property int $performed_by_user_id
 * @property string $action
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string $reason_code
 * @property string|null $reason_text
 * @property Carbon $created_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 */
final class ShiftCorrection extends Model
{
    public $timestamps = false;

    protected $table = 'shift_corrections';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'shift_entry_id',
        'performed_by_user_id',
        'action',
        'before',
        'after',
        'reason_code',
        'reason_text',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
