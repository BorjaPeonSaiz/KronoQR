# ADR-021 — El puerto `Clock` vive en `Shared/Application/Port/`

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 12 de agosto de 2026 |
| **Decide** | `arquitecto-dominio` |
| **Afecta a** | Tareas 1.1 y 0.2 · Regla dura 2 de `CLAUDE.md` |
| **Requisitos** | RN-04, RN-05, RN-09, RQ-01 |

> Los ADR-001 a ADR-020 provienen de la tabla del [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §4 y se redactan en la tarea 0.6. Este es el primero que nace de una decisión tomada al desarrollar el plan de implementación.

## Contexto

La regla dura 2 prohíbe `now()`, `time()`, `Carbon::now()` y `new DateTime()` en el dominio: la hora se inyecta mediante un puerto `Clock`. Sin esa inyección no se pueden probar los cambios de hora ni los turnos que cruzan medianoche, que son la mitad del riesgo de este dominio.

El [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §1.5 dibuja `Clock` como puerto de `Attendance/Application/Port/`, junto a `WorkDayRepository` y `EventPublisher`. Ese diagrama ilustra un módulo concreto, y al desarrollar el plan apareció la pregunta que no resuelve: **`Attendance` no es el único que necesita la hora.**

- `Compliance` la necesita para calcular la fecha de corte de la retención y para sellar las entradas de `audit_log`.
- `Kiosk` la necesita para calcular el desfase entre `occurred_at` y `recorded_at`.
- El scheduler la necesita en la detección de incidencias y en la reconciliación nocturna.
- `Reporting` la necesita para resolver periodos relativos.

Con el puerto declarado dentro de `Attendance`, los otros módulos tendrían que importarlo —lo que viola la frontera del §1.6, porque ningún módulo puede depender de otro salvo por caso de uso público o evento— o declarar cada uno su propia copia de una interfaz de un solo método.

## Decisión

**El puerto `Clock` se declara en el módulo `Shared`, en la capa de aplicación.**

```
backend/app/Modules/Shared/Application/Port/Clock.php        ← el puerto
backend/app/Modules/Shared/Infrastructure/Adapter/SystemClock.php   ← su implementación
```

Los demás módulos lo consumen desde ahí. En las pruebas se sustituye por un reloj fijo o controlable.

Tres razones lo sostienen:

1. **El §1.6 lo permite explícitamente.** Los ocho módulos declaran `Shared` como dependencia admitida, y `Shared` no depende de nadie. Deptrac queda en verde sin excepciones.
2. **`Shared` está definido como el sitio de los «contratos de eventos» y los «tipos base».** Una interfaz de un método sin implementación es precisamente eso: un contrato.
3. **La capa la decide quién consume la abstracción, no la pureza de la interfaz.** Es la corrección más importante de este ADR respecto a su primera redacción: que una interfaz no importe `Illuminate\*` la hace *admisible* en `Domain/`, no la sitúa ahí. Lo que la sitúa es que **el dominio recibe instantes en lugar de pedirlos** — un agregado nunca llama a `$clock->now()`, se lo pasan ya resuelto—, y quien lo llama es el caso de uso. El §1.5 dibuja todos los puertos en `Application`, y `Clock` no es la excepción.

> **Por qué no `Shared/Domain/Port/`, que fue la primera redacción.** Además de contradecir el §1.5, tenía tres consecuencias mecánicas: el árbol del §2 no contempla `Domain/Port/` en ningún módulo; la regla de Deptrac que prohíbe a `*/Domain` depender del `Domain` de otro módulo habría **tumbado el reloj el primer día de la Fase 1**, justo lo contrario de lo que este ADR afirmaba; y dejaba `SystemClock` sin sitio coherente. Colocarlo en `Application` resuelve las tres sin excepción alguna en `deptrac.yaml`.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Un `Clock` por módulo** | Cuatro o cinco interfaces idénticas de un método. Cuando una necesite cambiar —añadir una zona horaria, un `today()`— hay que recordar las cinco, y la que se olvide divergirá en silencio. Es el tipo de duplicación que `revisor-codigo` marca como su hallazgo más valioso |
| **`Clock` en `Attendance`, importado por los demás** | Rompe la frontera del §1.6 y hace fallar Deptrac. Y convierte al módulo núcleo en dependencia de todos, que es exactamente lo que la arquitectura modular evita |
| **Un servicio de aplicación en lugar de un puerto** | El dominio volvería a preguntar la hora en lugar de recibirla, que es lo que la regla dura 2 prohíbe |

## Consecuencias

- **`Shared` gana `Application/Port/` y `Infrastructure/Adapter/`.** Es el precedente para los demás puertos genuinamente transversales, y hay que vigilarlo: `Shared` no es el cajón de lo que no se sabe dónde poner. El criterio para entrar es que **más de un módulo lo necesite y no represente una regla de negocio de ninguno**. Un puerto que solo usa `Attendance` sigue viviendo en `Attendance`.
- **La regla de Deptrac no necesita excepción**, y esta vez es cierto: `Shared` ya es dependencia admitida de los ocho módulos, y la dependencia va de `Application` a `Application`, no de `Domain` al `Domain` de otro módulo.
- **`SystemClock` vive en `Shared/Infrastructure/Adapter/`, no en `Attendance`.** Es lo que impide que `Compliance` —que necesita la hora en la tarea 2.2 para sellar `audit_log` y calcular la fecha de corte de retención— acabe con una segunda implementación. La verificación de este ADR comprueba las dos cosas: que no haya dos declaraciones del puerto **ni dos de su adaptador**.
- **`WorkDayRepository`, `EventPublisher` y `CredentialResolver` no se mueven.** Son puertos de `Attendance`. El diagrama del §1.5 sigue siendo correcto para ellos.

> **Acotado por [ADR-025](ADR-025-frontera-de-dependencias-del-nucleo.md).** La redacción original de este punto incluía `CompliancePolicyProvider` entre los que no se mueven, con el argumento de que *«solo `Attendance` los usa»*. Era cierto para los tres que quedan y falso para ese: lo consumen también `Compliance` y `Reporting`, y su adaptador lee una tabla de `Product`. ADR-025 lo sube a `Shared/Application/Port/` junto con `OperationalSettingsProvider`, aplicando el mismo criterio de admisión que este ADR fijó. `CredentialResolver` sí se queda, aunque lo implemente `Identity`: quién implementa el adaptador no mueve el puerto.

- **El diagrama del §1.5 queda desactualizado en un detalle**: sitúa `Clock` dentro de `Attendance/Application/Port/`. Lo que cambia es el módulo, no la capa. La nota de corrección ya está en el §1.5 del documento 02.
- **El árbol del §2 del documento 02 se amplía** con `Shared/Application/Port/` y `Shared/Infrastructure/Adapter/`, que no figuraban.

## Verificación

- Prueba de arquitectura: ningún fichero de `Modules/*/Domain` importa `Illuminate\*` (Deptrac, ya existente).
- Prueba de arquitectura: **no existe más de una declaración del puerto `Clock` ni más de una implementación `SystemClock`** en el árbol de módulos. La segunda mitad es la que evita el modo de fallo real.
- Prueba de arquitectura: `Modules/*/Domain` no importa `Shared\Application\*`. El dominio recibe instantes; no conoce el puerto.
- Deptrac en verde **sin ninguna excepción declarada en `deptrac.yaml`**. Si hiciera falta una excepción, la ubicación sería la equivocada.
- Prueba unitaria de dominio: el cálculo de duración con reloj fijo en los dos cambios de hora de `Europe/Madrid`, en ambos sentidos, sin base de datos y por debajo de 2 segundos.
