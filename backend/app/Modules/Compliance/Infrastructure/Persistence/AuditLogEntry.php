<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Modelo Eloquent de `audit_log`. **Solo-append, y de verdad** (regla dura 6).
 *
 * Es un detalle de persistencia y no sale de esta capa: el repositorio traduce a
 * los objetos de valor de `Compliance/Domain` y nadie fuera de
 * `Infrastructure/Persistence/` conoce esta clase.
 *
 * **Los tres cerrojos, y por que hacen falta los tres.**
 *
 * 1. **PostgreSQL.** El rol de la aplicacion no tiene `UPDATE` ni `DELETE`
 *    (migracion `101500`). Es el unico cerrojo que no se puede rodear desde PHP,
 *    y por eso es el que importa.
 * 2. **Este modelo.** Un `->update()` o un `->delete()` aqui **lanza excepcion**
 *    en lugar de llegar a la base de datos. No añade seguridad —la anterior ya
 *    la da— pero convierte un error de permisos oscuro, a las tres de la
 *    mañana, en un mensaje que dice exactamente que se ha intentado y por que no
 *    se puede.
 * 3. **La ausencia de puerta.** No hay repositorio publico de escritura: el
 *    unico camino es `AuditTrail::append()`.
 *
 * `$timestamps = false` porque la tabla no tiene `created_at`/`updated_at`: el
 * momento es `occurred_at`, y no hay actualizacion que fechar.
 */
final class AuditLogEntry extends Model
{
    protected $table = 'audit_log';

    /**
     * La clave primaria real es `(id, occurred_at)` —la clave de particion tiene
     * que formar parte de toda restriccion unica—, pero Eloquent no admite
     * claves compuestas. Se declara `id` para las lecturas; **no hay escritura
     * por clave** que pudiera equivocarse de fila, porque no hay escritura.
     */
    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'actor_id' => 'integer',
            'subject_id' => 'integer',
            'payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        self::updating(function (): never {
            throw new RuntimeException(
                'audit_log es solo-append (regla dura 6, ADR-010): una entrada de auditoria no se modifica. '
                .'Si el hecho cambio, se escribe otra entrada.'
            );
        });

        self::deleting(function (): never {
            throw new RuntimeException(
                'audit_log es solo-append (regla dura 6, ADR-010): una entrada de auditoria no se borra. '
                .'La purga por retencion es DROP PARTITION con el rol de mantenimiento (ADR-027, tarea 2.10).'
            );
        });
    }
}
