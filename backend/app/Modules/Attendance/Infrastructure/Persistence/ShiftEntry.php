<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Fila de `shift_entries` (doc 01 §5.5). **Detalle de persistencia**, no el
 * modelo de dominio: ese es {@see \App\Modules\Attendance\Domain\Model\ShiftEntry}
 * y es el unico que sale de este modulo hacia arriba.
 *
 * Los dos se llaman igual a proposito —es la convencion de Laravel para el
 * modelo y la del dominio para la entidad— y el repositorio los distingue con un
 * alias, igual que hace `Workforce` con `Employee`.
 *
 * **No tiene `$fillable` con `status` ni `duration_minutes` por comodidad.** Lo
 * tiene porque el repositorio escribe la fila entera en un `upsert` y esos dos
 * campos los decide el dominio. Lo que **no** existe aqui es ningun metodo que
 * cierre, anule o corrija un tramo: eso vive en el agregado, y un modelo con
 * `->update(['status' => 'closed'])` a mano seria la puerta por la que RN-01 y
 * RN-13 dejan de cumplirse.
 *
 * @property int $id
 * @property string $uuid
 * @property int $employee_id
 * @property int $site_id
 * @property Carbon $work_date
 * @property Carbon $clocked_in_at
 * @property Carbon|null $clocked_out_at
 * @property int|null $duration_minutes
 * @property string $status
 * @property string $clock_in_source
 * @property string|null $clock_out_source
 * @property int $version
 * @property int|null $superseded_by_id
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 */
final class ShiftEntry extends Model
{
    protected $table = 'shift_entries';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'employee_id',
        'site_id',
        'work_date',
        'clocked_in_at',
        'clocked_out_at',
        'duration_minutes',
        'status',
        'clock_in_source',
        'clock_out_source',
        'version',
        'superseded_by_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // `date` y no `datetime`: la jornada es una fecha civil, no un
            // instante (RN-05). Darle hora obligaria a elegir una que nadie ha
            // decidido.
            'work_date' => 'date',
            // Precision 6 en las dos: la de serie de Laravel redondea al
            // segundo, y en un registro con valor legal la hora leida tiene que
            // ser la escrita.
            'clocked_in_at' => 'immutable_datetime',
            'clocked_out_at' => 'immutable_datetime',
        ];
    }
}
