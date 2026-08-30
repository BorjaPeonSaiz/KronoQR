<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Los cuatro tipos de dato con plazo propio (RL-11, doc 02 §8.2.1).
 *
 * **La lista es cerrada y es la decision de la tarea 2.10**: si manana aparece
 * un almacen nuevo con datos que caducan, tiene que entrar aqui —y en el informe
 * de purga— o quedara conservado para siempre sin que nadie lo note. Un `match`
 * sin `default` en la politica obliga a decidir su plazo en el momento de
 * anadirlo.
 *
 * **Por que `WorkRecords` y `AuditLog` van separados teniendo el mismo plazo.**
 * Porque se purgan de forma distinta y con roles distintos: el registro de
 * jornada se borra por lotes con el rol de la aplicacion, y `audit_log`
 * **nunca** se borra —se suelta la particion entera con el rol de mantenimiento,
 * previa verificacion y sellado de la cadena (ADR-027)—. Juntarlos en un solo
 * ambito habria invitado a un `DELETE` sobre el registro probatorio.
 */
enum RetentionScope: string
{
    /** Tramos, totales diarios, correcciones, escaneos e incidencias (RL-02). */
    case WorkRecords = 'work_records';

    /** La cadena de auditoria, por particiones anuales (RL-02, ADR-027). */
    case AuditLog = 'audit_log';

    /** Ficheros de log de la aplicacion (RL-11). */
    case TechnicalLog = 'technical_log';

    /** Historico de errores agrupado por huella (RF-PD-15, tarea 5.12). */
    case ErrorHistory = 'error_history';

    /** Etiqueta para el informe y la consola. Sin i18n: el informe es del servidor. */
    public function label(): string
    {
        return match ($this) {
            self::WorkRecords => 'Registro de jornada',
            self::AuditLog => 'Cadena de auditoria',
            self::TechnicalLog => 'Log tecnico',
            self::ErrorHistory => 'Historico de errores',
        };
    }

    /** Si su plazo lo fija el perfil de cumplimiento (anos) o la instalacion (dias). */
    public function isLegalRecord(): bool
    {
        return $this === self::WorkRecords || $this === self::AuditLog;
    }

    /**
     * Que se cuenta en este ambito. El log tecnico son ficheros y no filas, y
     * llamarlos «filas» en el informe hace dudar de si se han borrado lineas de
     * dentro de un fichero, que es otra cosa.
     */
    public function unit(): string
    {
        return $this === self::TechnicalLog ? 'ficheros' : 'filas';
    }
}
