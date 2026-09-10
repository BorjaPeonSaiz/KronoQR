<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Por que se restauro la copia previa (RF-PD-10, RL-04, tarea 5.7).
 *
 * **Codigo cerrado y no el mensaje de error.** El mensaje de `update.sh` esta
 * traducido —el script habla espanol o ingles segun el operador—, es una frase
 * larga y cambia con cada revision del texto. Un `audit_log` que conserva cuatro
 * anos no puede tener como unico dato consultable una cadena que se reescribe:
 * la pregunta que este campo responde es «¿cuantas vueltas atras hubo por
 * migracion en esta instalacion?», y esa pregunta necesita un valor agrupable.
 *
 * **El detalle no se pierde**: sigue en el informe de la actualizacion y en el
 * fichero de detalle, que el asiento referencia por `report_id`. Aqui va el
 * codigo; alli, la frase.
 *
 * Cada valor corresponde a un punto de `rollback_and_die()` de `update.sh`; el
 * mapa exacto lo fija la tarea que engancha el script.
 */
enum SystemRestoreReason: string
{
    /** El `trap ERR` del script: un fallo no previsto en cualquier punto. */
    case UnexpectedError = 'unexpected_error';

    /** Una senal (`INT`, `TERM`, `HUP`) corto la actualizacion a media faena. */
    case Interrupted = 'interrupted';

    /** No se pudo entrar o salir del modo mantenimiento. */
    case MaintenanceFailed = 'maintenance_failed';

    /** Los procesos de fondo (`horizon`, `scheduler`) no pararon o no volvieron. */
    case WorkersFailed = 'workers_failed';

    /** No se pudo dejar el `.env` de la version nueva en su sitio. */
    case EnvPrepareFailed = 'env_prepare_failed';

    /** Un contenedor no arranco o no llego a sano dentro de su espera. */
    case ServiceStartFailed = 'service_start_failed';

    /** Una migracion fallo, o quedaron migraciones pendientes al terminar. */
    case MigrationFailed = 'migration_failed';

    /** `/health`, `/ready` o la ruta de gestion no respondieron lo esperado. */
    case HealthProbeFailed = 'health_probe_failed';

    /** La version que reporta `/health` no es la que se estaba instalando. */
    case VersionMismatch = 'version_mismatch';

    /**
     * `compliance:verify-audit-chain` fallo **despues** de migrar.
     *
     * Es el unico motivo que describe un dano al propio registro, y por eso el
     * asiento que lo lleva importa mas que ninguno: dice que la instalacion
     * volvio atras porque su trail dejo de verificar.
     */
    case AuditChainBroken = 'audit_chain_broken';

    /** Las restricciones que sostienen RN-01 y RN-02 no se pudieron verificar. */
    case ConstraintViolation = 'constraint_violation';

    /** El usuario de aplicacion tenia sobre `audit_log` permisos que no debe tener. */
    case PrivilegesCheckFailed = 'privileges_check_failed';

    /** `product:doctor` salio en rojo tras la actualizacion. */
    case DoctorFailed = 'doctor_failed';
}
