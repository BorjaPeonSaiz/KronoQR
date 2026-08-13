# Fase 4 — Evolución

| Campo | Valor |
|---|---|
| **Fase** | 4 — Evolución |
| **Orden de ejecución** | 6.º y último (0 → 1 → 2 → 5 → 3 → **4**) |
| **Horas** | 60–90 h |
| **Condición de arranque** | **A decidir con datos de uso reales** |
| **Documento origen** | [docs/02](../docs/02-stack-tecnologico-y-plan-implementacion.md) §11 · [docs/05](../docs/05-presentacion-cliente.md) §11 |
| **Requisitos** | Ninguno codificado. El Anexo A del [documento 01](../docs/01-especificaciones-proyecto.md) no asigna códigos `RF-*` a esta fase |

---

## Por qué este fichero es corto

**No hay tareas que desarrollar, y no se inventan.** El documento 02 §11 dedica a la Fase 4 un único párrafo, sin tabla de tareas, sin horas por tarea, sin requisitos y sin agente asignado. El Anexo A del documento 01 la describe con cuatro conceptos, no con códigos de requisito. Es deliberado: el alcance de esta fase **se decide con datos de uso reales**, y detallarla hoy sería planificar sobre una plantilla en blanco.

Desarrollar aquí tareas paso a paso significaría inventarlas. Este fichero, por tanto, hace tres cosas y ninguna más: enumera las líneas de trabajo contempladas, registra qué se le anunció al cliente sobre cada una, y fija qué hay que decidir antes de convertir cualquiera de ellas en tareas ejecutables.

---

## Líneas de trabajo contempladas

Las cinco del documento 02 §11, contrastadas con lo que el documento 05 §11 anuncia al cliente:

| # | Línea de trabajo | Qué dice el doc 02 §11 | Qué se le ha dicho al cliente (doc 05) |
|---|---|---|---|
| 4.a | **Cuadrantes** y comparación entre planificado y realmente trabajado | Contemplada | §11: «Planificación de cuadrantes y comparación entre lo planificado y lo realmente trabajado». §8, tabla de expectativas: «Contemplado como evolución futura. **El modelo de datos deja la puerta abierta**» |
| 4.b | **Vacaciones y permisos con flujo de aprobación** | Contemplada | §11 la anuncia. §8 acota el presente con precisión: «Se registran las ausencias para no falsear los informes, **pero sin flujo de aprobación**» |
| 4.c | **Integración directa con sistemas de nómina concretos** | Contemplada | El doc 05 no la anuncia en §11. Lo que sí está comprometido y **es de la Fase 3** (tarea 3.9) es la *exportación configurable para nómina* (RF-IN-07) |
| 4.d | **Informes avanzados** | Contemplada | §11: «Informes avanzados» |
| 4.e | **Consolidación multi-centro para cadenas** | Contemplada | §11: «consolidación entre varios centros de una cadena», con la salvedad de que «el modelo de datos ya contempla varios centros desde el primer día, aunque se despliegue con uno solo» |

### Dos matices que conviene no perder

**4.a y 4.e llevan una promesa estructural, no funcional.** Al cliente no se le ha prometido la funcionalidad, pero sí que *el modelo de datos deja la puerta abierta*. Eso no es una tarea de la Fase 4: es una **restricción sobre las Fases 1 y 2**. El diseño del dominio (tarea 1.1) y el esquema (tarea 1.3) deben admitir varios centros y no cerrar la puerta a un cuadrante planificado, porque esa afirmación ya está escrita en un documento comercial. Si al llegar aquí hay que rehacer el esquema, la promesa era falsa.

**4.b tiene una frontera explícita que hoy se cumple y hay que seguir cumpliendo.** El registro de ausencias existe desde la tarea 3.10 (RF-GP-04), pero **sin flujo de aprobación**. Esa distinción está escrita en el documento 05 §8 y es lo que separa esta fase de lo ya entregado. Añadir un botón de "aprobar" en cualquier momento anterior desdibuja la frontera y convierte en incierto lo que hoy es una expectativa acotada.

---

## Qué hay que decidir antes de convertir esto en tareas

El documento 02 condiciona la fase a «datos de uso reales». Estos son los datos que la deciden, y todos salen de instrumentación que ya existe al llegar aquí:

