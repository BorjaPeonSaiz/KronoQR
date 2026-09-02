<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Las familias de hechos que **obligan** a escribir en `audit_log`
 * (`/revision-cumplimiento` bloque D, regla dura 6).
 *
 * Nacio con las **siete** del bloque D y hoy son nueve. La tarea 2.6 anadio el
 * ciclo de vida de una incidencia, que cumple el mismo criterio —bajo volumen y
 * relevancia legal— y no cabia en ninguna de las siete; la 5.3 anadio el de la
 * licencia, que es la unica de relevancia **comercial** y explica en su propio
 * caso por que se audita igual. Ampliar esta lista es una decision, no un
 * tramite: cada familia nueva tiene que decir por que lo es.
 *
 * Es el enunciado del bloque D convertido en codigo, y esta aqui por una razon
 * concreta: la lista vivia solo en una skill en Markdown, asi que una accion
 * nueva podia nacer sin auditoria y nada fallaba. Con el catalogo declarado,
 * cada `AuditAction` tiene que decir a que familia pertenece, y una prueba
 * comprueba que las nueve siguen cubiertas.
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

    /**
     * Abre o cierra una **incidencia** del registro horario (RF-PR-01, tarea
     * 2.6).
     *
     * **La octava familia, y la primera que no estaba en el bloque D.** Se anade
     * porque cumple el criterio que ese bloque enuncia y que ADR-039 desarrolla:
     * es un hecho de **bajo volumen** —unas decenas al dia, frente a los miles de
     * escaneos— y con **relevancia legal directa**. Una incidencia
     * `insufficient_rest` afirma que un descanso quedo por debajo del minimo del
     * art. 34.3 ET; darla por resuelta afirma que alguien lo reviso. Las dos son
     * exactamente el tipo de afirmacion que una inspeccion pide reconstruir, y sin
     * asiento la unica prueba de que existieron seria una fila que la aplicacion
     * puede actualizar.
     *
     * No cabe en `ShiftEntryLifecycle`: abrir una incidencia no crea, modifica,
     * anula ni cierra ningun fichaje — precisamente lo que RN-08 prohibe hacer
     * automaticamente.
     */
    case IncidentLifecycle = 'incident_lifecycle';

    /**
     * Se activa una licencia, o un alta deja la instalacion por encima del plan
     * (**RF-PD-04**, ADR-018, ADR-028, tarea 5.3).
     *
     * **La novena familia, y la segunda que no estaba en el bloque D.** Entra
     * por el mismo criterio que la octava —bajo volumen y necesidad de
     * reconstruir el hecho mucho despues— con una diferencia que conviene
     * declarar: **su relevancia es comercial antes que legal**. Es la unica
     * familia de esta lista que no responde a una obligacion del art. 34 ET.
     *
     * Se audita igual, y por dos razones. La primera la escribe ADR-028: el
     * asiento de exceso es *«la prueba que sostiene la reclamacion comercial: la
     * fecha exacta desde la que el cliente opera por encima del plan»*, y sin el
     * el cliente puede alegar con razon que nadie se lo dijo. La segunda es
     * simetrica y protege al cliente: activar una clave cambia que
     * funcionalidades tiene, y el trail es donde consta quien lo hizo y cuando.
     *
     * **No cabe en `AuthorityOrCalculationChange`.** Esa familia responde a
     * «¿quien movio las reglas del calculo?», que es una pregunta de inspeccion.
     * Ni activar una licencia ni superar `max_employees` mueven un solo minuto
     * trabajado ni conceden una potestad a nadie; meterlas ahi obligaria a
     * separar el ruido comercial de los cambios de umbral justo en la consulta
     * en la que eso mas duele.
     *
     * **Lo que este asiento no puede significar nunca** es que algo se haya
     * impedido: ninguna de las dos acciones bloquea nada (ADR-019, ADR-028,
     * regla dura 15).
     */
    case LicenseLifecycle = 'license_lifecycle';
}
