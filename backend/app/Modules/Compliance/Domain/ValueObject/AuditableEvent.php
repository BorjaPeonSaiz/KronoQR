<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\ValueObject;

/**
 * Las familias de hechos que **obligan** a escribir en `audit_log`
 * (`/revision-cumplimiento` bloque D, regla dura 6).
 *
 * Nacio con las **siete** del bloque D y hoy son diez. La tarea 2.6 anadio el
 * ciclo de vida de una incidencia, que cumple el mismo criterio —bajo volumen y
 * relevancia legal— y no cabia en ninguna de las siete; la 5.3 anadio el de la
 * licencia, que es la unica de relevancia **comercial** y explica en su propio
 * caso por que se audita igual; la 5.9 anadio el acceso de soporte, que es el
 * unico hecho del producto en el que alguien **ajeno a la organizacion del
 * cliente** recibe una potestad sobre su instalacion. Ampliar esta lista es una
 * decision, no un tramite: cada familia nueva tiene que decir por que lo es.
 *
 * Es el enunciado del bloque D convertido en codigo, y esta aqui por una razon
 * concreta: la lista vivia solo en una skill en Markdown, asi que una accion
 * nueva podia nacer sin auditoria y nada fallaba. Con el catalogo declarado,
 * cada `AuditAction` tiene que decir a que familia pertenece, y una prueba
 * comprueba que las diez siguen cubiertas.
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

    /**
     * Se concede, se usa o se revoca un **acceso de soporte** del fabricante, o
     * se genera un paquete de diagnostico (**RF-PD-11**, RF-PD-09, RL-18, RL-19,
     * ADR-020, tarea 5.9).
     *
     * **La decima familia, y la tercera que no estaba en el bloque D.** Entra
     * por el mismo criterio que la octava y la novena, y con la relevancia legal
     * mas directa de las tres: durante una intervencion de soporte **el
     * fabricante es encargado del tratamiento para ese supuesto concreto**
     * (RL-18), lo que exige el contrato de encargo del art. 28 RGPD. Lo que
     * acredita que ese encargo existio, con que alcance y durante cuanto, es
     * este trail y nada mas. Y es de bajo volumen por diseño: unas pocas
     * concesiones al año, con sus usos agrupados por ventana para que una sesion
     * de soporte no inunde la cadena por la que pasa cada fichaje.
     *
     * **No cabe en `PersonalDataAccess`, y la distincion no es sutil.** Aquella
     * familia responde a «¿que datos de terceros consulto esta cuenta?»: describe
     * un **dato mirado**. Esta describe una **potestad concedida** —quien puede
     * entrar, hasta cuando y para hacer que— que existe aunque no se use ni una
     * vez, y que en su alcance mas comun (`diagnostics`) no alcanza ni un solo
     * dato personal. Meterlas juntas dejaria sin respuesta la pregunta que hace
     * el cliente y que hace una inspeccion: «¿ha entrado el fabricante en mi
     * instalacion?», que no se contesta enumerando lecturas.
     *
     * **Tampoco en `AuthorityOrCalculationChange`.** Conceder soporte no mueve
     * un minuto trabajado ni cambia el rol de nadie de la organizacion: crea un
     * acceso temporal para alguien de fuera. Y sobre todo, quien consulta esa
     * familia pregunta «¿quien movio las reglas del calculo?»; mezclar ahi los
     * accesos del fabricante ensuciaria justo la consulta en la que eso mas
     * duele.
     *
     * **Y no en `LicenseLifecycle`**, aunque las dos hablen del fabricante: la
     * licencia es un hecho **comercial** que no da acceso a nada, y esta familia
     * es lo contrario — no tiene nada que ver con lo contratado y si con quien
     * puede leer datos de jornada. La regla dura 15 las separa ademas por
     * comportamiento: una concesion se emite y se revoca igual con la licencia
     * caducada, porque es cuando mas falta hace.
     */
    case SupportAccess = 'support_access';
}
