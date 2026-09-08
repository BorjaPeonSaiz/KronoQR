<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Que puede hacer un acceso de soporte (**RF-PD-11**, ADR-020, tarea 5.9).
 *
 * El campo `scope` existe en el modelo de datos del doc 01 §5 y **ningun
 * documento enumeraba sus valores**: los tres de este enum son la decision de la
 * tarea 5.9 (punto no cubierto 12), y el contrato los declara en el esquema
 * `SupportScope`.
 *
 * ## Los dos controles, no uno
 *
 * Cada alcance se traduce en las **dos** mitades con las que este producto
 * autoriza cualquier sesion (doc 02 §7.3, regla dura 18):
 *
 * - {@see self::abilities()} — los **ambitos del token**, que comprueba el
 *   middleware `ability` de Sanctum. Dicen *que* puede hacer.
 * - {@see self::actsAs()} — el **rol** con el que la concesion se presenta ante
 *   las policies. Dice *sobre que datos*.
 *
 * Con una sola de las dos, un alcance mal escrito seria un acceso de mas: los
 * ambitos sin rol dejarian pasar cualquier policy que no mire el rol, y el rol
 * sin ambitos dejaria pasar cualquier ruta que solo mire el ambito.
 *
 * ## Por que los ambitos se escriben aqui y no se importan de `Identity`
 *
 * Porque `Product` no puede importar nada de `Identity` (doc 02 §1.6, verificado
 * por Deptrac), y al reves tampoco: el `tokenable` de una concesion es una fila
 * de `support_grants`, que es una tabla de `Product`, asi que el emisor del
 * token tiene que vivir en este modulo — un adaptador en `Identity` tendria que
 * tocar el modelo Eloquent de otro modulo, que es justo lo que el §1.6 prohibe
 * sin matices.
 *
 * Es la **cuarta copia** de esas cadenas, y entra por la puerta que el catalogo
 * `Identity\Domain\ValueObject\TokenAbility` ya declara para las otras tres —el
 * contrato OpenAPI y la migracion del catalogo de roles—: *«las tres copias las
 * ata una prueba, no la buena fe»*. La prueba que ata esta es
 * `tests/Unit/Product/Domain/SupportScopeTest.php`, que exige que cada cadena de
 * aqui exista en aquel enum. Sin ella, una errata no rompe nada visible: el
 * token simplemente no autoriza, o autoriza de mas.
 *
 * ## Lo que NINGUN alcance concede nunca
 *
 * `license:*`, `support:*`, `employees:*`, `credentials:*`,
 * `attendance:correct`, `reports:*` y la inclusion de datos personales en un
 * paquete de diagnostico (RL-19). No es una omision: es la lista de puertas que
 * el fabricante no cruza ni con permiso, porque son actos del **cliente** —lo
 * que contrato, a quien deja entrar, quien esta en su plantilla, que horas
 * constan trabajadas y que datos personales salen de su instalacion—. Cada una
 * tiene su prueba de que un token de soporte recibe `403`.
 */
enum SupportScope: string
{
    /**
     * El alcance por defecto y el que basta para la mayoria de las incidencias:
     * generar el paquete **anonimizado** y consultar el historico de errores.
     *
     * **No alcanza ni un solo dato personal**, y esa es toda su gracia: es el
     * alcance con el que se resuelve un problema sin convertir al fabricante en
     * encargado de nada que importe.
     */
    case Diagnostics = 'diagnostics';

    /**
     * Lo anterior y **leer** jornadas, tramos, plantilla y auditoria.
     *
     * Existe para la incidencia que el paquete no resuelve: «a esta persona le
     * salen ocho horas y deberian ser nueve».
     *
     * **Lo que lo hace de solo lectura son sus tres ambitos, no su rol.** Lleva
     * `attendance:read`, `employees:read` y `audit:read`, y esas tres familias
     * no abren en toda la API ni una sola ruta que no sea `GET` — lo comprueba
     * `SupportScopeRoutesTest` recorriendo las rutas registradas, no un
     * comentario. Ante las policies actua como `admin`, que es lo unico que le
     * permite llegar a las pantallas del registro horario; ver
     * {@see self::actsAs()}.
     *
     * **Nunca `attendance:correct`, ni `employees:*`, ni `reports:legal`**:
     * corregir una hora trabajada, mover la plantilla o emitir la exportacion
     * para la Inspeccion son actos del cliente con valor legal, y el fabricante
     * no los hace ni con permiso (RL-19).
     */
    case ReadOnly = 'read_only';

