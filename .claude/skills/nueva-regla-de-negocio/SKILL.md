---
name: nueva-regla-de-negocio
description: Añade una regla de negocio nueva al dominio con su documentación, su prueba unitaria pura, su escenario Gherkin y su trazabilidad. Úsalo cuando aparezca una regla de cálculo de tiempo, de validación de fichajes, de detección de incidencias o de cumplimiento laboral que aún no esté en el documento 01 §4.
---

# Añadir una regla de negocio

En este sistema una regla de negocio determina las horas que cobra una persona. Una regla que solo vive en el código es una regla que nadie puede auditar, revisar ni defender ante una inspección.

**Por eso el orden es: documentar → probar → implementar.** No al revés.

## Paso 1 — Documentar la regla

Añade una fila a `docs/01-especificaciones-proyecto.md` §4, con el siguiente `RN-` disponible:

```
| RN-XX | Enunciado de la regla, en lenguaje de negocio, sin jerga técnica. |
```

El enunciado debe ser comprensible para alguien de RRHH. Si necesitas nombrar una clase para explicarlo, aún no has entendido la regla.

Si la regla proviene del Estatuto de los Trabajadores, del convenio de hostelería o de cualquier norma, **cítalo** y márcalo para validación por la asesoría laboral del cliente. Añade también una fila en §7 si genera una obligación legal nueva.

## Paso 2 — Escribir el escenario Gherkin

Añade el escenario a `docs/01-especificaciones-proyecto.md` §11, con datos concretos y verificables:

```gherkin
Escenario: <lo que describe la regla>
  Dado <estado inicial con valores reales>
  Cuando <acción con hora concreta>
  Entonces <resultado exacto, con el número esperado>
```

Nada de "el sistema calcula correctamente". Escribe el número.

## Paso 3 — Escribir la prueba unitaria que falla

`backend/tests/Unit/{Módulo}/` — prueba pura, sin base de datos, con el reloj inyectado y fijo.

**Ejecútala y comprueba que falla.** Una prueba que pasa antes de implementar no prueba nada.

Casos que debes cubrir siempre que la regla toque tiempo:
- El caso nominal
- El límite exacto (si la regla dice "más de 12 h": probar 11:59, 12:00 y 12:01)
- Turno que cruza medianoche
- Los dos cambios de hora de `Europe/Madrid`, si el cálculo abarca más de una hora
- Duración cero y duración negativa (que debe ser imposible de construir)

## Paso 4 — Implementar en el dominio

`Modules/{Módulo}/Domain/` — en el agregado si es una invariante, en una `Policy` si es una decisión parametrizable, en un objeto de valor si es una restricción del propio dato.

Prefiere que el tipo impida el estado inválido a que una validación lo detecte después.

**El enunciado va en español y el código en inglés** (documento 02 §3.5). La regla se lee `RN-16: se alerta si…` en el documento, y se llama `InsufficientRestPolicy` en el dominio. El glosario del 01 §13 es el puente; si la regla introduce un concepto nuevo del negocio, añádelo también ahí.

Si la regla tiene un umbral configurable, **nunca va como número literal en el código** y el dominio lo recibe ya resuelto por un puerto (regla dura 14). Dónde vive según su naturaleza:

- **Umbral legal** (descanso mínimo, jornada máxima, pausas, retención): campo del **perfil de cumplimiento** (`compliance_profiles`, RF-PD-07), servido por el puerto `CompliancePolicy`.
- **Umbral operativo** (segundos de anti-rebote, horas para considerar un turno anómalo, tolerancia de reloj): `installation_settings` con ámbito de instalación o de centro (RF-PD-01).
- La variable de entorno del **Anexo B** del documento 02 solo fija el **valor por defecto de la instalación**, no la fuente de verdad en ejecución.

## Paso 5 — Mutación

```bash
make mutate
```

MSI del dominio ≥ 80 %. Si la mutación sobrevive a un cambio en tu regla nueva, tu prueba no la cubre de verdad: refuérzala.

## Paso 6 — Propagar

- ¿Genera una incidencia nueva? Añade el tipo en `incidents.type` y en la bandeja del panel.
- ¿Requiere alerta? Métrica y regla en `infra/observability/`.
- ¿Cambia lo que ve el usuario? Textos en ES y EN.
- ¿Aplica a datos históricos? Decide y **documenta** si se aplica retroactivamente. En un registro legal, recalcular el pasado no es inocuo: puede alterar registros ya entregados. Por defecto, **no** se aplica retroactivamente sin decisión explícita.

## Lista de comprobación de entrega

- [ ] `RN-XX` documentada en el documento 01 §4 con enunciado comprensible
- [ ] Origen normativo citado, si lo hay, y marcado para validación legal
- [ ] Escenario Gherkin con números concretos
- [ ] Prueba unitaria que falló antes de implementar
- [ ] Límites exactos, medianoche y DST cubiertos
- [ ] Implementada en `Domain/`, no en un handler ni en un controlador
- [ ] Umbrales parametrizados en el perfil de cumplimiento o en `installation_settings`, con su valor por defecto documentado en el Anexo B del documento 02
- [ ] MSI ≥ 80 % tras `make mutate`
- [ ] Decisión sobre retroactividad tomada y documentada
