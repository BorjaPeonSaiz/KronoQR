# ADR-002 — Arquitectura hexagonal en `Attendance` y `Compliance`

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 11 de agosto de 2026 (redactada el 14 de agosto de 2026, tarea 0.6) |
| **Decide** | `arquitecto-dominio` |
| **Afecta a** | Tareas 0.2, 1.1, 1.2, 1.4 y 2.2 · [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §1.5 y §2 · Reglas duras 1 y 2 de `CLAUDE.md` |
| **Requisitos** | RN-01..RN-09, RQ-01, RQ-02, RQ-10, RNF-M-01, RNF-M-03 |

> Procede de la primera tabla del [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §4, que ya fijaba decisión, contexto y consecuencias. Esta redacción los desarrolla y los enlaza con los requisitos y con las reglas duras; no cambia la decisión.

## Contexto

Las reglas del §4 del documento 01 no son validaciones de formulario: son el producto. Un turno que cruza la medianoche, un día de cambio de hora que dura 23 o 25 horas, un total diario que debe recalcularse y no acumularse, una duración mínima computable. Cada una de ellas, mal implementada, produce **un dato de nómina erróneo o un registro legalmente inválido**, y ninguna de las dos cosas se detecta mirando la pantalla.

Eso impone dos exigencias que la mayoría de los módulos de este sistema no tienen:

1. **Probarlas sin infraestructura.** RQ-01 pide una prueba unitaria por regla, sin base de datos ni framework, y RQ-02 pruebas basadas en propiedades sobre los días de cambio de hora. Una suite que necesita levantar PostgreSQL para comprobar aritmética de instantes se ejecuta en minutos, se ejecuta poco y acaba desactivada. `make test-unit` debe tardar menos de dos segundos.
2. **Sobrevivir al framework.** El producto se vende y se mantiene durante años en instalaciones de terceros. Las reglas del art. 34.9 ET durarán más que cualquier versión mayor de Laravel.

`Compliance` está en la misma situación por otro motivo: la cadena de hash de auditoría, la retención y la exportación legal son lógica con consecuencias jurídicas, no consultas.

El resto de los módulos —`Workforce`, `Kiosk`, `Product`, `Reporting`, `Identity`— son en buena medida CRUD, configuración y lectura. Aplicarles el hexágono completo produciría cinco capas alrededor de un alta de departamento.

## Decisión

**`Attendance` y `Compliance` se construyen con arquitectura hexagonal completa: `Domain/` puro, `Application/` con casos de uso y puertos, `Infrastructure/` con adaptadores. Los demás módulos usan una variante ligera.**

Tres consecuencias estructurales que forman parte de la decisión:

- **`Domain/` no importa nada del exterior.** Ni `Illuminate\*`, ni Eloquent, ni otro módulo, ni ninguna librería de infraestructura (regla dura 1). Lo que necesite del mundo se declara como puerto en `Application/Port/` y se implementa en `Infrastructure/Adapter/`.
- **El tiempo entra por un puerto.** Ninguna clase de dominio llama a `now()`, `time()`, `Carbon::now()` ni instancia fechas del sistema (regla dura 2, ADR-021). Sin esto, probar los dos cambios de hora de forma determinista es imposible.
- **Los umbrales entran resueltos.** El dominio recibe el descanso mínimo o la jornada máxima ya calculados por el perfil de cumplimiento; nunca consulta la configuración (regla dura 14, ADR-017).

La **variante ligera** de los demás módulos significa: controlador delgado, caso de uso explícito y modelo Eloquent, sin capa de dominio separada mientras no haya invariante que proteger. Cuando aparezca una, el módulo se gradúa; no antes.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Hexágono en los ocho módulos** | Ceremonia sin capacidad añadida en un CRUD de departamentos. La complejidad que no protege nada se paga igual, y además enseña al equipo que las capas son un trámite |
| **Sin hexágono en ninguno, lógica en servicios sobre Eloquent** | El cálculo de duración pasaría a depender de una conexión a base de datos, y con él las pruebas de RQ-01, RQ-02 y las de mutación de RQ-10. Es lo que descarta ADR-001 al rechazar N-capas, aplicado al núcleo |
| **Dominio anémico con la lógica en servicios de aplicación** | Las invariantes de RN-01, RN-02 y RN-06 dejan de tener un guardián: cualquier caso de uso puede tocar un `ShiftEntry` suelto. `WorkDay` existe precisamente para que eso no se pueda hacer |
| **Documentos de dominio compartidos entre módulos** | Convierte `Domain/` en dependencia cruzada y rompe la frontera del §1.6. Lo transversal vive en `Shared` con criterio de admisión explícito (ADR-021) |

## Consecuencias

- **Más ficheros y algo de mapeo entre dominio y Eloquent.** Es el precio, y es real: un fichaje toca agregado, repositorio, modelo y proyección. Se acepta porque el mapeo es mecánico y lo que protege no lo es.
- **Las pruebas del núcleo corren sin base de datos y en milisegundos**, lo que habilita las pruebas de mutación de RQ-10 con MSI ≥ 80 % y la cobertura ≥ 90 % de RNF-M-01. Una suite de dominio lenta hace inviables las dos.
- **El agregado `WorkDay` es la frontera transaccional del fichaje** (documento 01 §5.2). Ningún caso de uso puede saltárselo para tocar `ShiftEntry` directamente, y esa es la regla que hace que RN-01, RN-02, RN-06, RN-07 y RN-08 tengan un solo sitio donde vivir.
- **Los objetos de valor hacen imposible el estado imposible.** `WorkedDuration` no puede ser negativa porque su constructor lo rechaza; `TimeRange` no puede terminar antes de empezar. Es preferible a validar en cada uso y acordarse siempre.
- **Dos estilos conviven en el mismo repositorio.** Hay que decir por qué, o alguien «arreglará» la inconsistencia. Este ADR es esa explicación.

## Verificación

- Deptrac: `Modules/*/Domain` no depende de `Illuminate\*`, de Eloquent ni de otro módulo. La CI falla si alguien lo intenta.
- Prueba de arquitectura: `Modules/*/Domain` y `Modules/*/Application` no usan facades ni helpers de Laravel.
- Prueba de arquitectura: ninguna clase de `Domain/` llama a `now()`, `time()`, `Carbon::now()` ni `new DateTime()` (regla dura 2).
- `make test-unit` ejecuta la suite de dominio **sin base de datos y por debajo de 2 segundos**.
- `make mutate`: MSI ≥ 80 % sobre `Attendance/Domain` (RQ-10).
- Cobertura ≥ 90 % en la capa de dominio (RNF-M-01).
