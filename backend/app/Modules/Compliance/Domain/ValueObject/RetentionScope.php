<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Los tipos de dato con plazo propio (RL-11, doc 02 §8.2.1). Desde la 2.3.0
 * (ADR-060) tambien la ficha de quien ya no esta y la cuenta de gestion dada de
 * baja, que no se borran: se minimizan en sitio.
 *
 * **La lista es cerrada y es la decision de la tarea 2.10**: si manana aparece
 * un almacen nuevo con datos que caducan, tiene que entrar aqui —y en el informe
 * de purga— o quedara conservado para siempre sin que nadie lo note. Un `match`
 * sin `default` en la politica obliga a decidir su plazo en el momento de
 * anadirlo.
 *
 * **`employment_contracts` NO esta aqui, y no es un olvido** (cierre de Fase 2):
 * su plazo —relacion laboral + 4 anos, art. 21 LISOS como referencia— lo tiene
 * que confirmar la asesoria laboral en la tarea 5.2, y hasta entonces se
 * conserva. Queda documentado como categoria en `docs/cliente/obligaciones-legales.md`
 * §4 y en `docs/runbooks/solicitud-derechos-rgpd.md` §1.
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
    /**
     * Tramos, totales diarios, correcciones, escaneos e incidencias (RL-02), y
     * los avisos de fichaje descartado (RN-22, ADR-047), que
     * envejecen con `scan_events`.
     */
    case WorkRecords = 'work_records';

    /** La cadena de auditoria, por particiones anuales (RL-02, ADR-027). */
    case AuditLog = 'audit_log';

    /** Ficheros de log de la aplicacion (RL-11). */
    case TechnicalLog = 'technical_log';

    /** Historico de errores agrupado por huella, tabla `error_events` (RF-PD-15). */
    case ErrorHistory = 'error_history';

    /**
     * La ficha de una persona dada de baja (ADR-060, tarea 11.3 A): pasado
     * `personnel_record_retention_years` desde su cese, y cuando ya no queda
     * nada suyo en el registro ni en la cadena viva, se minimiza en sitio. Sus
     * contratos y ausencias se borran con ella.
     */
    case PersonnelRecords = 'personnel_records';

    /**
     * La cuenta de gestion dada de baja (ADR-060, tarea 11.3 B): pasado
     * `retention_years` desde la baja, y cuando ya no queda ningun asiento ni
     * nada que la nombre como autora, se minimiza en sitio.
     */
    case ManagementAccounts = 'management_accounts';

    /** Etiqueta para el informe y la consola. Sin i18n: el informe es del servidor. */
    public function label(): string
    {
        return match ($this) {
            self::WorkRecords => 'Registro de jornada',
            self::AuditLog => 'Cadena de auditoria',
            self::TechnicalLog => 'Log tecnico',
            self::ErrorHistory => 'Historico de errores',
            self::PersonnelRecords => 'Fichas de personal',
            self::ManagementAccounts => 'Cuentas de gestion',
        };
    }

    /**
     * Que se cuenta en este ambito. El log tecnico son ficheros y no filas, y
     * llamarlos «filas» en el informe hace dudar de si se han borrado lineas de
     * dentro de un fichero, que es otra cosa.
     */
    public function unit(): string
    {
        return match ($this) {
            self::TechnicalLog => 'ficheros',
            self::PersonnelRecords => 'personas',
            self::ManagementAccounts => 'cuentas',
            default => 'filas',
        };
    }
}