| Pregunta a responder | Con qué dato se responde | De dónde sale |
|---|---|---|
| ¿Se usan los informes existentes lo suficiente como para que "avanzados" signifique algo? | Cuadro de impacto y adopción (RF-IN-08, tarea 3.13) | doc 02 §8.3 |
| ¿Cuánto esfuerzo manual consume hoy la salida a nómina? | Uso real de `GET /api/v1/reports/payroll-export` (tarea 3.9) | doc 01 Anexo B |
| ¿Cuántos clientes tienen de verdad más de un centro? | Configuración de instalación de la base instalada (RF-PD-01) | doc 02 §11.6 |
| ¿El registro de ausencias sin aprobación genera fricción real? | Correcciones con motivo `AJUSTE_ACORDADO_CON_RRHH` y `ALTA_RETROACTIVA`, más `manual_corrections_total{reason_code}` | doc 01 Anexo C · doc 02 §8.2 |
| ¿Qué pide de verdad el cliente frente a lo que suponemos? | La primera instalación en casa de un cliente. El doc 03 §7 lo pone entre lo que sigue necesitando una persona | doc 03 §7 |

---

## Cómo se convierte una línea en tareas ejecutables

Cuando se decida abordar cualquiera de las cinco, **no se improvisa**: se recorre el mismo camino que las fases anteriores, y el andamiaje ya existe para ello.

1. **Requisitos primero.** La línea se traduce a códigos `RF-*` / `RN-*` en el documento 01 §3 y §4, con criterios de aceptación en Gherkin (§11). Sin código de requisito no hay tarea: lo exige la Definición de Preparado del [doc 02](../docs/02-stack-tecnologico-y-plan-implementacion.md) §10.3.
2. **Anexo A actualizado.** El requisito nuevo se asigna a la Fase 4 en el Anexo A del documento 01, que es el alcance que consulta `qa:traceability --check` (doc 02 §9.6).
3. **Contrato antes que código.** Impacto en [docs/api/openapi.yaml](../docs/api/openapi.yaml) evaluado y, si aplica, actualizado. Es la fuente de verdad (ADR-013, regla dura del orden de autoridad).
4. **Diseño antes de implementación.** `arquitecto-dominio` decide módulo y capa. Los cuadrantes, en particular, son un concepto de dominio nuevo: no es obvio que vivan en `Attendance`, y esa decisión merece un ADR.
5. **Impacto legal y de privacidad evaluado**, con `/revision-cumplimiento`. Un flujo de aprobación de vacaciones introduce decisiones sobre personas: es tratamiento de datos con consecuencias.
6. **Tareas con la plantilla de este plan**, con su agente, sus horas y sus pruebas según la tabla del §9.5.
7. **Cierre de fase** como las demás (doc 03 §6.6).

---

## Reglas duras que esta fase no puede relajar

Ninguna de las 21 reglas de [CLAUDE.md](../CLAUDE.md) se suspende porque el trabajo sea "evolución". Tres son especialmente frágiles aquí:

- **Regla 13 — nada específico de un cliente en el código.** Esta fase es la que más presión va a recibir en contra: la integración con "un sistema de nómina concreto" (4.c) es exactamente la petición que tienta a meter un cliente en el repositorio. Sale por configuración o por un adaptador genérico, nunca por una rama.
- **Regla 5 — nada se borra ni se sobrescribe.** Un flujo de aprobación (4.b) tiene estados que cambian. Cada cambio es una versión nueva con autor, momento y motivo.
- **Regla 4 y ADR-006 — los turnos no se parten a medianoche.** La comparación entre planificado y trabajado (4.a) es un caldo de cultivo para prorrateos mal hechos. El prorrateo por día natural vive en `Reporting` y es explícito, nunca implícito.

---

## Aviso sobre las horas

Las **60–90 h** del documento 02 son una banda de referencia para cinco líneas de trabajo sin desglosar, no una estimación de tareas. Con el criterio del §11.0 —horas de una persona desarrollando con el andamiaje de agentes, revisión humana incluida—, y sin alcance definido, esa cifra sirve para reservar capacidad, no para comprometerse. Se recalibra cuando la fase tenga requisitos, y el §11.0 recomienda contrastar la estimación con los datos reales de la Fase 1, que es la primera oportunidad de medir.

---

## Estado de la fase en el resumen de esfuerzo

Del [doc 02](../docs/02-stack-tecnologico-y-plan-implementacion.md) §11.1:

| Alcance | Fases | Horas | ¿Vendible? |
|---|---|---|---|
| Producto vendible y operable | 0 + 1 + 2 + 5 + 3 | 420–554 | ✅ Con observabilidad completa |
| **Con evolución** | **Todas** | **480–644** | ✅ |

El producto **ya es vendible y operable sin esta fase**. Eso es lo que la convierte en evolución y no en alcance: es la única fase del plan cuyo recorte no aparece en la tabla de riesgos del §11.2, porque no hay riesgo en no hacerla todavía.

---

← Anterior: [Fase 3 — Operación y refuerzo](06-fase-3-operacion-y-refuerzo.md) · Siguiente: [Entrega, despliegue y actualización](08-entrega-despliegue-y-actualizacion.md) · [Índice](README.md)
