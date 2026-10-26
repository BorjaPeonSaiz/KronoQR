<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Exception;

use App\Modules\Shared\Application\Port\DatabaseFailureTranslator;

/**
 * Que le ha pasado a una operacion contra la base de datos, en el vocabulario
 * que necesitan los casos de uso. Los codigos del motor (`SQLSTATE`) los
 * traduce el adaptador ({@see DatabaseFailureTranslator});
 * aqui solo llega su significado.
 */
enum DatabaseFailureKind: string
{
    /** El candado no llego dentro de `lock_timeout`. */
    case LockNotAvailable = 'lock_not_available';

    /** El motor rompio un abrazo de candados y eligio esta transaccion. */
    case Deadlock = 'deadlock';

    /** Una transaccion serializable no pudo confirmarse sin contradecir a otra. */
    case SerializationFailure = 'serialization_failure';

    /** La consulta se corto por `statement_timeout`. */
    case QueryCanceled = 'query_canceled';

    /** Cualquier otro fallo del motor o del driver. */
    case Other = 'other';

    /**
     * No se pudo tomar un candado a tiempo: no se llego a hacer nada y la
     * pasada siguiente lo vuelve a intentar.
     */
    public function isContention(): bool
    {
        return $this === self::LockNotAvailable || $this === self::Deadlock;
    }

    /**
     * Dos escrituras simultaneas se cruzaron y el motor deshizo esta entera:
     * repetirla tiene sentido.
     */
    public function isConcurrency(): bool
    {
        return $this === self::Deadlock || $this === self::SerializationFailure;
    }
}
