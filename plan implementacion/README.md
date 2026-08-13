# Plan de implementación de KronoQR — desarrollado tarea por tarea

| Campo | Valor |
|---|---|
| **Producto** | KronoQR — control de presencia y registro horario por QR, sector hotelero |
| **Qué es esto** | El plan del [documento 02](../docs/02-stack-tecnologico-y-plan-implementacion.md) §11 reordenado por orden real de ejecución y desarrollado paso a paso |
| **Documento origen** | [docs/02-stack-tecnologico-y-plan-implementacion.md](../docs/02-stack-tecnologico-y-plan-implementacion.md) |
| **Alcance total** | 58 tareas · 420–554 h para producto vendible y operable |
| **Estado del repositorio** | Solo documentación. La Fase 0 no ha empezado |

---

## Cómo se relaciona con los documentos que ya existen

**Este plan no sustituye a nada: deriva.** Todo su contenido sale de los cinco documentos de [docs/](../docs/) y del andamiaje de [.claude/](../.claude/). Lo que aporta es una cosa concreta que faltaba.

El documento 02 §11 tiene las ~50 tareas en **formato de tabla resumen**: una fila con nombre, horas, requisitos y agente. Eso basta para planificar y presupuestar. No basta para ejecutar, porque no dice en qué orden se dan los pasos dentro de una tarea, qué precondiciones tiene, qué pruebas exige en su caso concreto ni con qué comando se comprueba que está terminada. Esa información **sí existe**, pero repartida entre el §2 (rutas del árbol), §3.5 (convenciones), §9.4 y §9.5 (pruebas), §10.3 (Definición de Terminado), §11.3 (camino crítico), el Anexo A del documento 01 (requisitos por fase) y el documento 03 (agentes y skills). Este plan la reúne por tarea.

> **Si este plan y el documento 02 se contradicen, manda el documento 02.** Y arreglar la contradicción forma parte de la tarea, como establece el orden de autoridad de [CLAUDE.md](../CLAUDE.md).

### Orden de autoridad de los documentos

De [CLAUDE.md](../CLAUDE.md), sin cambios:

1. [docs/adr/](../docs/adr/) — una decisión arquitectónica solo se cambia con otro ADR.
2. [docs/api/openapi.yaml](../docs/api/openapi.yaml) — manda sobre la forma de cualquier endpoint.
3. [docs/01](../docs/01-especificaciones-proyecto.md) — manda sobre **qué** hace el producto (`RF-*`, `RN-*`, `RL-*`, `RS-*`).
4. [docs/02](../docs/02-stack-tecnologico-y-plan-implementacion.md) — manda sobre **cómo** se construye y en qué orden.
5. [docs/05](../docs/05-presentacion-cliente.md) — no manda, pero **obliga**: si promete algo que no existe como requisito, hay que resolverlo antes de seguir.

Este plan iría en un sexto escalón: es derivado y no manda sobre ninguno.

---

## Índice

| # | Fichero | Contenido | Tareas | Horas |
|---|---|---|---|---|
| 01 | [Herramientas y entorno](01-herramientas-y-entorno.md) | Puesta a punto de la máquina de desarrollo y dependencias del proyecto | — | — |
| 02 | [Fase 0 — Cimientos](02-fase-0-cimientos.md) | Repositorio, Compose, módulos, cadena de calidad, CI, ADRs | 7 | 31–42 |
| 03 | [Fase 1 — MVP de fichaje](03-fase-1-mvp-fichaje.md) | Dominio, esquema, credenciales, quiosco offline, portal, PIN | 13 | 102–130 |
| 04 | [Fase 2 — Gestión y cumplimiento](04-fase-2-gestion-y-cumplimiento.md) | 2FA, auditoría encadenada, correcciones, panel, informes, retención | 12 | 86–109 |
| 05 | [Fase 5 — Productización](05-fase-5-productizacion.md) | Configuración, perfiles, licencia, instalador, actualizador, marca, diagnóstico, documentación | 13 | 117–161 |
| 06 | [Fase 3 — Operación y refuerzo](06-fase-3-operacion-y-refuerzo.md) | Observabilidad, cuadros de mando, cumplimiento, carga, E2E, seguridad externa | 13 | 84–112 |
| 07 | [Fase 4 — Evolución](07-fase-4-evolucion.md) | Cuadrantes, aprobaciones, nómina, multi-centro. Sin desglosar por diseño | 0 | 60–90 |
| 08 | [Entrega, despliegue y actualización](08-entrega-despliegue-y-actualizacion.md) | Paquete de entrega, los 5 scripts, actualización entre versiones, runbooks | — | — |

**El prefijo del fichero es el orden de ejecución, no el número de fase.** De ahí que la Fase 5 sea el fichero `05` y la Fase 3 el `06`. El nombre lleva las dos cifras para que no haya ambigüedad.

