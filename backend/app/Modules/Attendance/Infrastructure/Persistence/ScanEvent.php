<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Fila de `scan_events` (doc 01 §5.5): el log inmutable de **todo** escaneo,
 * aceptado o no.
 *
 * **Sin `timestamps`.** La tabla no tiene `created_at` ni `updated_at` y no es
 * un olvido del esquema: sus dos marcas de tiempo son `occurred_at` y
 * `recorded_at` (regla dura 9), y una tercera solo podria confundirse con
 * alguna de ellas.
 *
 * **Append-only por disciplina.** Nada en el codigo actualiza una fila de esta
 * tabla: un escaneo es un hecho ocurrido y su desenlace se decide antes de
 * escribirlo. Si alguna vez hiciera falta corregir uno, seria una fila nueva,
 * como todo lo demas en este producto (regla dura 5).
 *
 * @property int $id
 * @property string $scan_id
 * @property int $device_id
 * @property int|null $employee_id
 * @property Carbon $occurred_at
 * @property Carbon $recorded_at
 * @property string $origin
 * @property string $intent
 * @property string $result
 * @property int|null $shift_entry_id
 * @property string|null $payload_fingerprint
 * @property array<string, mixed> $client_meta
 * @property int|null $clock_skew_seconds
 * @property bool $flagged_for_review
 * @property int|null $worked_minutes
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> query()
 */
final class ScanEvent extends Model
{
    protected $table = 'scan_events';

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'scan_id',
        'device_id',
        'employee_id',
        'occurred_at',
        'recorded_at',
        'origin',
        'intent',
        'result',
        'shift_entry_id',
        'payload_fingerprint',
        'client_meta',
        'clock_skew_seconds',
        'flagged_for_review',
        'worked_minutes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'client_meta' => 'array',
            'flagged_for_review' => 'boolean',
        ];
    }
}
