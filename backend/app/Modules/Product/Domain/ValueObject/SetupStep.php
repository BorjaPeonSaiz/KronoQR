<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Product\Domain\Exception\UnknownSetupStep;

/**
 * Los pasos del asistente de puesta en marcha (**RF-PD-03**), en el orden en
 * que se presentan.
 *
 * ## El administrador va primero, y no quinto
 *
 * RF-PD-03 los enumera «organizacion, centro, departamentos, perfil de convenio,
 * primer administrador, quiosco». Esa es la lista de **que** recoge el
 * asistente, no un orden de ejecucion, y el orden se invierte por la regla dura
 * 6: la creacion del centro, la activacion de la licencia y el alta masiva de
 * plantilla dejan asiento en `audit_log`, y un asiento **sin actor** —que es lo
 * unico que se podria escribir antes de que exista una cuenta— no responde a la
 * pregunta para la que el trail existe. Con el administrador primero, todo lo
 * demas ocurre autenticado y todo asiento tiene una persona detras.
 *
 * ## Ocho pasos y ni uno mas
 *
 * Los seis de RF-PD-03, mas {@see self::EMPLOYEES} —que es RF-GP-05, movido a
 * esta tarea— y {@see self::LICENSE}. Cada paso extra es una barrera entre el
 * cliente y su primer fichaje, asi que la lista no crece «por si acaso».
 *
 * ## Derivados, obligatorios y omitibles
 *
 * Tres propiedades distintas y no una sola bandera:
 *
 * - **Derivado** es el paso cuyo estado se lee del dato y no de una marca
 *   ({@see self::isDerived()}). Es lo que impide que una fila perdida o un `PUT`
 *   mal dirigido hagan que el asistente afirme que hay administrador.
 * - **Obligatorio** es el que impide cerrar el asistente si no esta hecho.
 * - **Omitible** es el que admite `skipped`. La licencia lo es **por la regla
 *   dura 15**: un asistente que la exigiera para terminar convertiria la
 *   licencia en requisito de arranque del registro horario. El perfil de
 *   convenio **no lo es**, y ahi la razon es RL-21: los umbrales hay que
 *   contrastarlos con el convenio aplicable, y eso es un acto de alguien y no un
 *   valor por defecto que nadie miro.
 */
enum SetupStep: string
{
    /** Primera cuenta de gestion, con su segundo factor confirmado (RS-06). */
    case ADMINISTRATOR = 'administrator';

    /** Nombre visible e idiomas de la instalacion: claves de `installation_settings`. */
    case ORGANISATION = 'organisation';

    /** El centro y su zona horaria (ADR-040, regla dura 3). */
    case SITE = 'site';

    /** Departamentos. Omitible: un hotel pequeño puede no tenerlos. */
    case DEPARTMENTS = 'departments';

    /** Perfil de convenio con sus umbrales a la vista (RL-21, RF-PD-07). */
    case COMPLIANCE_PROFILE = 'compliance_profile';

    /** Carga de plantilla (RF-GP-05). Omitible: se puede dar de alta a mano. */
    case EMPLOYEES = 'employees';

    /** Activacion de la clave de licencia (RF-PD-04). Omitible por la regla dura 15. */
    case LICENSE = 'license';

    /** Vinculacion del primer quiosco (RF-PD-06). Omitible: puede que la tablet no haya llegado. */
    case KIOSK = 'kiosk';

    /**
     * La clave con la que se guarda el **cierre** del asistente.
     *
     * No es un paso: no esta en este enum, no viaja en el contrato y no aparece
     * en `steps`. Vive aqui porque comparte tabla con los pasos y porque tener
     * la cadena escrita en un solo sitio es lo que evita que el repositorio y la
     * migracion se separen.
     */
    public const string COMPLETION_KEY = 'completion';

    /**
     * @throws UnknownSetupStep
     */
    public static function fromString(string $step): self
    {
        return self::tryFrom($step) ?? throw new UnknownSetupStep($step);
    }

    /**
     * Su estado se lee del dato, no de una marca, y por eso no se puede declarar
     * a mano (`422` en `PUT /api/v1/setup/steps/{step}`).
     */
    public function isDerived(): bool
    {
        return $this === self::ADMINISTRATOR || $this === self::SITE;
    }

    /** Sin el, el asistente no se puede cerrar. */
    public function isRequired(): bool
    {
        return match ($this) {
            self::ADMINISTRATOR, self::ORGANISATION, self::SITE, self::COMPLIANCE_PROFILE => true,
            self::DEPARTMENTS, self::EMPLOYEES, self::LICENSE, self::KIOSK => false,
        };
    }

    /**
     * Admite `skipped`.
     *
     * Coincide hoy con «no obligatorio», y son dos preguntas distintas a
     * proposito: obligatorio dice si bloquea el cierre, omitible dice si se
     * puede declarar aparcado. El dia que un paso sea obligatorio y aun asi
     * aparcable —o al reves—, la diferencia ya esta escrita.
     */
    public function isSkippable(): bool
    {
        return ! $this->isRequired();
    }
}
