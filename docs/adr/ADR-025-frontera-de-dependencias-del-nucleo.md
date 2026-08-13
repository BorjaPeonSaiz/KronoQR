# ADR-025 — El núcleo declara sus puertos; los satélites los implementan

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 13 de agosto de 2026 |
| **Decide** | `arquitecto-dominio` |
| **Afecta a** | Tareas 1.1, 1.5, 1.6 y 5.1 · §1.5 y §1.6 del documento 02 · Regla dura 1 de `CLAUDE.md` |
| **Requisitos** | RN-05, RN-14, RF-AT-01, RF-QR-03, RF-PD-07 |

## Contexto

El [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §1.6 fija las fronteras entre módulos con una tabla de dependencias admitidas. `Attendance`, `Identity`, `Workforce` y `Product` declaran cada uno una sola: `Shared`. Y añade que *«la comunicación entre módulos ocurre solo por dos vías: casos de uso públicos con interfaz explícita, o eventos de dominio»*.

Al desarrollar las tareas 1.1 y 1.5 del plan de implementación apareció que **el núcleo no puede hacer su trabajo bajo esa tabla**. Tres necesidades concretas, ninguna evitable:

1. **Resolver una credencial.** La tarea 1.1 declara el puerto `CredentialResolver` en `Attendance/Application/Port/`, y la 1.5 sitúa su adaptador `HmacSignatureVerifier` en `Identity/Infrastructure/`, que es donde vive la tabla `credentials`. Eso es `Identity` dependiendo de `Attendance`, arista que la tabla no concede. El §1.5 lo dibuja al revés —el adaptador dentro de `Attendance`—, lo que obligaría a `Attendance` a leer una tabla de `Identity`, arista que tampoco se concede.

2. **Conocer la zona horaria del centro.** RN-05 atribuye la jornada a *«la fecha civil, en la zona del centro»*, dato que vive en `sites.timezone`, propiedad de `Workforce`. La lista de puertos de la tarea 1.1 no incluye ninguno que lo entregue.

3. **Conocer el estado del empleado.** RN-14 exige rechazar el escaneo de un empleado de baja. `employees.status` es de `Workforce`. Mismo hueco.

A lo que se suma una contradicción heredada: `CompliancePolicyProvider` está declarado a la vez en `Attendance/Application/Port/` (tarea 1.1, que además lo fija como *«nomenclatura válida para todo el proyecto»*) y en `Product/Application/Port/` (tarea 5.1), con el adaptador leyendo `compliance_profiles`, tabla de `Product`.

**Con la tabla tal cual, Deptrac falla se ponga el adaptador donde se ponga.** Y la salida más probable bajo presión de entrega es que alguien lea `App\Models\Employee` directamente desde `Attendance`, que es exactamente lo que la regla dura 1 existe para impedir. Una frontera que obliga a incumplirla no protege nada.

## Decisión

**El módulo que necesita algo declara el puerto. El módulo que tiene el dato implementa el adaptador. La arista va siempre del satélite al núcleo, nunca al revés.**

Es inversión de dependencias aplicada entre módulos, y añade una **tercera vía de comunicación** a las dos del §1.6: *implementar un puerto declarado por el módulo consumidor*.

### Los puertos del núcleo

`Attendance/Application/Port/` declara cinco:

| Puerto | Qué entrega | Quién lo implementa |
|---|---|---|
| `WorkDayRepository` | Persistencia del agregado | `Attendance/Infrastructure/Persistence/` |
| `EventPublisher` | Publicación de eventos de dominio | `Attendance/Infrastructure/Adapter/` |
| `CredentialResolver` | De un payload QR firmado al `employee_uuid`, o rechazo | **`Identity`** |
| `EmployeeDirectory` | Estado y adscripción del empleado (RN-14) | **`Workforce`** |
| `SiteCalendar` | Zona horaria del centro y su calendario (RN-05) | **`Workforce`** |

**Los dos proveedores de umbrales no son de `Attendance`.** `CompliancePolicyProvider` (umbrales legales, de `compliance_profiles`) y `OperationalSettingsProvider` (umbrales operativos, de `installation_settings`) suben a `Shared/Application/Port/` por el mismo criterio de admisión que ADR-021 fijó para `Clock` —*«más de un módulo lo necesita y no representa una regla de negocio de ninguno»*—: el primero lo consumen `Attendance`, `Compliance` y `Reporting`; el segundo, `Attendance` y `Kiosk` (RF-AT-10, la tolerancia de desfase de reloj, es suya). Sus adaptadores viven en `Product/Infrastructure/Adapter/`, que es donde están las dos tablas.

La distinción entre umbral legal y umbral operativo que fija la tarea 1.1 **no cambia**: siguen siendo dos puertos porque son dos fuentes distintas —uno lo fija la jurisdicción, otro el hotel—. Lo único que cambia es el módulo donde se declaran.

```
Attendance/Application/Port/CredentialResolver.php     ← declara el núcleo
Identity/Infrastructure/Adapter/HmacSignatureVerifier.php   ← implementa Identity

Attendance/Application/Port/EmployeeDirectory.php
Attendance/Application/Port/SiteCalendar.php
Workforce/Infrastructure/Adapter/EloquentEmployeeDirectory.php
Workforce/Infrastructure/Adapter/EloquentSiteCalendar.php

Shared/Application/Port/CompliancePolicyProvider.php
Shared/Application/Port/OperationalSettingsProvider.php
Product/Infrastructure/Adapter/DbCompliancePolicyProvider.php
Product/Infrastructure/Adapter/DbOperationalSettingsProvider.php
```

### Las tres restricciones que hacen que esto siga siendo una frontera

Sin ellas, «los satélites pueden depender del núcleo» degenera en «todo el mundo puede con todo».

1. **Solo `Infrastructure` del satélite depende, y solo de `Application/Port/` del núcleo.** Nunca de su `Domain/`, nunca de sus casos de uso, nunca de sus modelos Eloquent.
2. **Los puertos hablan en tipos de `Shared` o escalares.** `EmployeeDirectory` no devuelve un modelo Eloquent ni una entidad de `Workforce`: devuelve un objeto de valor inmutable (`EmployeeSnapshot`) con lo que el núcleo necesita y nada más. Es lo que impide que el acoplamiento se cuele por el tipo de retorno.
3. **El enlace puerto→adaptador se declara en el `ServiceProvider` del satélite.** `IdentityServiceProvider` enlaza `CredentialResolver`; `WorkforceServiceProvider` los otros dos. `Attendance` no nombra a nadie: no sabe quién lo sirve, que es el punto entero.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Adaptadores dentro de `Attendance`, consumiendo casos de uso públicos de `Identity` y `Workforce`** | Convierte al núcleo en dependiente de otros tres módulos. Un cambio en `Workforce` pasa a poder romper el fichaje, que es justo la relación que una arquitectura modular existe para invertir. Además cada resolución de credencial pasaría por un caso de uso completo por escaneo, con RNF-P-06 exigiendo 50 por segundo |
| **Los cinco puertos a `Shared`** | Cero aristas nuevas y Deptrac trivialmente en verde, pero `CredentialResolver`, `EmployeeDirectory` y `SiteCalendar` **sí representan reglas de `Attendance`**: definen qué necesita saber el núcleo para decidir un fichaje. Moverlos incumple el criterio de admisión de ADR-021 y convierte `Shared` en el cajón de sastre que ese ADR se comprometió a vigilar |
| **Acceso directo a los modelos Eloquent de otro módulo** | Regla dura 1 y §1.6. Es la salida que este ADR existe para cerrar, no una opción |
| **Duplicar en `Attendance` las tablas que necesita** | Dos fuentes de verdad sobre el estado de un empleado. La que se desincronice producirá fichajes aceptados de personal de baja, incumpliendo RN-14 en silencio |

## Consecuencias

- **La tabla del §1.6 gana tres aristas explícitas**, y la nota de las dos vías de comunicación pasa a enumerar tres. La tabla deja de describir un ideal inalcanzable y describe lo que se va a construir.
- **`deptrac.yaml` necesita reglas nuevas, no excepciones.** La diferencia importa: una excepción es un agujero con nombre; estas son aristas declaradas con su capa de origen y de destino, verificables. `Identity/Infrastructure → Attendance/Application/Port` es una regla; `Identity → Attendance` no lo sería.
- **Sustituye a la última frase de ADR-021 sobre `CompliancePolicyProvider`.** Aquel ADR afirmaba que *«`WorkDayRepository`, `EventPublisher`, `CredentialResolver` y `CompliancePolicyProvider` no se mueven: son puertos de `Attendance` y solo `Attendance` los usa»*. Era cierto para los dos primeros y falso para los dos últimos, y este ADR lo corrige. `ADR-021` no se anula: se acota.
- **La tarea 1.1 declara cinco puertos, no tres**, y su presupuesto sube en consecuencia: `EmployeeSnapshot` y la zona horaria del centro son entradas del cálculo de RN-05, no un detalle de infraestructura.
- **La tarea 1.5 deja de estar en falso.** Su adaptador vive en `Identity` con una arista declarada, y no hay que decidirlo al implementar.
- **`Kiosk` no cambia.** Su dependencia de `Attendance` *«vía caso de uso»* ya estaba concedida y sigue siendo la vía correcta: `Kiosk` **usa** el fichaje, no lo sirve.

## Verificación

- **Deptrac en verde con las tres aristas declaradas como reglas** y ninguna excepción en `deptrac.yaml`. Si hiciera falta una excepción, la ubicación de algún adaptador sería la equivocada.
- Prueba de arquitectura: **`Modules/Attendance` no importa nada de `Identity`, `Workforce`, `Product` ni `Compliance`.** Es la que verifica que el núcleo sigue siendo núcleo, y la que falla primero si alguien invierte una flecha.
- Prueba de arquitectura: `Identity/Infrastructure` y `Workforce/Infrastructure` importan de `Attendance\Application\Port` **y de nada más de `Attendance`**.
- Prueba de arquitectura: ningún puerto de `Attendance/Application/Port/` tiene en su firma un tipo de `Identity`, `Workforce` o `Illuminate\*`. Es la que protege la restricción 2, que es la que se erosiona sin darse cuenta.
- Prueba de arquitectura: una sola declaración de `CompliancePolicyProvider` y una sola de `DbCompliancePolicyProvider` en todo el árbol (mismo criterio que ADR-021 aplicó a `Clock`).
- Prueba unitaria de dominio: RN-05 calcula la jornada con la zona del centro inyectada, y RN-14 rechaza al empleado de baja, ambas con dobles de los puertos y sin base de datos.
