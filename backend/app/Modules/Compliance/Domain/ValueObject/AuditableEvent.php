<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Las siete familias de hechos que **obligan** a escribir en `audit_log`
 * (`/revision-cumplimiento` bloque D, regla dura 6).
 *
 * Es el enunciado del bloque D convertido en codigo, y esta aqui por una razon
 * concreta: la lista vivia solo en una skill en Markdown, asi que una accion
 * nueva podia nacer sin auditoria y nada fallaba. Con el catalogo declarado,
 * cada `AuditAction` tiene que decir a que familia pertenece, y una prueba
 * comprueba que las siete siguen cubiertas.
 *
 * *Ante la duda, si.* El coste de auditar de mas es despreciable; el de auditar
 * de menos es una inspeccion que no puede reconstruir quien hizo que.
 */
enum AuditableEvent: string
{
    /** Crea, modifica, anula o cierra un fichaje. */
    case ShiftEntryLifecycle = 'shift_entry_lifecycle';

    /** Emite, imprime, entrega, revoca o reemite una credencial. */
    case CredentialLifecycle = 'credential_lifecycle';

    /** Provisiona, empareja o revoca un dispositivo. */
    case DeviceLifecycle = 'device_lifecycle';

    /** Accede a datos personales de terceros (RS-05). */
    case PersonalDataAccess = 'personal_data_access';

    /** Genera una exportacion legal (RL-03, RL-06). */
    case LegalExport = 'legal_export';

    /** Cambia roles, permisos o configuracion con efecto en el calculo de horas. */
    case AuthorityOrCalculationChange = 'authority_or_calculation_change';

    /** Ejecuta una purga por retencion (RL-02, ADR-027). */
    case RetentionPurge = 'retention_purge';
}