En este mismo documento, además del índice: [orden de ejecución](#orden-de-ejecución-0--1--2--5--3--4), [camino crítico](#camino-crítico), [qué se sacrifica al recortar](#qué-se-sacrifica-al-recortar), [cómo se usa este plan](#cómo-se-usa-este-plan) y **[flujo de ejecución: ramas, commits y puertas](#flujo-de-ejecución-ramas-commits-y-puertas)**.

---

## Orden de ejecución: 0 → 1 → 2 → 5 → 3 → 4

No es el orden numérico, y la razón está en el documento 02 §11:

> La Fase 5 se numeró después pero se ejecuta antes que la 3, porque **un producto instalable con registro legalmente defendible ya es vendible** aunque la observabilidad avanzada llegue después.

| Orden | Fase | Horas | Qué se consigue al terminarla |
|:---:|---|---|---|
| 1.º | **0 — Cimientos** | 31–42 | `make up` levanta el entorno completo, la CI está en verde y las fronteras arquitectónicas —y la coherencia entre los documentos— se verifican solas |
| 2.º | **1 — MVP de fichaje** | 102–130 | Un empleado recibe su tarjeta y ficha en la tablet, con o sin red, con credencial infalsificable y registro correcto. **Corte MVP mínimo defendible** |
| 3.º | **2 — Gestión y cumplimiento** | 86–109 | Sistema **legalmente defendible** y operable por RRHH. Es aquí, y no antes, donde se puede poner en producción con tranquilidad |
| 4.º | **5 — Productización** | 117–161 | **El hito que convierte el proyecto en negocio.** Un tercero puede comprar, instalar y operar el producto |
| 5.º | **3 — Operación y refuerzo** | 84–112 | Observabilidad completa, cumplimiento visible, carga verificada, seguridad revisada por terceros |
| 6.º | **4 — Evolución** | 60–90 | A decidir con datos de uso reales |

### Resumen de esfuerzo por alcance

Del documento 02 §11.1:

| Alcance | Fases | Horas | ¿Vendible? |
|---|---|---|---|
| **MVP funcional** | 0 + 1 | 133–172 | ⚠️ Piloto interno controlado |
| **Primera instalación a medida** | 0 + 1 + 2 | 219–281 | ⚠️ Sí, pero instalada y operada por el equipo de desarrollo |
| **✅ Producto vendible** | 0 + 1 + 2 + 5 | **336–442** | ✅ **Sí: el cliente lo instala, configura y opera** |
| **Producto vendible y operable** | 0 + 1 + 2 + 5 + 3 | **420–554** | ✅ Con observabilidad completa |
| **Con evolución** | Todas | 480–644 | ✅ |

> **La Fase 5 es lo que separa "un sistema" de "un producto".** Sin ella se puede entregar una instalación, pero cada cliente nuevo consume tiempo del equipo de desarrollo. Con veinte clientes eso no escala, y el negocio deja de ser vender software para pasar a ser consultoría. Las ~110 h de la Fase 5 son la inversión que hace que el cliente número veintiuno cueste lo mismo que el segundo.

### Qué son estas horas

Del documento 02 §11.0, porque interpretarlas mal convierte la planificación en ficción:

- Son **horas de una persona desarrollando con el andamiaje de agentes** del documento 03, no de desarrollo manual.
- **Incluyen** diseño, implementación, las pruebas que exige el §9.5, documentación y —sobre todo— **la revisión humana de lo que produce el agente**, que es tiempo real y la parte que no se puede recortar.
- **No incluyen** aprender el dominio, esperar decisiones del cliente, ni las tres validaciones de la nota final: asesoría laboral, prueba de campo del hardware y contraste de costes de impresión.
- **La asistencia no acelera todo por igual.** Rinde mucho en trabajo mecánico y bastante menos donde manda el criterio: el diseño del dominio (1.1), la documentación de cliente (5.11) y todo lo que exige decidir qué es correcto para un negocio concreto.
- **La revisión no escala.** Si se acelera la producción de código sin acelerar la capacidad de revisarlo, el cuello de botella se desplaza a la persona, y el resultado es código que nadie ha leído en un sistema con valor probatorio.
- Para comparar con un presupuesto de desarrollo **manual sin asistencia**, el orden de magnitud es de **2,5 a 3 veces** estas horas. Conviene recalibrarlo con los datos reales de la Fase 1, que es la primera oportunidad de contrastar estimación contra realidad.

---

## Camino crítico

Verbatim del documento 02 §11.3:

```
0.1→0.2→0.3 ──► 1.1→1.2 (dominio; bloquea todo lo demás)
                  ├─► 1.3→1.4 ──► 1.7 ──► 1.8→1.9 (quiosco)
                  │                        └─► 1.12 (fichaje por PIN)
                  ├─► 1.5 (credenciales) ──► 1.10 (tarjetas y entrega)
                  ├─► 1.6 ──► 1.13 (provisión del PIN) ──► 1.11 (portal)
                  │                                   └─► 1.12
                  └─► 2.1→2.2 ──► 2.3 ──► 2.5
                                    └─► 2.8→2.9
                                          └─► 5.1→5.2 ──► 5.3
                                                └─► 5.4→5.5→5.7
```

**Una arista añadida sobre el §11.3:** la tarea **1.13** (provisión del PIN) se intercala entre la 1.6 y las dos que consumen el PIN. Sin ella, la 1.6 crea la columna `pin_hash`, la 1.11 hace login con ella y la 1.12 ficha con ella, y **nadie la rellena nunca**: el E2E del portal no es ejecutable.

**Dos ramas que deben avanzar en paralelo desde el principio:** el quiosco (1.8, 1.9) y la emisión de credenciales (1.5, 1.10). Un quiosco perfecto sin tarjetas que escanear no sirve de nada, y es un error de planificación fácil de cometer porque el quiosco es la parte visible.

**1.1 y 1.2 bloquean todo lo demás** y son las más fáciles de subestimar. No empezar la interfaz del quiosco hasta que el dominio esté cerrado y sus pruebas en verde: un cambio en las reglas de cálculo con el frontend construido cuesta el triple.

---

## Qué se sacrifica al recortar

Del documento 02 §11.2. Se reproduce aquí porque es la tabla que hay que tener delante cuando alguien pida acortar plazos:

| Si se recorta… | Riesgo que se asume |
|---|---|
| **Fase 2 completa** | **Incumplimiento legal.** Sin auditoría inmutable, retención y exportación para Inspección, el registro no satisface el art. 34.9 ET. **Es el recorte que no se debe hacer** |
| Solo la firma HMAC del QR (1.5) | Cualquiera puede fabricar la credencial de otro con un generador online. Se pierde la fiabilidad del registro completo, que es la razón de ser del sistema |
| Solo el modo offline (1.9) | Un corte de red en el cambio de turno deja a la plantilla sin poder fichar. En un hotel esto ocurre, y el registro en papel resultante contamina el sistema |
| Solo el PIN de respaldo (1.12) | Un empleado sin su tarjeta no puede fichar y su jornada acaba registrada a mano. Recorte de 4 h que genera correcciones manuales a diario |
| Solo el panel de estado de credenciales (parte de 1.10) | Nadie sabe quién no puede fichar todavía. El problema se descubre delante del quiosco a las 06:00 del primer día |
| **Fase 5 entera** | **No hay producto.** Cada cliente nuevo consume al equipo de desarrollo. Es el recorte que decide si esto es un negocio de software o una consultora |
| Solo la documentación de cliente (5.11) | Falso ahorro. Cada instalación se paga en horas de soporte para siempre |
| Solo el actualizador (5.7) | Actualizar veinte clientes a mano, cada uno en una versión distinta, con datos de nómina de por medio. Es el recorte con más probabilidad de acabar en pérdida de datos de un cliente |
| Fase 3 completa | Aceptable a corto plazo **si** se implementan como mínimo: sonda de salud, alerta de quiosco sin latido y alerta de copia fallida. Sin eso, los fallos los descubre RRHH a fin de mes. **Aviso:** las tareas 3.9 a 3.12 están comprometidas en el documento de presentación al cliente; recortarlas obliga a corregir antes ese documento, no a callarlo |

---

## Cómo se usa este plan

### Una tarea por sesión

Cada tarea de los ficheros 02 a 06 es **autocontenida**: qué hacer, quién lo hace, con qué skill, contra qué requisito, qué pruebas exige y cómo se verifica. Está diseñada así por lo que dice el documento 03 §1.1: el trabajo se reparte en muchas sesiones, y una tarea autocontenida sobrevive a empezar de cero un lunes por la mañana.

El procedimiento por tarea:

1. Comprobar las **precondiciones** de su cabecera. Si una tarea precedente no está cerrada, no se empieza.
2. Comprobar la **Definición de Preparado** del documento 02 §10.3: requisito identificado con su código y criterios en Gherkin, impacto en el contrato OpenAPI evaluado, impacto legal y de privacidad evaluado, sin dependencias bloqueantes abiertas.
3. Invocar el **agente** de la columna `Agente / Skill` y la **skill** si la hay. No improvisar quién la ejecuta: los ámbitos de los agentes se solapan a propósito y la columna es la que desempata.
4. Seguir los **Pasos** en orden. El orden no es decorativo: diseñar antes de implementar y probar antes de codificar es lo que evita descubrir una regla mal entendida con el frontend ya construido.
5. Ejecutar la **Verificación** de la tarea.
6. Recorrer el **Terminado cuando** antes de darla por cerrada.

### Los agentes por fase

Del documento 03 §2.2:

| Fase | Agentes protagonistas |
|---|---|
| **0 — Cimientos** | `devops-observabilidad` (entorno y CI), `arquitecto-dominio` (estructura de módulos y ADRs) |
| **1 — MVP de fichaje** | `arquitecto-dominio` → `qa-testing` → `backend-laravel`, con `frontend-quiosco` y `frontend-portal-empleado` en paralelo |
| **2 — Gestión y cumplimiento** | `backend-laravel` y `frontend-panel`, con revisión obligatoria de `seguridad-cumplimiento` en auditoría y rotación de claves |
| **5 — Productización** | `producto-licencia` como protagonista, con apoyo de `devops-observabilidad` y de los tres agentes de frontend para la marca blanca |
| **3 — Operación y refuerzo** | `devops-observabilidad` y `qa-testing` en instrumentación y pruebas; `backend-laravel` y `frontend-panel` en las tareas 3.9 a 3.12 |
| **Cierre de cada fase** | `revisor-codigo` y `seguridad-cumplimiento` |

Los diez agentes están en [.claude/agents/](../.claude/agents/) y las seis skills en [.claude/skills/](../.claude/skills/), y se invocan con `/nombre`. El detalle de qué hace cada uno está en el [documento 03](../docs/03-agentes-y-skills-ia.md).

### Cierre de fase

Al terminar cada fase, el procedimiento del documento 03 §6.6, en este orden:

1. **`seguridad-cumplimiento`** — revisa lo implementado contra STRIDE, RGPD y art. 34.9 ET. Informe por severidad.
2. **`revisor-codigo`** — revisión final buscando duplicación, complejidad innecesaria e incumplimientos de la Definición de Terminado.
3. **`qa-testing`** — verifica cobertura, MSI y que cada requisito de la fase (Anexo A del documento 01) tiene prueba que lo cubre.
4. **`devops-observabilidad`** — comprueba que lo nuevo está instrumentado y que cada alerta añadida tiene su runbook.

Entregable del cierre: hallazgos bloqueantes, requisitos de la fase sin cobertura de prueba, y qué queda pendiente para pasar a la siguiente.

---

## Flujo de ejecución: ramas, commits y puertas

### El modelo de ramas ya está decidido

Del documento 02 §10.5, y no es una preferencia abierta:

> Trunk-based con ramas cortas. Conventional Commits. SemVer con `CHANGELOG` generado.

Y del §10.1, que reparte las puertas de calidad:

| Cuándo | Etapas de la CI | Tiempo objetivo |
|---|---|---|
| **Cada push** | ① Lint + tipos · ② Arquitectura · ③ Unitarias + mutación · ③b Trazabilidad | Retroalimentación en **menos de 4 minutos** |
| **Cada Pull Request** | ④ Integración + Feature · ⑤ Seguridad · ⑥ Frontend · ⑦ E2E | ~12 min |
| **Antes de publicar versión** | ⑧ Instalación limpia + actualización desde versión anterior | ~4 min |

### Rama por incremento verificable, no por tarea a ciegas

**Esta es la única corrección que el plan hace al planteamiento intuitivo de "una rama por tarea".** Varias tareas son demasiado grandes para una rama corta:

| Tarea | Horas | Por qué no cabe en una rama |
|---|---|---|
| 1.1 — Dominio `Attendance` | 14–18 | Tres días de rama abierta contradicen el trunk-based del §10.5 |
| 5.3 — Licencia | 15–20 | Íd. |
| 5.7 — Actualizador | 15–20 | Íd. |
| 5.8 — Marca blanca | 12–18 | Toca tres frontends y los PDF: cuatro incrementos independientes |
| 1.8 — PWA quiosco | 12–16 | Escaneo, feedback, i18n y accesibilidad son revisables por separado |

**Criterio:** si una tarea supera aproximadamente una jornada de trabajo, se parte en varias ramas **por los pasos de su skill**, que ya están numerados para eso:

| Skill | Pasos | Corte natural de rama |
|---|---|---|
| `/crear-caso-de-uso` | 8 | Dominio y puertos → handler y adaptadores → contrato y HTTP → instrumentación y pruebas |
| `/nueva-regla-de-negocio` | 6 | Documentar y probar → implementar |
| `/migracion-segura` | 3 despliegues | Una rama por despliegue: expand → migrate → contract |
| `/endpoint-api` | 8 | Contrato → implementación → autorización y pruebas negativas |
| `/informe-nuevo` | 8 | Consulta y cálculo → endpoint y formatos → prueba de rendimiento |

Cada rama cierra con la suite en verde. Ninguna rama se abre sin que la anterior esté integrada.

### Puertas por rama frente a puertas por tarea

Se confunden con facilidad y no son lo mismo:

- **Por rama:** `make quality` y las etapas ① a ③b. Es la verificación de que lo que se integra no rompe nada.
- **Por tarea completa:** la **Definición de Terminado** del §10.3, que se recorre entera una sola vez, al cerrar la tarea. Instrumentación añadida, `audit_log` escrito, contrato OpenAPI actualizado, autorización probada en negativo, textos en español e inglés, accesibilidad verificada y `qa:traceability --check` en verde son juicios sobre **la funcionalidad completa**, no sobre un incremento parcial. Una rama que cierra con la suite en verde no significa que la tarea esté terminada.

### Convención de nombre de rama

⚠️ **No cubierto por los documentos — decidir.** Ni el documento 02 §10.5 ni ningún otro fija el formato del nombre de rama. La propuesta, coherente con lo que sí está escrito (Conventional Commits en §10.5, y el código se escribe en inglés según §3.5):

```
<tipo>/<tarea>-<descriptor-en-ingles>

feat/1.4-register-scan
feat/1.4-scan-idempotency
test/1.2-dst-transitions
refactor/2.3-correction-versioning
chore/0.3-phpstan-level-9
```

Se registra aquí como **propuesta explícita y no como supuesto heredado**: si se adopta, se adopta a sabiendas de que la decisión es nuestra y no del documento origen.

Los tipos de Conventional Commits se usan tal como el estándar los define. Recordatorio del §3.5, que sí es normativo: *«deja el fichero que tocas algo mejor que como estaba, pero **no mezcles refactor y funcionalidad en el mismo cambio**. Son dos revisiones distintas»* — lo que en la práctica significa que un `refactor/` y un `feat/` no comparten rama.

### Decisión: no se usa OpenSpec

**Fecha de la decisión:** 12 de agosto de 2026. **Estado:** cerrada.

Se evaluó crear cada tarea del plan como propuesta de OpenSpec y se descartó. Tres motivos:

**1. Introduciría una sexta fuente de verdad.** `CLAUDE.md` fija un orden de autoridad explícito: `docs/adr/` → `docs/api/openapi.yaml` → doc 01 → doc 02 → doc 05. Un `openspec/specs/` no tiene lugar en esa jerarquía, y la pregunta *«¿manda el spec de OpenSpec o manda RF-AT-06 del documento 01?»* se quedaría sin respuesta escrita. En un producto cuyo dato tiene valor probatorio legal, una especificación ambigua no es un problema de orden: es un riesgo.

**2. La capa de propuesta ya existe, y es más estricta.**

| Lo que aportaría OpenSpec | Lo que el proyecto ya tiene |
|---|---|
| Propuesta antes de codificar | **Definición de Preparado** (§10.3): requisito con su código, criterios de aceptación en Gherkin, impacto en el contrato OpenAPI evaluado e impacto legal y de privacidad evaluado, **antes** de entrar en la iteración |
| Especificación vigente frente a delta propuesto | Documento 01 como especificación vigente con 152 requisitos codificados, `docs/adr/` para las decisiones estructurales y `openapi.yaml` como contrato (ADR-013) |
| Flujo documentar → implementar | Skill `/nueva-regla-de-negocio`: documentar → probar → implementar, en ese orden, con decisión explícita sobre retroactividad |
| Validación de que el spec se cumple | `php artisan qa:traceability --check` **bloquea la CI** si un requisito implementado no tiene prueba que lo referencie (RQ-13, §9.6) |

La última fila es la diferencia de fondo: aquí la trazabilidad la verifica una máquina en cada push. Una propuesta la lee una persona. Sustituir lo primero por lo segundo sería un retroceso.

**3. El modelo de ramas no estaba en discusión.** Ya lo fija el §10.5.

**Qué haría falta para reabrir la decisión.** Un ADR que fije dónde encaja OpenSpec en el orden de autoridad de `CLAUDE.md` y quién manda ante una contradicción con el documento 01. Sin ese ADR el conflicto queda sin resolver, y ese conflicto sin resolver es precisamente el motivo del descarte. Si alguien quiere reabrirla, ese ADR es el trabajo, no una nota en una reunión.

---

## Convenciones de este plan

**Nada está inventado.** Cada afirmación deriva de un documento y cita su origen (`doc 02 §9.5`, `doc 01 RN-05`, `ADR-014`). Donde la plantilla de una tarea pedía un dato que ningún documento determina, aparece la marca **`⚠️ No cubierto por los documentos — decidir`** en lugar de un relleno a criterio del redactor. Esas marcas son trabajo pendiente de decisión humana, no descuidos.

**La plantilla de cada tarea** es idéntica en las cinco fases con desglose:

| Campo | Qué contiene y de dónde sale |
|---|---|
| Horas | Literal del documento 02 §11 |
| Agente / Skill | Literal de la columna del documento 02 §11 |
| Requisitos | De la columna del documento 02, contrastados con el Anexo A del documento 01 |
| Precondiciones / Bloquea a | Del camino crítico, §11.3 |
| Objetivo | Qué existe cuando la tarea termina |
| Reglas duras aplicables | Las de [CLAUDE.md](../CLAUDE.md) que la tarea puede romper, por número |
| Pasos | Orden derivado de la skill asignada (documento 03 §5 y su `SKILL.md`) o del método del agente |
| Artefactos | Rutas concretas del árbol del documento 02 §2 |
| Pruebas exigidas | Lo que marca la tabla del §9.5 para esa naturaleza de cambio, más los escenarios ineludibles del §9.4, con sus etiquetas de trazabilidad del §9.6 |
| Verificación | Comandos concretos y resultado esperado |
| Terminado cuando | Subconjunto aplicable de la Definición de Terminado del §10.3 |

**El nivel de prueba no lo decide quien implementa.** Lo decide la tabla del documento 02 §9.5 según la naturaleza de lo construido, y `php artisan qa:traceability --check` bloquea en CI si un requisito implementado no tiene prueba que lo referencie (RQ-13).

---

## Las 21 reglas duras, que gobiernan todas las tareas

Están en [CLAUDE.md](../CLAUDE.md) y se cargan solas en cada sesión. No se repiten aquí, pero cada tarea cita por número las que puede romper. La razón de que existan, en palabras del documento 03 §3: **las reglas que se pueden olvidar son las que se olvidan.** Que el dominio sea puro, que el reloj se inyecte, que nada se borre, que todo fichaje sea idempotente, que la credencial sea una tarjeta y que nada específico de un cliente entre en el código son invariantes del sistema, no recordatorios.

Las cinco que más veces aparecen en este plan:

| # | Regla | Por qué reaparece tanto |
|---|---|---|
| 1 | `Domain/` es puro | Es la frontera que Deptrac verifica y la que se erosiona sin darse cuenta |
| 2 | Nunca `now()` en el dominio: se inyecta `Clock` | Sin esto no se puede probar DST ni medianoche, que es la mitad del riesgo del dominio |
| 5 | Nada se borra ni se sobrescribe | Es lo que da valor probatorio al registro (RN-13, RL-04) |
| 8 | Todo fichaje es idempotente por `scan_id` | Es lo que hace posible el modo offline sin duplicar jornadas |
| 13 | Nada específico de un cliente en el código | Es lo que hace que el cliente veintiuno cueste lo mismo que el segundo (ADR-017) |

---

## Antes de la primera venta

Dos verificaciones quedan fuera de lo que este plan puede resolver, y están en la Nota final del documento 02:

1. **Validación jurídica.** La sección legal del documento 01 recoge requisitos de producto derivados del marco normativo, **no asesoramiento jurídico**. Debe validarla una asesoría laboral, junto con el contrato de licencia y el contrato de encargo acotado a soporte.
2. **Vigilancia normativa.** Existe una corriente regulatoria hacia el registro digital, interoperable y con acceso remoto para la Inspección. La arquitectura lo cubre por diseño, pero debe designarse un responsable de seguimiento antes de cada versión mayor.

A las que se suman, del §11.0, las que no están incluidas en las horas: la prueba de campo del hardware —incluida la **prueba de resistencia de 12 h** de la PWA en el dispositivo real que exige el Anexo A— y el contraste de costes de impresión de las tarjetas.

---

## Apéndice — Decisiones pendientes

Al desarrollar las 58 tareas apareció información que **ningún documento del proyecto determinaba**. La instrucción era no inventarla, así que quedó marcada en su sitio con `⚠️ No cubierto por los documentos — decidir`. Este apéndice las reúne todas.

**No eran descuidos del plan: eran trabajo de decisión humana pendiente.** Lo que sí habría sido un descuido es que estuvieran resueltas a criterio de quien redactó, sin que nadie lo supiera.

### Estado: las 22 bloqueantes están resueltas

| | |
|---|---|
| **Resueltas** | **22 de 22.** Tres no eran problemas sino lecturas incompletas del requisito, y se documentan como tales |
| **Con visto bueno externo pendiente** | 1 de las 4 originales — las otras tres (1-3, 3-6, 5-4) se cerraron el 13 de agosto de 2026 como decisión de producto, no como aprobación externa formal; queda solo `LICENCIA.txt`, que sí requiere asesoría laboral cualificada |
| **Abiertas y no bloqueantes** | El resto (🟡 y 🟢): se deciden al ejecutar su tarea, que es para lo que están clasificadas así |

**Dónde vive cada decisión resuelta.** No en este apéndice: en el documento que manda según el orden de autoridad. Aquí solo se retiró la marca.

| Documento | Qué recogió |
|---|---|
| [`docs/adr/`](../docs/adr/) | **ADR-021** (`Clock` en `Shared`), **ADR-022** (sin instalador de Windows), **ADR-023** (frontera registro legal / accesorio), **ADR-024** (la pausa son dos tramos), **[ADR-025](../docs/adr/ADR-025-frontera-de-dependencias-del-nucleo.md)** (el núcleo declara sus puertos; los satélites los implementan), **[ADR-026](../docs/adr/ADR-026-la-correccion-supersede.md)** (la corrección supersede), **[ADR-027](../docs/adr/ADR-027-audit-log-particionado.md)** (`audit_log` particionado con anclas de cadena), **[ADR-028](../docs/adr/ADR-028-limites-del-plan-no-bloquean.md)** (los límites del plan nunca bloquean el alta) |
| [`docs/01`](../docs/01-especificaciones-proyecto.md) | **RN-16** (secuencia imposible) · Anexo B: endpoints nuevos y notas de contrato · §9.3: destinatario y runbook de las once alertas · Anexo A: RF-GP-05 pasa a Fase 5 · §5.5: `superseded` en `shift_entries`, `audit_log` particionado y `audit_chain_anchors` |
| [`docs/02`](../docs/02-stack-tecnologico-y-plan-implementacion.md) | §4: los **ocho** ADR nuevos · §11: RF-GP-05 de 3.10 a 5.5, tareas **1.13** y **5.11b** nuevas y horas ajustadas · §3.2: predicado `NOT IN ('voided','superseded')` · §7.1: dos zonas de rate limiting de fichaje · Anexo B: escalones del PIN, `ATTENDANCE_MIN_TRANSIT_SECONDS` y `KIOSK_VLAN_CIDR` · §11.6.1: sin `install.ps1` · §12: tres runbooks nuevos |

**Mover RF-GP-05 no cambia el total:** resta 3–4 h a la Fase 3 y las suma a la Fase 5.

**Lo que sí cambia el total son las cuatro ampliaciones** que salieron de la revisión de coherencia: la tarea **1.13** (provisión del PIN, `RF-ID-09`, que no construía nadie), la **5.11b** (documentación de usuario), la reestimación de la **3.5** a 8–10 h por ADR-024, y la ampliación de la **0.7** con `docs/requisitos.yaml` y `docs:consistency`. El total pasa de **402–530 h** a **420–554 h**, y la fila «Producto vendible» del §11.1, a **336–442 h**.

### Cómo leer la clasificación

| Marca | Significado |
|:---:|---|
| ✅ | **Resuelta.** La decisión vive en el documento que manda; aquí queda el enlace |
| ⚠️ | **Resuelta en lo técnico, pendiente de visto bueno externo.** El mecanismo está; el contenido lo aprueba alguien de fuera del equipo |
| 🟡 | **Se decide al ejecutar.** Ubicación de un fichero, nombre de una métrica, reparto de trabajo entre dos tareas. Conviene fijarlo, pero no impide empezar |
| 🟢 | **Menor.** Organizativo o cosmético |

Las que estaban marcadas 🔴 aparecen ahora como ✅ o ⚠️, con la decisión en la misma fila.

---

### Antes de la Fase 0 — [01-herramientas-y-entorno](01-herramientas-y-entorno.md)

| # | Qué hay que decidir | |
|:---:|---|:---:|
| E-1 | **PHP 8.4 en el host o solo en el contenedor.** Planteada como disyuntiva en §B.5, sin resolver: el contenedor lleva 8.4 con todas las extensiones, el host tiene 8.3.31 sin `pdo_pgsql`, `pgsql`, `redis` ni `sodium` | 🟡 |
| E-2 | **`REVERB_APP_ID` / `_KEY` / `_SECRET` no figuran en la lista de rotación de secretos del §7.7**, pese a ser credenciales. O se añaden al runbook `rotacion-secretos.md`, o se documenta por qué no rotan | 🟡 |

### Fase 0 — [02-fase-0-cimientos](02-fase-0-cimientos.md)

| # | Tarea | Qué hay que decidir | |
|:---:|:---:|---|:---:|
| 0-1 | 0.2 | ✅ **`Shared/Application/Port/Clock.php`** ([ADR-021](../docs/adr/ADR-021-clock-en-shared.md)). **En `Application`, no en `Domain`**: el ADR rechaza `Domain/Port/` explícitamente, porque el dominio recibe instantes y no los pide, y porque habría chocado con la regla de Deptrac que prohíbe a `*/Domain` depender del `Domain` de otro módulo — es decir, habría tumbado el reloj el primer día. `Compliance`, `Kiosk`, `Reporting` y el scheduler lo necesitan igual que `Attendance`; el §1.6 admite `Shared` como dependencia de los ocho módulos, así que Deptrac queda en verde sin excepciones. Con criterio explícito de admisión para que `Shared` no acabe siendo un cajón de sastre | ✅ |
| 0-2 | 0.3 | ✅ **Pest Arch + Semgrep, no Deptrac.** Pest Arch prohíbe en `Modules/*/Domain` las funciones `now`, `time`, `date`, `mktime`, `microtime` y `strtotime` y la clase `Carbon\Carbon`; Semgrep detecta `new DateTime()` y `new DateTimeImmutable()` **sin argumentos o con `'now'`**, que es la forma que ninguna regla de *imports* encuentra porque la clase se importa legítimamente para tipar | ✅ |
| 0-3 | 0.6 | **Herramienta de validación del `openapi.yaml`.** El §3.1 solo fija `spectator` para las pruebas, no un validador del contrato en sí | 🟡 |
| 0-4 | 0.7 | **En qué módulo viven los comandos `qa:traceability` y `docs:consistency`.** El Anexo C los agruparía bajo «Calidad y trazabilidad» sin asignarles módulo | 🟡 |
| 0-9 | 0.7 | **Valor y gobierno de `CURRENT_PHASE`.** Variable nueva del Anexo B: es lo que fija el alcance del bloqueo de `qa:traceability --check`. Se propone actualizarla como parte del procedimiento de cierre de cada fase | 🟡 |
| 0-5 | 0.1 | **Ubicación del `Makefile`.** El árbol del §2 no lo incluye, aunque `CLAUDE.md` exige sus seis comandos | 🟡 |
| 0-6 | 0.1 | **`.gitattributes`** — no está en el árbol del §2. Propuesto en [01 §B.0](01-herramientas-y-entorno.md) porque sin él los `.sh` con CRLF no arrancan en el servidor Linux del cliente | 🟡 |
| 0-7 | 0.6 | **Cuáles de los 20 runbooks del §12 se escriben en la Fase 0** y cuáles esperan a la fase que introduce su alerta | 🟡 |
| 0-8 | 0.3 | **`backend/pint.json`** no aparece en el árbol del §2, aunque el §3.5 exige el preset `laravel` | 🟢 |

### Fase 1 — [03-fase-1-mvp-fichaje](03-fase-1-mvp-fichaje.md)

| # | Tarea | Qué hay que decidir | |
|:---:|:---:|---|:---:|
| 1-1 | 1.3 | ✅ **Todo el esquema de la Fase 1 se crea en 1.3**, ordenado por dependencia de claves foráneas. Partirlo obligaría a migraciones que se referencian hacia adelante, porque `shift_entries.employee_id` referencia `employees` y su módulo es de la tarea 1.6. **Las tareas 1.5 y 1.6 no crean migraciones**: consumen las de 1.3 | ✅ |
| 1-2 | 1.11 | ✅ **CSV en 1.11, PDF en 2.9**, por `?format=`. CSV cubre la portabilidad del RGPD sin arrastrar Browsershot al camino crítico; el PDF —que es lo que una persona presenta— llega con la maquinaria de exportación. **Sin XLSX**: no aporta nada sobre CSV para un histórico personal. En las notas de contrato del Anexo B del doc 01 | ✅ |
| 1-3 | 1.12 | ✅ **Escalado geométrico, ya parametrizado y confirmado**: 3 fallos → 5 min, 5 → 15 min, 10 → 60 min, contador que se reinicia sin fallos en 24 h (Anexo B del doc 02). Cada escalón triplica el anterior, lo que hace inviable barrer 10⁶ sin castigar a quien se equivoca una vez. **Minutos confirmados como decisión de producto** el 13 de agosto de 2026: equilibran seguridad y no dejar a nadie sin fichar delante del quiosco; ajustables si la operación real de un cliente lo pide | ✅ |
| 1-4 | 1.1 | ✅ **Misma decisión que 0-1** ([ADR-021](../docs/adr/ADR-021-clock-en-shared.md)). Y en la misma nota se resolvió una tensión de orden que no estaba en la lista: `CompliancePolicyProvider` y la tabla `compliance_profiles` con su semilla `ES-hosteleria` se adelantan a las tareas 1.1 y 1.3, para que la regla dura 14 no se incumpla durante las Fases 1 y 2 | ✅ |
| 1-5 | 1.2 | **Dónde viven las *factories* de dominio puro**, dado que las de Eloquent pertenecen a `Infrastructure/Persistence/` y no existen hasta 1.3 | 🟡 |
| 1-6 | 1.11 | **Qué módulo sirve `GET /api/v1/me/workdays`:** `Reporting` (el §1.6 le asigna las consultas de lectura) o `Attendance/Application/Query/` | 🟡 |
| 1-7 | 1.10 | **Ruta de las plantillas Blade de los PDF de tarjeta.** El árbol del §2 no las ubica | 🟡 |

### Fase 2 — [04-fase-2-gestion-y-cumplimiento](04-fase-2-gestion-y-cumplimiento.md)

| # | Tarea | Qué hay que decidir | |
|:---:|:---:|---|:---:|
| 2-1 | 2.6 | ✅ **Se adelanta la tabla, no la funcionalidad.** `compliance_profiles` y su semilla `ES-hosteleria` se crean en la tarea **1.3**; el puerto, en la **1.1**. Los umbrales de la semilla son los que RN-10/11/12 ya fijan (12 h, 9 h, 6 h), así que no se inventa nada. Las tareas 5.1 y 5.2 conservan lo caro: edición, cascada y auditoría del cambio. **~2 h adelantadas, cero rehacer**, y ningún adaptador desechable con literales dentro | ✅ |
| 2-2 | 2.10 | ✅ **Misma resolución que 2-1**, con los 4 años de RL-02 en la semilla. `COMPLIANCE_PROFILE` del Anexo B sigue siendo solo el valor por defecto de la instalación, no la fuente de verdad en ejecución | ✅ |
| 2-3 | 2.6 | ✅ **`turno-abierto-prolongado.md`, escrito en la tarea 2.6**, que es la que crea la alerta. Añadido al §12 del doc 02. Destinatario **RRHH** y no IT: no es una avería, es trabajo de gestión sobre el registro. El runbook deja claro lo que RN-08 impone — **el sistema nunca cierra el turno por su cuenta** — y remite a la corrección trazada de 2.3 con motivo `OLVIDO_FICHAJE_SALIDA` | ✅ |
| 2-4 | 2.12 | ✅ **No hay endpoint, y es deliberado.** Rotar la clave no es una acción de panel: es un acto operativo con semanas de reimpresión detrás (§5.3), y un botón que lo dispare invita a pulsarlo. Se queda en `credentials:rotate-key`. El panel solo lee: `GET /api/v1/credentials/status` admite ahora **`?key_id=`** para ver a quién le falta reimprimir, que es lo que permite retirar la clave anterior con seguridad | ✅ |
| 2-5 | 2.4, 2.6, 2.7, 2.10, 2.11, 2.12 | **Posición dentro de la fase de seis tareas que no figuran en el camino crítico del §11.3.** Sus precondiciones están derivadas y marcadas en cada ficha, pero el orden entre ellas es decisión abierta | 🟡 |
| 2-6 | 2.5 | **Con qué herramienta se mide el LCP del panel en CI** (RNF-P-04: 500 empleados por debajo de 1,5 s). El §9.2 solo fija k6 para carga de API y `@axe-core/playwright` para accesibilidad | 🟡 |
| 2-7 | 2.5 | **Si 2.6 precede a 2.5** o se integran en paralelo con datos de semilla: la bandeja de incidencias necesita incidencias que las produce 2.6 | 🟡 |
| 2-8 | 2.7 | **Destinatario de la alerta de divergencia en reconciliación.** El §9.3 fija severidad crítica pero no a quién avisa | 🟡 |
| 2-9 | 2.8 | **Cuánto de la pantalla de informes entra en 2.8** y cuánto en 2.5 o 3.13. El §11 asigna la tarea a `backend-laravel`, lo que sugiere que la pantalla no es suya | 🟡 |
| 2-10 | 2.10 | **Si la documentación de cliente sobre conservación se redacta aquí** o se acumula a la tarea 5.11, que es la dueña de esa documentación | 🟡 |
| 2-11 | 2.10 | **Si el cálculo de la fecha de corte de retención se documenta como una `RN-*` nueva** en el documento 01 | 🟡 |
| 2-12 | 2.11 | **Si la restauración de una copia escribe en `audit_log`.** El bloque D de `/revision-cumplimiento` no la lista entre las acciones auditables | 🟡 |
| 2-13 | 2.11 | **Nombre de la métrica de respaldo.** El §8.2 no incluye ninguna, pese a que su último bloque se titula «Credenciales y respaldo» | 🟡 |
| 2-14 | 2.11 | **Si el simulacro de restauración vive en `infra/scripts/`** o como *workflow* de CI | 🟢 |

### Fase 5 — [05-fase-5-productizacion](05-fase-5-productizacion.md)

Este fichero **ya consolida sus 15 decisiones** en su propia tabla final, con el número de tarea afectada. Las cuatro que bloquean:

| # | Tarea | Qué hay que decidir | |
|:---:|:---:|---|:---:|
| 5-1 | 5.5, 5.11 | ✅ **C-1: RF-GP-05 se mueve a la tarea 5.5.** Un asistente que obliga a teclear a mano la plantilla de un hotel no es un producto instalable, que es el criterio con el que se juzga esta fase. **El documento comercial no se corrige porque decía la verdad**: era el plan el que tenía el requisito en la fase equivocada. 3–4 h que cambian de fase, no que se suman | ✅ |
| 5-2 | 5.4, 5.11 | ✅ **C-2: `install.ps1` se retira** ([ADR-022](../docs/adr/ADR-022-sin-instalador-de-windows.md)). Un entregable que ninguna herramienta revisa y ninguna etapa de CI prueba es peor que no tenerlo. Con ello ShellCheck y `shfmt` cubren el 100 % de los scripts del paquete. Un cliente solo-Windows instala sobre máquina virtual Linux, **y se le dice antes de empezar** | ✅ |
| 5-3 | 5.6 | ✅ **C-3 no era una contradicción: faltaba escribir el flujo.** `/kiosk/pair` es *la tablet pide emparejarse y recibe el código que muestra*; el administrador lo teclea en el panel, que llama a `/kiosk/pair/confirm` y emite el token. `kiosk:pairing-code {site}` queda como vía alternativa de consola, coherente con el «no tiene por qué usar SSH» de RF-PD-06. **Los tres documentos intactos** | ✅ |
| 5-4 | 5.3 | ⚠️ **Lista enumerada en [ADR-023](../docs/adr/ADR-023-frontera-registro-legal-y-funcionalidad-accesoria.md)**, con dos reglas que se implementan: lo no clasificado es **no degradable** por defecto, y el conjunto legal **no es licenciable** —no aparece en `features`, así que no existe forma de expresar su desactivación—. **La lista es contractual y necesita visto bueno comercial**: es lo que se le puede decir a un cliente que perderá | ⚠️ |

Las otras once —valores del perfil `ES-hosteleria`, retroactividad al cambiar un umbral, avisos de caducidad, códigos de salida, imputación de `backup.sh`, punto de control del actualizador, `scope` de `support_grants`, formato del paquete de diagnóstico, telemetría, soporte de versiones y precedencia entre `.env` y `installation_settings`— están en [su tabla](05-fase-5-productizacion.md).

### Fase 3 — [06-fase-3-operacion-y-refuerzo](06-fase-3-operacion-y-refuerzo.md)

| # | Tarea | Qué hay que decidir | |
|:---:|:---:|---|:---:|
| 3-1 | 3.6 | ✅ **El límite por IP no aplica a la VLAN de quioscos, y ningún requisito cambia.** El §7.1 ya dice que la aplicación limita «por `device_id`, por credencial y por empleado»: ahí está el control real. En Nginx, la zona de fichaje excluye el CIDR interno; los 30 r/m por IP quedan para orígenes no internos, que es el tráfico para el que ese control se pensó. **Se estaba aplicando a la red del hotel un control pensado para internet.** La VLAN debe declararse en la instalación, o el síntoma será «el quiosco va lento a las 06:00» | ✅ |
| 3-2 | 3.2 | ✅ **Matriz de destinatarios en el §9.3 del doc 01, y los tres runbooks añadidos al §12.** Crítica (seguridad) → responsable de seguridad, porque es un incidente y exige preservar evidencia antes que restablecer; Crítica (operaciones) y Alta → IT del cliente, el único que puede actuar sobre servidor, red o dispositivos; Media → RRHH. **El fabricante no es destinatario de ninguna**: no tiene acceso a la instalación (ADR-020) | ✅ |
| 3-3 | 3.4 | ✅ **`GET /api/v1/compliance/summary`**, con filtros de periodo y ámbito, rol `manager+`. Añadido al Anexo B del doc 01 y al contrato antes de escribir código (ADR-013) | ✅ |
| 3-4 | 3.5 | ✅ **La pausa son dos tramos** ([ADR-024](../docs/adr/ADR-024-la-pausa-son-dos-tramos.md)), no un intervalo interno. **Cero conceptos nuevos y ninguna columna nueva**: el modelo ya lo soportaba —RN-12 enuncia la regla sobre *tramos continuos* y «jornada partida de 4 tramos» ya era escenario obligatorio—. Un intervalo interno obligaría a revisar RN-01, RN-02 y la restricción `EXCLUDE USING gist`, que no sabe de huecos internos: se toca la última línea de defensa para no ganar nada | ✅ |
| 3-5 | 3.5 | ✅ **Se amplía el enum `action`** de `/scan` a `clock_in`, `clock_out`, `break_start`, `break_end`, y `scan_events.result` lo refleja. **Aditivo, no rompe la v1** (ADR-012): un cliente antiguo trata `break_start` como valor desconocido, nunca como error | ✅ |
| 3-6 | 3.11 | ✅ **Definida como `RN-16`** en el doc 01 §4: dos escaneos de la misma credencial en **dispositivos distintos** separados por menos del tiempo mínimo de tránsito, con umbral en `ATTENDANCE_MIN_TRANSIT_SECONDS`. Genera incidencia, **nunca anula un fichaje ni concluye fraude**. ✅ **120 s confirmado como valor de serie, decisión de producto** (no una medición de un hotel real): configuración por instalación, ajustable a la distancia de cada cliente | ✅ |
| 3-7 | 3.12 | ✅ **No lo detecta: la ventana es configuración.** RF-KI-07 dice literalmente «ventana de actualización **configurable**», así que se declara por centro y no se infiere. **El problema estaba mal planteado** por leer solo la primera mitad del requisito. Se añaden dos guardas locales más fiables que adivinar el horario: no actualizar si la cola offline no está vacía, ni si hubo un escaneo en los últimos N minutos | ✅ |
| 3-8 | 3.13 | ✅ **Uno se declara, el otro se lee del quiosco.** «Horas/mes consolidando hojas de horas» mide el proceso *anterior* al sistema, que ninguna métrica puede observar: se captura como dato que el cliente declara en el asistente (5.5) y el cuadro muestra la variación. «Disponibilidad del flujo de fichaje» **no es *uptime* de la API** — el §1.3 aclara «(incluye modo offline)» —: son fichajes confirmados sobre intentados, según la telemetría del quiosco. Medirlo como *uptime* contaría como caída justo el escenario que el ADR-008 resuelve | ✅ |
| 3-9 | 3.11 | **Si la detección de patrones genera alerta y con qué destinatario.** El §9.3 no la incluye | 🟡 |
| 3-10 | 3.12 | **Contenido exacto del resumen semanal.** RF-PR-05 dice «resumen semanal por correo al responsable» sin especificar qué lleva | 🟡 |
| 3-11 | 3.12 | **Módulo del resumen semanal:** `Reporting` o `Compliance`. Encaja en ambos según el §1.6 | 🟡 |
| 3-12 | 3.1 | **Ubicación de la instrumentación transversal.** El árbol del §2 no asigna carpeta a métricas y contexto de log | 🟡 |
| 3-13 | 3.2 | **Herramienta de validación de reglas de alerta y cuadros de mando.** El §9.2 no incluye ninguna para Prometheus ni Grafana | 🟡 |
| 3-14 | 3.8 | **Dónde vive el informe de revisión de seguridad.** Ni el §2 ni el §12 lo ubican. Nunca en el paquete que se entrega al cliente si contiene detalles explotables | 🟡 |
| 3-15 | 3.4 | **Nombre de la carpeta de la vista de cumplimiento** en `frontend-admin`. El §2 lista ocho *features* y ninguna es de cumplimiento | 🟢 |
| 3-16 | 3.2 | **Si 3.3 espera al cuadro de operación de quioscos** o va en paralelo | 🟢 |
| 3-17 | 3.6 | **Si la prueba de carga va en `release.yml`** o en un *workflow* propio | 🟢 |

### Entrega al cliente — [08-entrega-despliegue-y-actualizacion](08-entrega-despliegue-y-actualizacion.md)

Este fichero **ya consolida sus 7 decisiones** en su tabla final. Cinco coinciden con las de la Fase 5 vistas desde el lado de la entrega (`install.ps1`, códigos de salida, imputación de `backup.sh`, punto de control del actualizador, formato del paquete de diagnóstico). Las dos propias:

| # | Qué hay que decidir | |
|:---:|---|:---:|
| 8-1 | ⚠️ **Marcador de posición que hace fallar la publicación.** La etapa 8 de la CI comprueba que el paquete no lleva el marcador; si lo lleva, no se publica. Es el mismo criterio que el §3.5 aplica a los secretos: lo que no comprueba una herramienta es una sugerencia. ⚠️ **El texto lo redacta o valida una asesoría laboral** — no es decisión técnica, y la Nota final del doc 02 ya lo sitúa fuera | ⚠️ |
| 8-2 | **Duración temporal del soporte de una versión menor** y canal por el que se anuncia la ventana de migración a un cliente sin salida a internet. El §11.6.5 fija una política por número de versiones, no por tiempo, y sin cadencia declarada el cliente no puede planificar | 🟡 |

### Transversal

| # | Qué hay que decidir | |
|:---:|---|:---:|
| T-1 | **Convención de nombre de rama.** Propuesta en [Flujo de ejecución](#flujo-de-ejecución-ramas-commits-y-puertas) como `feat/1.4-register-scan`, marcada explícitamente como propuesta y no como supuesto heredado | 🟡 |

---

### Qué queda pendiente de alguien de fuera del equipo

**Tres de las cuatro se cerraron el 13 de agosto de 2026** como decisión de producto (5-4, 1-3, 3-6): tenían mecanismo construido y valor por defecto razonable, y el responsable de producto confirmó ese valor como definitivo — sustituible, no por un defecto técnico, sino si más adelante hay una medición real, una oferta comercial concreta o datos de un cliente que aconsejen otro número. Queda una:

| # | Qué falta | Quién lo cierra |
|:---:|---|---|
| 8-1 | El texto de `LICENCIA.txt` | Asesoría laboral, junto con el contrato de encargo de soporte — es texto con efectos legales y no se resuelve como decisión de producto |

### Tres que se disolvieron en lugar de resolverse

Merecen mención porque son el patrón más útil que salió del ejercicio: **antes de decidir, releer el requisito completo.**

- **3-7** — RF-KI-07 ya decía «ventana de actualización **configurable**». No había nada que detectar.
- **5-3** — RF-PD-06 y el Anexo B encajaban; lo que faltaba era escribir el flujo de tres pasos.
- **3-1** — No había conflicto entre requisitos: se estaba aplicando a la red interna un control de borde pensado para internet.

En los tres casos, la marca `⚠️` no señalaba un hueco en los documentos sino una lectura parcial al desarrollar el plan. Los documentos tenían la respuesta.

### Dónde vive una decisión cuando se toma

**No en este apéndice**, sino en el documento que corresponda según el orden de autoridad: un ADR si es estructural, el `openapi.yaml` si cambia la forma de un endpoint, el documento 01 si es un requisito o una regla, el 02 si es un parámetro o un runbook. Aquí solo queda el enlace.

Es la misma razón por la que se descartó OpenSpec: **una sola fuente de verdad por tipo de decisión.** Un apéndice que acumulara decisiones sería exactamente la sexta fuente de verdad que se quería evitar.

---

Empezar por: **[01 — Herramientas y entorno](01-herramientas-y-entorno.md)** → **[02 — Fase 0, Cimientos](02-fase-0-cimientos.md)**.