    /**
     * Lo de `diagnostics` y **cambiar** la configuracion de la instalacion, el
     * perfil de cumplimiento y los quioscos.
     *
     * Para la incidencia de puesta en marcha: un umbral operativo mal puesto, un
     * quiosco que no vincula, una zona horaria equivocada. **No lee jornadas**:
     * cambiar un ajuste y leer el registro de la plantilla son dos potestades
     * distintas, y quien necesita la primera no necesita la segunda.
     *
     * ## Con una excepcion dentro del propio ambito: el PERFIL DE CUMPLIMIENTO
     *
     * `settings:*` cubre tambien `PATCH /api/v1/compliance-profile` (doc 02 §7.3,
     * precision 4), y ahi **este alcance recibe `403`**: lo cierra
     * `ComplianceProfilePolicy::update()`, no el ambito.
     *
     * Lo que hay en ese recurso son los **umbrales legales** —descanso minimo,
     * jornada maxima, pausas— y `retention_years`, los años que se conserva el
     * registro horario (RL-01, RL-02, regla dura 14). Los fija la jurisdiccion
     * del cliente y su asesoria: mover uno cambia las incidencias que se detectan
     * sobre horas **ya trabajadas** de toda la plantilla, y bajar la retencion
     * acorta la vida de un registro con valor probatorio. Ninguna de las dos la
     * hace quien esta arreglando una incidencia tecnica.
     *
     * **Leerlo si puede**, que es lo que hace falta para diagnosticar por que
     * salta una incidencia. Decision de `seguridad-cumplimiento` en la revision
     * de la tarea 5.9.
     */
    case Configuration = 'configuration';

    /**
     * El alcance de serie cuando no se pide ninguno.
     *
     * Es el mas estrecho a proposito: quien concede acceso deprisa, en mitad de
     * una incidencia, tiene que acabar con el minimo y no con el maximo.
     */
    public static function default(): self
    {
        return self::Diagnostics;
    }

    /**
     * Los ambitos del token que emite una concesion con este alcance (doc 02
     * §7.3).
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        return match ($this) {
            // Solo el paquete anonimizado y el historico de errores.
            self::Diagnostics => ['diagnostics:*'],
            // Lo anterior y las tres familias de LECTURA del §7.3. Ninguna de
            // las tres abre una ruta que no sea `GET` (SupportScopeRoutesTest).
            self::ReadOnly => ['diagnostics:*', 'attendance:read', 'employees:read', 'audit:read'],
            // Lo de `diagnostics` y la configuracion de la instalacion, que
            // incluye el perfil de cumplimiento y los quioscos (§7.3,
            // precisiones 4 y 5).
            self::Configuration => ['diagnostics:*', 'settings:*'],
        };
    }

    /**
     * El rol con el que la concesion se presenta ante las policies: `admin`,
     * los tres.
     *
     * ## Lo que limita a cada alcance son los AMBITOS, no el rol
     *
     * Y no es una comodidad: es la unica lectura que hace ciertas las tres filas
     * de la tabla del contrato. El `auditor` de este producto **no es «solo
     * lectura de todo»**: es el rol de una funcion concreta —el requerimiento de
     * la Inspeccion— y las policies del registro horario lo excluyen a proposito
     * (ver el docblock de `Reporting\Http\Policy\WorkDayJournalPolicy`, que lo
     * razona por escrito). Con `auditor`, el alcance `read_only` no alcanzaba
     * **ninguna** pantalla: ni jornadas, ni plantilla, ni la exportacion —que
     * exige `reports:legal`, y ese ambito no lo concede ni puede concederlo
     * ningun alcance de soporte (RL-19)—.
     *
     * Asi que el rol dice **hasta donde** se ve —alcance completo, sin
     * departamento— y los ambitos dicen **que** se puede hacer con lo que se ve.
     * Las dos comprobaciones del §7.3 siguen siendo dos, y la que acota a
     * `read_only` es la del ambito:
     *
     * | Alcance | Ambitos | Lo que abre de verdad |
     * | --- | --- | --- |
     * | `diagnostics` | `diagnostics:*` | El paquete anonimizado y el historico de errores |
     * | `read_only` | + `attendance:read`, `employees:read`, `audit:read` | **Solo rutas `GET`**: jornadas, presencia y plantilla |
     * | `configuration` | + `settings:*` | Ajustes de instalacion y quioscos. **No** el perfil de cumplimiento: lo cierra su policy (RL-01, RL-02) |
     *
     * **Que los tres ambitos de lectura no abran ni una sola ruta de escritura no
     * se confia: se comprueba.** `SupportScopeRoutesTest` recorre las rutas
     * registradas y falla si alguna que no sea `GET` los acepta. Es lo que
     * convierte «solo lectura» en una propiedad verificada y no en el nombre de
     * un enum.
     */
    public function actsAs(): UserRole
    {
        return UserRole::ADMIN;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(static fn (self $scope): string => $scope->value, self::cases());
    }
}
