# Fase 5 — Productización

| Campo | Valor |
|---|---|
| **Fase** | 5 — Productización |
| **Horas** | **117–161 h** |
| **Orden de ejecución** | **3.ª** (0 → 1 → 2 → **5** → 3 → 4) |
| **Documento origen** | [docs/02](../docs/02-stack-tecnologico-y-plan-implementacion.md) §11 (Fase 5), §11.6, §12 · [docs/01](../docs/01-especificaciones-proyecto.md) §3.9, §7.3, Anexo A |
| **Requisitos** | `RF-PD-01..15`, `RL-16..21`, `RQ-11` (Anexo A del [doc 01](../docs/01-especificaciones-proyecto.md)) |
| **Agente principal** | `producto-licencia`, con el apoyo indicado en la columna «Agente / Skill» de cada tarea |
| **Entregable** | Un tercero compra, instala, configura, actualiza y opera el sistema sin intervención del fabricante |

> **Convierte el sistema en un producto que un tercero puede comprar, instalar y operar. Es el hito que convierte el proyecto en negocio.**
> — [doc 02](../docs/02-stack-tecnologico-y-plan-implementacion.md) §11, Fase 5

---

## Índice

- [El criterio de éxito de toda la fase](#el-criterio-de-éxito-de-toda-la-fase)
- [Por qué la Fase 5 va tercera y no quinta](#por-qué-la-fase-5-va-tercera-y-no-quinta)
- [Prompt de arranque](#prompt-de-arranque)
- [Requisitos que cubre la fase](#requisitos-que-cubre-la-fase)
- [Contradicciones con el documento 05 detectadas al planificar](#contradicciones-con-el-documento-05-detectadas-al-planificar)
- Tareas
  - [5.1 — Módulo `Product`](#tarea-51--módulo-product-configuración-con-ámbito-resolución-en-cascada-auditoría-de-cambios)
  - [5.2 — Perfiles de cumplimiento](#tarea-52--perfiles-de-cumplimiento-extraer-rn-101112-a-parámetros-perfil-es-hosteleria)
  - [5.3 — Licencia](#tarea-53--licencia-emisión-firmada-verificación-local-límites-y-degradación-honesta)
  - [5.4 — Instalador y Compose de producción](#tarea-54--instalador-compose-de-producción-comprobación-de-requisitos-generación-de-secretos)
  - [5.5 — Asistente de puesta en marcha, incluida la importación masiva de plantilla](#tarea-55--asistente-de-puesta-en-marcha-incluida-la-importación-masiva-de-plantilla)
  - [5.6 — Vinculación de quiosco por código](#tarea-56--vinculación-de-quiosco-por-código-de-emparejamiento)
  - [5.7 — Actualizador](#tarea-57--actualizador-copia-previa-migraciones-encadenadas-verificación-vuelta-atrás)
  - [5.8 — Marca blanca](#tarea-58--marca-blanca-en-las-tres-aplicaciones-y-en-los-pdf)
  - [5.9 — Diagnóstico, `doctor` y accesos de soporte](#tarea-59--paquete-de-diagnóstico-anonimizado-comando-doctor-accesos-de-soporte-auditados)
  - [5.10 — Exportación íntegra y telemetría](#tarea-510--exportación-íntegra-de-datos-y-telemetría-opcional-desactivada-por-defecto)
  - [5.11 — Documentación de cliente](#tarea-511--documentación-de-instalación-operación-configuración-y-obligaciones-legales)
  - [5.11b — Guía de uso para RRHH y hoja del empleado](#tarea-511b--guía-de-uso-para-rrhh-y-hoja-de-instrucciones-del-empleado)
  - [5.12 — Histórico de errores](#tarea-512--histórico-de-errores-en-base-de-datos)
- [La suma de horas y por qué no es la suma](#la-suma-de-horas-y-por-qué-no-es-la-suma)
- [Parámetros de configuración que introduce la fase](#parámetros-de-configuración-que-introduce-la-fase)
- [Runbooks que se redactan en esta fase](#runbooks-que-se-redactan-en-esta-fase)
- [Cierre de fase](#cierre-de-fase)
- [Qué se pierde si se recorta esta fase](#qué-se-pierde-si-se-recorta-esta-fase)
- [Puntos no cubiertos por los documentos](#puntos-no-cubiertos-por-los-documentos)

---

## El criterio de éxito de toda la fase

Está escrito como una sola frase concreta en el [doc 03](../docs/03-agentes-y-skills-ia.md) §4.3 y repetido en el prompt de arranque del §6.5. **Todo lo que se hace en esta fase se juzga contra ella:**

> *Una persona de IT de un hotel, a la que no conocemos y que no sabe Laravel, instala el sistema siguiendo la guía, lo configura, lo actualiza seis meses después y resuelve una incidencia sin llamarnos.*

Las cuatro acciones de esa frase son las cuatro pruebas de aceptación de la fase, y cada una tiene su tarea:

| Acción de la frase | Tareas que la sostienen |
|---|---|
| **Instala** siguiendo la guía | 5.4 (instalador), 5.5 (asistente), 5.6 (quiosco), 5.11 (`instalacion.md`) |
| **Lo configura** | 5.1 (configuración con ámbito), 5.2 (perfil de cumplimiento), 5.8 (marca), 5.11 (`configuracion.md`) |
| **Lo actualiza seis meses después** | 5.7 (actualizador con vuelta atrás y saltos no consecutivos), 5.11 (`operacion.md`) |
| **Resuelve una incidencia sin llamarnos** | 5.9 (`doctor` y paquete de diagnóstico), 5.12 (histórico de errores en el panel), 5.11 (`operacion.md`) |

Y dos condiciones que la frase no dice pero que la hacen posible, y que atraviesan todas las tareas:

- **No podemos entrar a arreglarlo** (ADR-016, ADR-020). El sistema corre en un servidor al que el fabricante no tiene acceso. Eso cambia cómo se escribe todo: los mensajes de error dicen **qué hacer**, no solo qué falló; los registros son legibles por quien no conoce el código (`error_events`, tarea 5.12); y el paquete de diagnóstico contiene lo necesario para resolver **sin pedir una segunda ronda de información** (tarea 5.9).
- **El registro legal nunca es rehén del negocio** (ADR-019, regla dura 15, RF-PD-05). Ninguna tarea de esta fase puede introducir un camino en el que una licencia caducada, un límite excedido o una actualización a medias impidan fichar o consultar el registro.

---

## Por qué la Fase 5 va tercera y no quinta

El [doc 02](../docs/02-stack-tecnologico-y-plan-implementacion.md) §11 lo dice literalmente:

> Orden de ejecución: **0 → 1 → 2 → 5 → 3 → 4**. La Fase 5 se numeró después pero se ejecuta antes que la 3, porque un producto instalable con registro legalmente defendible ya es vendible aunque la observabilidad avanzada llegue después.

El Anexo A del [doc 01](../docs/01-especificaciones-proyecto.md) repite el mismo orden. El §11.1 lo cuantifica: **0 + 1 + 2 + 5 = 336–442 h y es el primer alcance vendible** («el cliente lo instala, configura y opera»); sin la Fase 5, el alcance 0 + 1 + 2 es vendible solo «instalada y operada por el equipo de desarrollo».

**Consecuencias prácticas de ejecutar la 5 antes de la 3, que hay que tener presentes en varias tareas:**

| Al llegar aquí **ya existe** | Al llegar aquí **todavía no existe** (llega en la Fase 3) |
|---|---|
| Dominio y fichaje completos (Fase 1) | OpenTelemetry, Prometheus, Grafana y Loki extremo a extremo (3.1) |
| `audit_log` encadenado y verificable (2.2) | Los 4 cuadros de mando y el catálogo de alertas con runbooks (3.2) |
| Correcciones trazadas (2.3), informes y exportación legal (2.8, 2.9) | Panel de salud de quioscos y pantalla de diagnóstico (3.3) |
| Retención y purga (2.10), copias verificadas (2.11) | Vista de cumplimiento sobre RN-10/11/12 (3.4) |
| Autenticación completa con 2FA y RBAC (2.1) | Validación de desfase de reloj y fichaje de pausa (3.5) |

Esto tiene dos efectos que se recogen en las tareas correspondientes:

1. **La tarea 5.12 (`error_events`) no puede apoyarse en el stack de observabilidad**, porque en este punto no está montado. Es coherente con el §8.2.1: `error_events` existe precisamente para no depender de Loki.
2. **El asistente de puesta en marcha (5.5) sí incluye la importación masiva de plantilla**, y por eso `RF-GP-05` se movió aquí desde la tarea 3.10 (resolución **C-1**, ya aplicada en el paso 9 de la ficha de 5.5 y en la tabla del doc 02 §11). El motivo es que la Fase 5 se ejecuta **antes** que la 3 y el [doc 05](../docs/05-presentacion-cliente.md) §10.2 la promete como paso 4 de la puesta en marcha: un asistente que obliga a teclear a mano la plantilla de un hotel no es un producto instalable. Son **3–4 h que cambian de fase, no que se suman**. Ver [Contradicciones](#contradicciones-con-el-documento-05-detectadas-al-planificar).

---

## Prompt de arranque

Literal del [doc 03](../docs/03-agentes-y-skills-ia.md) §6.5:

```
Ejecuta la Fase 5 del plan (docs/02, §11), que convierte el sistema
en un producto instalable por terceros.

Usa producto-licencia como agente principal, con el apoyo indicado
en la columna "Agente / Skill" de cada tarea.

Criterio de éxito, y todo se juzga contra esto: una persona de IT de un
hotel, a la que no conocemos y que no sabe Laravel, instala el sistema
siguiendo la guía, lo configura, lo actualiza seis meses después y
resuelve una incidencia sin llamarnos.

Requisitos innegociables:
- Nada específico de un cliente en el código. Jamás una rama por cliente
- La caducidad de la licencia NO bloquea el fichaje ni el acceso al
  registro legal (ADR-019)
- Verificación de licencia local, sin llamada a internet (ADR-018)
- Los umbrales de RN-10/11/12 salen del perfil de cumplimiento
- El paquete de diagnóstico va anonimizado por defecto
- El actualizador soporta saltos entre versiones NO consecutivas y
  tiene vuelta atrás verificada

Criterio de terminado: instalación limpia en una máquina virgen siguiendo
solo la guía escrita, y actualización desde la versión anterior con
vuelta atrás probada. Ambas en CI.
```

---

## Requisitos que cubre la fase

Del [doc 01](../docs/01-especificaciones-proyecto.md) §3.9, §7.3 y §10, con la tarea que los materializa:

| ID | Requisito (resumen del doc 01) | Prio | Tarea |
|---|---|:---:|---|
| RF-PD-01 | Cero configuración en código: marca, textos, umbrales, convenio, idiomas y funcionalidades son datos | M | 5.1 |
| RF-PD-02 | Instalación autónoma por el IT del cliente, con comprobación previa de requisitos | M | 5.4 |
| RF-PD-03 | Asistente de puesta en marcha | M | 5.5 |
| RF-PD-04 | Licencia con clave firmada; verificación local, sin internet | M | 5.3 |
| RF-PD-05 | Degradación honesta al expirar: sigue registrando y dando acceso al registro legal | M | 5.3 |
| RF-PD-06 | Vinculación de quiosco por código de emparejamiento, sin SSH | M | 5.6 |
| RF-PD-07 | Perfiles de cumplimiento configurables; perfil español de serie | M | 5.2 |
| RF-PD-08 | Marca blanca en quiosco, panel, portal, tarjetas y PDF | S | 5.8 |
| RF-PD-09 | Paquete de diagnóstico exportable, **anonimizado por defecto** | M | 5.9 |
| RF-PD-10 | Actualización asistida con copia previa verificada, migraciones reversibles y vuelta atrás | M | 5.7 |
| RF-PD-11 | Acceso de soporte con concesión expresa, caducidad, alcance limitado y auditoría | M | 5.9 |
| RF-PD-12 | Telemetría opcional y desactivada por defecto | S | 5.10 |
| RF-PD-13 | Comprobación de salud posinstalación con informe accionable | S | 5.9 |
| RF-PD-14 | Exportación íntegra de los datos del cliente en formato abierto | M | 5.10 |
| RF-PD-15 | Histórico de errores en base de datos, agrupado por huella, sin PII, 90 días | M | 5.12 |
| RL-16 | El cliente es responsable del tratamiento y operador del sistema | — | 5.11 |
| RL-17 | El fabricante no es encargado del tratamiento en operación ordinaria | — | 5.11 |
| RL-18 | Encargo acotado a soporte (art. 28 RGPD) cuando hay acceso durante una intervención | — | 5.9, 5.11 |
| RL-19 | El paquete de diagnóstico no contiene datos personales por defecto | — | 5.9 |
| RL-20 | Continuidad e independencia: exportación total sin intervención del fabricante | — | 5.10 |
| RL-21 | La documentación entregada indica qué obligaciones asume el cliente | — | 5.11 |
| RQ-11 | Prueba de instalación limpia y de actualización desde la versión anterior antes de publicar | — | 5.4, 5.7 (etapa 8 de la CI, §10.1) |

> **Nota sobre la columna «Requisitos» de la tabla del doc 02 §11.** El doc 02 asigna a cada tarea un subconjunto (por ejemplo `RF-PD-04..05` a la 5.3) y no menciona `RL-16..21` ni `RQ-11` en ninguna fila, mientras que el Anexo A del doc 01 sí los incluye en la fase. La tabla de arriba reparte esos requisitos a la tarea que los materializa; en cada ficha de tarea se cita **literal** lo que dice el doc 02 y, cuando aporta, la asignación contrastada.

---

## Contradicciones con el documento 05 detectadas al planificar

El [doc 05](../docs/05-presentacion-cliente.md) «no manda, pero obliga» (`CLAUDE.md`, orden de autoridad). Se detectaron tres puntos, y **las tres están resueltas**:

| # | Resolución | Dónde vive |
|---|---|---|
| **C-1** | **RF-GP-05 se mueve a la tarea 5.5.** La importación de plantilla estaba en la tarea 3.10 de una fase posterior, y el doc 05 la promete en la puesta en marcha. Un asistente que obliga a teclear a mano la plantilla de un hotel no es un producto instalable. Son 3–4 h que cambian de fase, no que se suman; el documento comercial no se toca porque decía la verdad | Anexo A del [doc 01](../docs/01-especificaciones-proyecto.md) · §11 del [doc 02](../docs/02-stack-tecnologico-y-plan-implementacion.md) |
| **C-2** | **`install.ps1` se retira del paquete.** Los requisitos publicados exigen Linux, el §3.5 no define convenciones para PowerShell y ni ShellCheck ni `shfmt` lo analizan, así que el umbral bloqueante del §9.2 no podría aplicársele. Un entregable que ninguna herramienta revisa es peor que no tenerlo | [ADR-022](../docs/adr/ADR-022-sin-instalador-de-windows.md) · §11.6.1 del doc 02 |
| **C-3** | **No era una contradicción: faltaba escribir el flujo.** `POST /api/v1/kiosk/pair` es *la tablet pide emparejarse y recibe el código que muestra*; el administrador lo teclea en el panel, que llama a `/pair/confirm` para vincularla y emitir su token. Los tres documentos quedan intactos, y `kiosk:pairing-code {site}` se conserva como vía alternativa de consola | Notas de contrato del Anexo B del doc 01 · ficha de 5.6 |

Lo que sigue es el análisis original que las detectó, conservado porque explica de dónde venía cada una:

| # | Qué promete el doc 05 | Qué dicen los documentos de autoridad | Afecta a |
|---|---|---|---|
| **C-1** | §10.2, paso 4 de la puesta en marcha: «**Carga de plantilla**: importación desde CSV o Excel, con validación previa» | La importación masiva es `RF-GP-05`, asignada a la **tarea 3.10 de la Fase 3** (doc 02 §11; Anexo A del doc 01). La Fase 5 se ejecuta **antes** de la 3. RF-PD-03 no incluye la carga de plantilla entre los pasos del asistente | 5.5, 5.11 |
| **C-2** | §10.1, tabla de requisitos: «Sistema: **Linux con Docker**». El §11.6.2 del doc 02 dice lo mismo: «Linux con Docker 24+ y Compose v2» | El §11.6.1 del doc 02 entrega `install.sh` **/ `install.ps1`**, es decir, un instalador de PowerShell para un sistema operativo que los requisitos publicados no contemplan. Y el §3.5 solo define convenciones y verificación (ShellCheck, `shfmt`) para los scripts de shell | 5.4, 5.11 |
| **C-3** | §10.2, paso 3: «la tablet muestra un código, se introduce en el panel». `RF-PD-06` dice lo mismo | El Anexo B del doc 01 define `POST /api/v1/kiosk/pair` como **público y de un solo uso** (lo llama el quiosco) y el Anexo C define `php artisan kiosk:pairing-code {site}`, que **genera** el código en el servidor. Son dos flujos de dirección opuesta | 5.6 |

Cada una se recoge en la ficha de la tarea afectada con la decisión que hay que tomar.

---

## Las tareas, desarrolladas

### Tarea 5.1 — Módulo `Product`: configuración con ámbito, resolución en cascada, auditoría de cambios

| | |
|---|---|
| **Horas** | 8–10 |
| **Agente / Skill** | `arquitecto-dominio` → `producto-licencia` |
| **Requisitos** | `RF-PD-01` (doc 02 §11). Contrastado con el Anexo A del doc 01: la fase cubre `RF-PD-01..15`; esta tarea materializa `RF-PD-01` y da soporte a `RF-PD-07` (5.2) y `RF-PD-08` (5.8) |
| **Precondiciones** | `2.8 → 2.9` cerradas (informes y exportaciones), según la cadena del §11.3: `… 2.8→2.9 └─► 5.1` |
| **Bloquea a** | `5.2` (y por tanto `5.3`) y `5.4` (y por tanto `5.5` y `5.7`), según §11.3: `5.1→5.2 ──► 5.3` y `5.1 └─► 5.4→5.5→5.7`. En la práctica también a 5.8 y 5.10, que leen configuración, aunque el §11.3 no las sitúa |

**Objetivo.** Existe el módulo `Product` con la tabla `installation_settings`, una resolución de configuración en cascada por ámbito, dos puertos tipados por los que el resto del sistema recibe valores ya resueltos, y los endpoints `GET`/`PATCH /api/v1/settings` con auditoría de todo cambio. A partir de esta tarea, **vender a un cliente nuevo no requiere tocar el repositorio**.

**Reglas duras aplicables.**

- **13 — Nada específico de un cliente vive en el código** (ADR-017). Es la razón de ser de la tarea: es la infraestructura que hace cumplible la regla. Si al terminar sigue existiendo un solo valor de cliente en una constante, la tarea no está hecha.
- **1 — `Domain/` es puro.** `Product/Domain/` no puede importar `Illuminate\*` ni Eloquent; el acceso a `installation_settings` vive en `Infrastructure/Persistence/`.
- **14 — El dominio recibe el umbral ya resuelto, nunca consulta la configuración.** Los puertos `CompliancePolicyProvider` y `OperationalSettingsProvider` **ya están declarados** en `Shared/Application/Port/` desde la tarea 1.1 ([ADR-025](../docs/adr/ADR-025-frontera-de-dependencias-del-nucleo.md)): lo que se define aquí es `BrandingProvider` y lo que se implementa son los adaptadores, en `Product/Infrastructure/Adapter/`. La implementación de cumplimiento llega en 5.2.
- **6 — Toda acción con relevancia legal escribe en `audit_log`.** Un cambio de configuración puede alterar el cálculo de horas (doc 01 §5, nota de `installation_settings`: «Todo cambio queda auditado, porque algunos afectan al cálculo de horas»).
- **18 — Cada endpoint tiene su policy y su prueba de autorización negativa.** `GET`/`PATCH /api/v1/settings` son de rol `admin` (Anexo B del doc 01; ámbito `settings:*` del §7.3 del doc 02).

**Pasos.**

1. **Contrato primero** (ADR-013): añadir a [`docs/api/openapi.yaml`](../docs/api/openapi.yaml) `GET /api/v1/settings` y `PATCH /api/v1/settings` con sus esquemas, tal como los declara el Anexo B del doc 01 (`[rol: admin]` y `[rol: admin, auditado]`). El contrato se modifica **antes** que el código.
2. **Ampliación de la tabla `installation_settings` creada en la tarea 1.3**, con los campos exactos del doc 01 §5: `key`, `value` (`JSONB`), `scope` (`installation`|`site`), `scope_id`, `updated_by_user_id`, `updated_at`. Aquí se añade lo que la Fase 1 no necesitaba: **el índice único por `(key, scope, scope_id)`**, el índice GIN sobre `value` si se consulta (skill `/migracion-segura`, reglas de tipos) y las columnas de ámbito que sostienen la cascada. `scope` como `enum` de PHP, no como constantes de clase (§3.5, modernización).

   > **La tabla no se crea aquí, y esto importa.** La decisión 1-4 la adelantó a la **tarea 1.3** junto con `compliance_profiles`, porque la regla dura 14 exige que los umbrales se lean de configuración desde el primer cálculo y no desde la Fase 5. Escribir aquí una migración de creación produciría **una migración duplicada que falla al desplegar**. Lo que esta tarea añade es lo caro —cascada por ámbito, resolución tipada, caché, edición y auditoría—, no la tabla. Como hay datos, **aquí sí aplica el patrón completo de `/migracion-segura`** (expand / migrate / contract), a diferencia de la 1.3, donde el esquema nacía vacío.
3. **Definir el catálogo de claves y su valor por defecto en código**, no en base de datos: el valor por defecto **es** el producto, y una instalación sin ninguna fila en `installation_settings` debe arrancar y funcionar. Las claves cubren lo que enumera el doc 01 §5: marca, umbrales, idiomas y funcionalidades activas.
4. **Resolución en cascada**, de lo más específico a lo más general: `scope = installation` → valor por defecto del catálogo. El ámbito `site` de la tabla queda sin uso desde ADR-040 (hay un centro por instalación); esta tarea decide si lo retira con una migración de contracción o lo deja documentado como reservado. Un solo punto de resolución, tipado, con la clave declarada; nada de acceso por cadena suelta desde cualquier módulo.
5. **Puertos tipados, y cada uno en su módulo** ([ADR-025](../docs/adr/ADR-025-frontera-de-dependencias-del-nucleo.md)). `CompliancePolicyProvider` y `OperationalSettingsProvider` viven en **`Shared/Application/Port/`** y los declaró la tarea 1.1: aquí **no se redeclaran**, se implementan. `BrandingProvider` se declara ahora, también en `Shared/Application/Port/`, porque lo consumen los tres frontends vía `Reporting` y `Product`. El resolvedor genérico de configuración (`SettingsRepository`) sí es interno de `Product`. Es la contrapartida de la nota del doc 02 §1.6: *«los demás módulos no leen su configuración directamente: reciben los valores ya resueltos como parámetros, o mediante un puerto tipado […] El dominio nunca pregunta "¿qué dice la configuración?": recibe el umbral ya decidido»*. Verificar con Deptrac: ningún `Domain/` de otro módulo depende de `Product`, y **no existe más de una declaración de cada puerto** en todo el árbol.
6. **Caché de la resolución** en Redis (`CACHE_STORE=redis`, Anexo B) con invalidación explícita en cada escritura. Una configuración que se lee en cada fichaje no puede costar una consulta por escaneo.
7. **Endpoints y policy**: `SettingsController` con FormRequest de validación por clave y `Resource` de serialización (§3.5, Laravel idiomático). Sin lógica de negocio en el controlador.
8. **Auditoría del cambio**: cada `PATCH` escribe en `audit_log` con actor, clave, valor anterior y valor nuevo, y marca si la clave afecta al cálculo de horas. La regla de la skill `/revision-cumplimiento` aplica: *el coste de auditar de más es despreciable; el de auditar de menos, no*.
9. **Textos de la pantalla y de los errores** externalizados en `i18n`, español e inglés (§3.5, transversal; DoD §10.3).

**Artefactos.**

```
backend/app/Modules/Product/
├── Domain/ValueObject/            # SettingKey, SettingScope (enum)
├── Application/
│   ├── Port/                      # SettingsRepository (interno de Product; los tres puertos
│   │                              # transversales viven en Shared/Application/Port/, ADR-025)
│   ├── UseCase/                   # GetSettingsHandler, UpdateSettingsHandler
│   └── Query/
├── Infrastructure/
│   ├── Persistence/               # Modelo Eloquent + EloquentSettingsRepository
│   └── Adapter/                   # Resolvedor en cascada + caché
├── Http/                          # SettingsController, FormRequest, Resource, Policy
└── ProductServiceProvider.php
backend/database/migrations/        # amplía installation_settings (creada en 1.3):
                                    # índices, ámbito y cascada. NO la crea
backend/routes/api_v1.php
docs/api/openapi.yaml
docs/cliente/configuracion.md       # se completa en 5.11
```

**Pruebas exigidas.** Fila «Cambia **configuración con efecto en el cálculo de horas**» de la tabla del §9.5 — es exactamente el caso de esta tarea:

| Nivel | Qué se prueba | Etiquetas |
|---|---|---|
| Unitaria | Resolución en cascada: `installation` gana al valor por defecto, y sin filas se devuelve el valor por defecto | `->group('RF-PD-01')` |
| Integración | Esquema y unicidad de `(key, scope, scope_id)`; **auditoría del cambio**: un `PATCH` deja entrada en `audit_log` con valor antes y después | `->group('RF-PD-01', 'RL-04')` |
| Feature + Contrato | `GET`/`PATCH /api/v1/settings` validan contra `openapi.yaml` (Spectator) | `->group('RF-PD-01')` |
| Autorización negativa | **Por cada rol no autorizado** (`manager`, `rrhh`, `auditor`, quiosco con `scope: kiosk`, portal con `self:read`): 403 y registro del fallo | `->group('RF-PD-01', 'RS-05')` |
| Arquitectura | Deptrac: ningún `Domain/` depende de `Product`; `Product/Domain/` sin `Illuminate\*` | — |

Del §9.4, escenario ineludible aplicable: **cadena de auditoría** (que el cambio de configuración quede encadenado y `compliance:verify-audit-chain` siga en verde).

**Verificación.**

```bash
make quality          # Pint + PHPStan 9 + Deptrac + Rector dry-run → 0 errores
make test-unit        # < 2 s, incluida la cascada
php artisan test --group=RF-PD-01
php artisan qa:traceability --check
php artisan compliance:verify-audit-chain   # verde tras varios PATCH
```

Resultado esperado: `Deptrac` sin violaciones; `qa:traceability --check` sin requisitos implementados sin prueba; y una instalación **sin ninguna fila** en `installation_settings` arranca y responde `GET /api/v1/settings` con los valores por defecto.

**Terminado cuando.** Subconjunto aplicable de la DoD del §10.3:

- [ ] Deptrac en verde y `Product/Domain/` sin framework.
- [ ] Pruebas en los cuatro niveles de la tabla anterior; `qa:traceability --check` verde.
- [ ] PHPStan 9 sin errores nuevos.
- [ ] Contrato OpenAPI actualizado **antes** del código y validado en pruebas.
- [ ] Autorización probada en negativo por cada rol.
- [ ] Todo cambio de configuración escribe en `audit_log`.
- [ ] Migración reversible y `down()` probado (skill `/migracion-segura`).
- [ ] Textos en español e inglés.
- [ ] **Nada específico de un cliente ha entrado en el código.**
- [ ] ADR-017 revisado: si la implementación matiza la decisión, se actualiza el ADR.

---

### Tarea 5.2 — Perfiles de cumplimiento; extraer RN-10/11/12 a parámetros; perfil `ES-hosteleria`

| | |
|---|---|
| **Horas** | 10–12 |
| **Agente / Skill** | `producto-licencia` + `/nueva-regla-de-negocio` |
| **Requisitos** | `RF-PD-07` (doc 02 §11). Toca `RN-10`, `RN-11` y `RN-12`, cuyos umbrales el doc 01 §4 declara «parámetros del perfil de cumplimiento […] no constantes» |
| **Precondiciones** | `5.1` (§11.3: `5.1→5.2`) |
| **Bloquea a** | `5.3` (§11.3: `5.1→5.2 ──► 5.3`) |

**Objetivo.** Los umbrales legales dejan de ser constantes: viven en `compliance_profiles`, se entregan configurados en el perfil `ES-hosteleria`, y llegan al dominio por el puerto `CompliancePolicyProvider` ya resueltos. Cambiar de convenio deja de ser un cambio de código.

**Reglas duras aplicables.**

- **14 — Los umbrales legales se leen del perfil de cumplimiento**, y *el dominio recibe el umbral ya resuelto por un puerto; nunca consulta la configuración*. Es el enunciado exacto de esta tarea.
- **13 — Nada específico de un cliente en el código.** El perfil `ES-hosteleria` es una **semilla de datos**, no una clase; un cliente con otro convenio crea otro perfil desde el panel.
- **1 y 2 — `Domain/` puro y sin `now()`.** `ClockingPolicy` recibe umbrales y `Clock`; no lee configuración ni la hora del sistema.
- **5 y 6 — Nada se sobrescribe y todo cambio legal se audita.** Cambiar un umbral altera qué jornadas se consideran anómalas: es un cambio con efecto legal y se audita.

**Pasos.**

1. **Documentar antes de implementar** (skill `/nueva-regla-de-negocio`, paso 1): reescribir el enunciado de RN-10, RN-11 y RN-12 en términos de parámetro, en lenguaje comprensible para RRHH. *«Se alerta si entre el fin de un turno y el inicio del siguiente median menos de `min_rest_hours`»*, no «si median menos de 12 h».
2. **Ampliación de la tabla `compliance_profiles` creada en la tarea 1.3**, hasta los campos exactos del doc 01 §5: `id`, `name`, `jurisdiction`, `retention_years`, `min_rest_hours`, `max_daily_hours`, `max_weekly_hours`, `break_required_after_hours`, `week_starts_on`, `holiday_calendar` (`JSONB`), `is_default`. `sites.compliance_profile_id` ya existe en el esquema del doc 01 §5, así que el perfil se resuelve **por centro**.

   > **La tabla y su fila semilla `ES-hosteleria` se crean en la tarea 1.3**, no aquí (decisión 2-1). Sin ella, las tareas 2.6 y 2.10 tendrían que leer los umbrales de una constante durante dos fases enteras, incumpliendo la regla dura 14. Escribir aquí una migración de creación produciría una migración duplicada. Lo que esta tarea aporta es la **gestión**: edición desde el panel, resolución por centro, retroactividad decidida y auditoría del cambio.

3. **Verificar y completar la semilla del perfil `ES-hosteleria`** con los valores que el doc 01 §4 y §7.1 fijan hoy: `min_rest_hours = 12` (RN-10, art. 34.3 ET), `max_daily_hours = 9` (RN-11), `break_required_after_hours = 6` (RN-12), `retention_years = 4` (RL-11). `COMPLIANCE_PROFILE=ES-hosteleria` (Anexo B) selecciona el perfil por defecto de la instalación. **`max_weekly_hours`, `week_starts_on` y `holiday_calendar` no tienen valor fijado en ningún documento** — ver [Puntos no cubiertos](#puntos-no-cubiertos-por-los-documentos).
4. **Extraer los umbrales de donde estén.** Al llegar aquí, RN-10/11/12 están implementados desde la tarea 2.6 (detección automática de incidencias) y consumidos por la 3.4, que **aún no existe**. La extracción consiste en sustituir el valor por el que devuelve el puerto, sin tocar la forma de la regla: *«Lo invariable es la forma de la regla; configurable es el número»* (doc 01 §4).
5. **Implementar `CompliancePolicyProvider`** —puerto declarado en `Shared/Application/Port/` desde la tarea **1.1**, no en 5.1— en **`Product/Infrastructure/Adapter/DbCompliancePolicyProvider`**, que es donde está `compliance_profiles` ([ADR-025](../docs/adr/ADR-025-frontera-de-dependencias-del-nucleo.md)): resuelve el perfil del centro del empleado y devuelve un objeto de valor inmutable con los umbrales. Es el `DbCompliancePolicyProvider` que aparece en el diagrama C4 del §1.5.
6. **Decidir y documentar la retroactividad.** Lo exige la skill `/nueva-regla-de-negocio` (doc 03 §5.1): *«obliga a tomar y documentar una decisión sobre retroactividad, que en un registro legal no es inocua: recalcular el pasado puede alterar registros ya entregados»*. Aquí el caso concreto es: **si un cliente cambia `min_rest_hours` de 12 a 10, ¿se recalculan las incidencias de descanso insuficiente ya generadas y posiblemente ya resueltas?** Ningún documento lo decide — ver [Puntos no cubiertos](#puntos-no-cubiertos-por-los-documentos). Lo que sí está fijado y acota la decisión: regla dura 5 (nada se borra ni se sobrescribe) y RN-13, de modo que cualquier opción elegida debe conservar el estado anterior con autor, momento y motivo.
7. **Pantalla de perfil en el panel** con los umbrales, su unidad y una advertencia visible de que el cambio afecta a la detección de incidencias. Textos en `i18n` ES/EN.
8. **Auditar el cambio de perfil y de umbral** en `audit_log`, incluyendo el valor anterior. Sin esto, ante una inspección no se puede explicar por qué una jornada de hace tres meses no generó alerta.

**Artefactos.**

```
backend/app/Modules/Compliance/Domain/Policy/          # umbrales como objeto de valor
backend/app/Modules/Product/Infrastructure/Adapter/    # DbCompliancePolicyProvider
backend/database/migrations/                           # amplía compliance_profiles (creada
                                                       # en 1.3). NO la crea
backend/database/seeders/                              # ES-hosteleria (ya sembrado en 1.3)
frontend-admin/src/features/settings/                  # pantalla de perfil de cumplimiento
docs/cliente/configuracion.md                          # se completa en 5.11
docs/cliente/obligaciones-legales.md                   # se completa en 5.11
```

**Pruebas exigidas.** Filas «Introduce o modifica una **regla de negocio**» y «Cambia **configuración con efecto en el cálculo de horas**» del §9.5:

| Nivel | Qué se prueba | Etiquetas |
|---|---|---|
| Unitaria | Las tres reglas con el **umbral inyectado**, en los límites exactos: con `min_rest_hours = 12`, casos de 11:59, 12:00 y 12:01 (§3.5, código de pruebas: «los valores límite se escriben explícitos»). Y con un umbral distinto, para probar que la regla no lleva el número dentro | `->group('RF-PD-07', 'RN-10')`, `('RN-11')`, `('RN-12')` |
| Unitaria | Medianoche y DST con el umbral parametrizado (exigencia de la skill `/nueva-regla-de-negocio`) | `->group('RN-09', 'RF-PD-07')` |
| Integración | Esquema de `compliance_profiles`, `is_default`, resolución del perfil por centro; auditoría del cambio de umbral | `->group('RF-PD-07', 'RL-04')` |
| Feature + Contrato | Endpoint de lectura y escritura del perfil (vía `PATCH /api/v1/settings` o recurso propio, según lo que fije el contrato) | `->group('RF-PD-07')` |
| Autorización negativa | 403 por cada rol distinto de `admin` | `->group('RF-PD-07')` |
| Mutación | MSI ≥ 80 % sobre el dominio afectado: es donde un `>` por `>=` cambia la nómina de alguien (§9.3) | — |

Del §9.4: **cambio de hora (DST)** y **turno que cruza medianoche**, ya cubiertos por la Fase 1, deben seguir en verde con umbrales parametrizados.

**Verificación.**

```bash
make test-unit                       # las tres reglas con umbral inyectado
make mutate                          # MSI ≥ 80 % sobre Modules/*/Domain
php artisan test --group=RF-PD-07
php artisan migrate:rollback && php artisan migrate    # down() probado
php artisan qa:traceability --check
```

Resultado esperado: cambiar `min_rest_hours` en la base de datos y volver a ejecutar `attendance:detect-incidents` produce un resultado distinto **sin haber tocado una línea de código**. Ese es el criterio de aceptación de la tarea.

**Terminado cuando.** Subconjunto aplicable de la DoD del §10.3:

- [ ] Deptrac en verde: el dominio no conoce `installation_settings` ni `compliance_profiles`.
- [ ] Unitarias en los límites exactos, DST y medianoche; MSI ≥ 80 %.
- [ ] Integración con auditoría del cambio.
- [ ] Autorización negativa por rol.
- [ ] Migración reversible con `down()` probado sobre volumen realista.
- [ ] **Decisión de retroactividad tomada y escrita** en `docs/cliente/configuracion.md` y en el ADR si es estructural.
- [ ] Perfil `ES-hosteleria` sembrado y documentado umbral a umbral.
- [ ] Textos en español e inglés.
- [ ] `docs/cliente/obligaciones-legales.md` recoge que ajustar el perfil al convenio aplicable es responsabilidad del cliente (RL-16, RL-21).

> **Tensión con la tarea 2.10, que conviene registrar.** La tarea 2.10 (retención con confirmación y purga, `RL-02`, `RL-11`, `RF-PR-03`) se ejecuta en la Fase 2, **antes de que exista el perfil de cumplimiento**, y necesita el umbral de retención de 4 años. Es decir: en la Fase 2 ese umbral vive necesariamente fuera del perfil, y esta tarea tiene que **migrarlo a `compliance_profiles.retention_years` sin cambiar el comportamiento**. Consecuencias que la tarea debe cubrir: la purga pasa a leer el umbral del perfil del centro, la migración conserva el valor efectivo anterior (4 años) para no purgar de más, y `compliance:apply-retention --dry-run` (Anexo C) se ejecuta antes y después para comparar que el conjunto a purgar es idéntico. **Purgar de más es irreversible sobre datos con obligación legal de conservación de cuatro años**: si esta migración se hace mal, se destruye el registro de un cliente.

---

### Tarea 5.3 — Licencia: emisión firmada, verificación local, límites y degradación honesta

| | |
|---|---|
| **Horas** | 15–20 |
| **Agente / Skill** | `producto-licencia`, revisión de `seguridad-cumplimiento` |
| **Requisitos** | `RF-PD-04..05` (doc 02 §11), contrastados con el Anexo A del doc 01 |
| **Precondiciones** | `5.2` (§11.3: `5.1→5.2 ──► 5.3`) |
| **Bloquea a** | Ninguna tarea de la Fase 5 según el §11.3: es hoja de la cadena `5.1→5.2──►5.3`. En la práctica condiciona a 5.5 (el asistente activa la clave) y a 5.9 (el paquete de diagnóstico informa del estado de licencia) |

**Objetivo.** Existe una clave de licencia firmada con ed25519 que codifica cliente, plan, límites y vigencia, se verifica **en local sin ninguna llamada a internet**, y al caducar degrada funcionalidades accesorias **sin tocar jamás el fichaje ni el acceso al registro legal**.

**Reglas duras aplicables.**

- **15 — La caducidad de la licencia jamás bloquea el fichaje ni el acceso al registro legal** (ADR-019). Es la regla que gobierna la tarea entera.
- **19 — El quiosco nunca bloquea al empleado.** Ni por licencia. El quiosco no debe ni enterarse del estado de la licencia en su camino de fichaje.
- **13 — Nada específico de un cliente en el código.** El nombre del cliente y sus límites viajan **dentro de la clave firmada** y en la tabla `license`; no en una constante ni en una rama.
- **6 — Auditoría.** Activar una clave es una acción con relevancia legal y comercial: se audita con actor y momento.
- **18 — Policy y autorización negativa** en `GET /api/v1/license` y `POST /api/v1/license/activate` (Anexo B: `[rol: admin]`; ámbito `license:*` del §7.3).
- **21 — Sin PII en logs.** El log de verificación de licencia no lleva datos de empleados.

**La separación que esta tarea tiene que dejar hecha en el código.** El ADR-019 lo dice explícitamente: *«Exige separar en el código lo que es "registro legal" de lo que es "producto"»*. No es una recomendación de diseño: es el entregable central de la tarea. La frontera se implementa como una **lista explícita y probada**, no como un criterio a interpretar en cada endpoint nuevo.

| Categoría | Qué incluye, según los documentos | Efecto de una licencia caducada |
|---|---|---|
| **Registro legal — nunca degradable** | Fichaje en todas sus formas: `POST /api/v1/scan`, `/scan/batch`, `/scan/pin`, `GET /api/v1/kiosk/roster`, `POST /api/v1/kiosk/heartbeat` (RF-PD-05: «sigue registrando fichajes»). Consulta y exportación del registro: `GET /api/v1/employees/{uuid}/workdays`, `GET /api/v1/reports/legal-export` (RL-06), `GET /api/v1/me/workdays` y `GET /api/v1/me/export` (RL-05, derecho del trabajador). Correcciones trazadas y auditoría, porque son parte de la fiabilidad del registro (RL-04). Retención y purga (RL-11). Exportación íntegra `product:export-all` (RF-PD-14, RL-20: «para seguir cumpliendo su obligación de conservación **aunque la relación comercial termine**»). Sondas `GET /api/v1/health` y `/ready` | **Ninguno.** Funcionan igual, sin aviso bloqueante en el camino de fichaje |
| **Producto — degradable** | Las «funcionalidades accesorias» de RF-PD-05, gobernadas por el campo `features` (`JSONB`) de la tabla `license` (doc 01 §5). **La lista está ahora en [ADR-023](../docs/adr/ADR-023-frontera-registro-legal-y-funcionalidad-accesoria.md)** | Aviso visible en el panel y recorte de la funcionalidad accesoria |

**La frontera, ya enumerada** ([ADR-023](../docs/adr/ADR-023-frontera-registro-legal-y-funcionalidad-accesoria.md), estado *Aceptada — confirmada por el responsable de producto el 13 de agosto de 2026*):

| Nunca degradable — registro legal | Degradable — accesorio |
|---|---|
| Fichaje por QR y por PIN | Informes avanzados y comparación entre periodos |
| Sincronización de la cola offline | Cuadro de impacto y adopción (RF-IN-08) |
| Consulta de jornadas y tramos | Exportación configurable para nómina (RF-IN-07) |
| Portal del empleado (RL-05) | Resumen semanal por correo (RF-PR-05) |
| Exportación legal para Inspección (RL-06) | Tiempo real → **degrada a sondeo**, no se apaga (ADR-011) |
| Auditoría (RL-04) | Marca blanca → vuelve a la marca por defecto |
| Correcciones trazadas (RN-13) | Telemetría (ya desactivada de serie) |
| Copias y restauración | |
| Sondas de salud y `error_events` | |

Dos reglas de esa lista que hay que implementar, no solo leer:

- **Lo no clasificado es no degradable por defecto.** Añadir funcionalidad obliga a clasificarla, y ante la duda gana el registro. Entra en la Definición de Terminado de toda tarea de la Fase 3 o posterior.
- **El primer conjunto no es licenciable.** No aparece en el campo `features`, de modo que **no existe forma de expresar su desactivación**. Eso es más fuerte que confiar en que nadie lo desactive.

> **La lista es contractual, no técnica**, y sigue siéndolo tras confirmarse. Es lo que se le puede decir a un cliente que perderá. La confirmación de ADR-023 es una decisión de producto que permite a esta tarea dar la lista por buena para construir; una oferta comercial concreta que necesite ampliarla o restringirla se resuelve con un ADR nuevo, no editando la lista aquí.

1. **Formato y firma de la clave.** ed25519 con `sodium` nativo de PHP (§3.1: «Firma de licencia — `sodium` de PHP (ed25519), nativo, verificación local sin dependencias externas»). Carga útil con los campos que exige RF-PD-04 y que refleja la tabla `license` del doc 01 §5: `customer_name`, `plan`, `max_sites`, `max_employees`, `max_devices`, `features`, `valid_until`.
2. **La clave privada de emisión no entra jamás en el repositorio ni en el paquete de entrega** (§7.7, RS-08). En el servidor del cliente solo vive la **clave pública** de verificación, y la clave firmada en `LICENSE_KEY` (Anexo B). Semgrep vigila que no aparezca ningún secreto en el código (§3.5, secretos).
3. **Verificación local, sin red** (ADR-018, RF-PD-04). Se comprueba la firma con la clave pública embebida y la fecha con el puerto `Clock`. **Prohibida cualquier llamada saliente en el camino de verificación**: una prueba debe fallar si aparece. Se registra `last_verified_at` en la tabla `license`.
4. **Migración de la tabla `license`** con los campos del doc 01 §5. Al no haber clave activada, la instalación **funciona igual**: sin licencia válida el sistema está en estado degradado, nunca detenido.
5. **Comandos de consola** del Anexo C: `php artisan license:show` (estado legible: cliente, plan, límites, vigencia, días restantes y qué está degradado) y `php artisan license:activate {key}` (valida, persiste y audita). `license:show` **no imprime la clave completa ni ningún secreto** (§3.5, secretos).
6. **Endpoints** del Anexo B: `GET /api/v1/license` y `POST /api/v1/license/activate`, ambos `[rol: admin]`, con el contrato actualizado antes del código.
7. **Límites del plan** (`max_employees`, `max_devices`; no hay `max_sites`, ADR-040): superarlos **no bloquea ninguna operación** ([ADR-028](../docs/adr/ADR-028-limites-del-plan-no-bloquean.md)). El exceso produce tres efectos, los tres verificables en una auditoría de licencia:

   1. **Aviso persistente en el panel** para los roles de administración, con la cifra contratada, la real y desde cuándo se supera. Persistente significa que no se descarta: desaparece cuando el exceso se corrige o la licencia se amplía.
   2. **Entrada en `audit_log`** al cruzar el umbral y en cada alta posterior en exceso, con la acción, el límite, el valor contratado y el alcanzado. Es la prueba que sostiene la reclamación comercial: la fecha exacta desde la que el cliente opera por encima del plan.
   3. **Cifra visible en `license:show`**: contratado frente a real para las tres magnitudes.

   > **Ninguna ruta del producto puede devolver un error de licencia al dar de alta a una persona ni al emparejar un dispositivo.** `POST /api/v1/employees`, la importación masiva de plantilla (5.5), el alta de centro y `/kiosk/pair/confirm` responden 2xx aunque el plan esté superado. Bloquear el alta al superar `max_employees` **deja trabajando sin registro horario** a quien no se puede dar de alta —infracción del art. 34.9 ET imputable al cliente y causada por el producto—, y bloquear `max_devices` **deja un centro sin punto de fichaje** justo al sustituir un quiosco averiado. Es el resultado que ADR-019 declara inaceptable, alcanzado por un rodeo: no bloquea el fichaje de hoy, bloquea el de mañana. **La palanca comercial es el contrato, no el software.**

   La comprobación de límites vive en el **punto único de decisión** del `FeatureGate`, no en `if`s dispersos por `Workforce`, `Identity` y `Kiosk`: el conteo es un **observador** que escucha los eventos de alta, no un guardián que los intercepta. Así ninguno de esos módulos consulta la licencia y no hacen falta aristas nuevas en el §1.6.
8. **Degradación honesta y visible.** «Honesta» significa que el sistema dice **qué está degradado, desde cuándo y qué hay que hacer para recuperarlo**, y no que se comporte de forma silenciosamente peor. El aviso vive en el panel, no en el quiosco.
9. **Instrumentación** (§10.3, DoD): estado de licencia expuesto en `GET /api/v1/health` junto con la versión desplegada (§10.5), para que el paquete de diagnóstico y `doctor` puedan informarlo (5.9).
10. **Retrofit del `FeatureGate` sobre las funcionalidades ya construidas que ADR-023 lista como degradables.** El punto único de decisión nace en esta tarea, pero las Fases 1 y 2 ya entregaron funcionalidad clasificada: **los informes avanzados y la comparación entre periodos (2.8)** y **el tiempo real de la presencia en vivo (2.4)**, que ADR-011 y ADR-023 mandan *degradar a sondeo, no apagar*. Cablearlas ahora al `FeatureGate` es lo que evita que la lista de ADR-023 quede a medias y que la degradación se implemente después con `if`s repartidos. Cada tarea de la Fase 3 en adelante llega ya clasificada por su propia Definición de Terminado.
11. **Revisión de `seguridad-cumplimiento`**, exigida por la columna del doc 02 §11. Con tres preguntas obligatorias: ¿existe algún camino en el que la licencia impida fichar o consultar el registro? ¿existe alguno en el que impida **dar de alta** a una persona o **emparejar** un dispositivo (ADR-028)? ¿la verificación es realmente local? El §8.1 del doc 01 lo enmarca: la alteración de la clave de licencia *«es un control comercial, no de seguridad de datos: no debe protegerse a costa de bloquear el registro legal»*.

**Artefactos.**

```
backend/app/Modules/Product/
├── Domain/                        # License, LicenseLimits, LicenseStatus (objetos de valor)
├── Application/
│   ├── Port/                      # LicenseVerifier, FeatureGate
│   └── UseCase/                   # ActivateLicenseHandler, GetLicenseStatusHandler
├── Infrastructure/Adapter/        # Ed25519LicenseVerifier (sodium)
├── Http/                          # LicenseController, Policy
└── Console/                       # license:show, license:activate
backend/database/migrations/       # create_license_table
frontend-admin/src/features/settings/   # estado de licencia y avisos
docs/api/openapi.yaml
docs/adr/ADR-018.md, ADR-019.md    # escritos en 0.6; se revisan aquí
```

**Pruebas exigidas.** Filas «Expone o modifica un **endpoint**» y «Introduce o modifica una **regla de negocio**» del §9.5:

| Nivel | Qué se prueba | Etiquetas |
|---|---|---|
| Unitaria | Verificación de firma válida, firma manipulada, clave de otro emisor, clave caducada, clave sin campos obligatorios. Cálculo de estado con el `Clock` inyectado (regla dura 2), incluidos el día exacto de caducidad y el siguiente | `->group('RF-PD-04')` |
| Unitaria | **La frontera:** para cada elemento de la lista «registro legal», el `FeatureGate` devuelve permitido con licencia caducada. Es la prueba que materializa ADR-019 | `->group('RF-PD-05')` |
| Integración | Persistencia de `license`, `last_verified_at`, auditoría de la activación | `->group('RF-PD-04', 'RL-04')` |
| Feature + Contrato | `GET /api/v1/license`, `POST /api/v1/license/activate` contra `openapi.yaml` | `->group('RF-PD-04')` |
| Feature | **Con licencia caducada**, `POST /api/v1/scan` responde 200 y crea el tramo; `GET /api/v1/reports/legal-export` responde 200; `GET /api/v1/me/workdays` responde 200. Si alguna de estas tres falla, la tarea está mal hecha | `->group('RF-PD-05', 'RL-05', 'RL-06')` |
| Feature (**negativa, ADR-028**) | **Con `max_employees` superado**, `POST /api/v1/employees` responde 2xx y el empleado queda dado de alta y capaz de fichar. **Con `max_devices` superado**, `/kiosk/pair/confirm` vincula el dispositivo y el quiosco queda operativo. Con `max_sites` superado, el alta de centro responde 2xx | `->group('RF-PD-04', 'RF-PD-05', 'RL-01')` |
| Integración | Cruzar cada umbral escribe en `audit_log` con límite, valor contratado y valor alcanzado; el aviso del panel no es descartable mientras el exceso persista; `license:show` imprime contratado y real para las tres magnitudes | `->group('RF-PD-04', 'RL-04')` |
| Arquitectura (ADR-023 + ADR-028) | **Ninguna lectura de `license` ni comprobación de `features` fuera del punto único de decisión**, incluidas las funcionalidades retrofitadas de 2.4 y 2.8 | `->group('RF-PD-05')` |
| Autorización negativa | 403 en ambos endpoints para todo rol distinto de `admin`, incluido el token de quiosco | `->group('RF-PD-04')` |
| Seguridad | Ninguna petición HTTP saliente durante la verificación (cliente HTTP simulado que falla la prueba si se invoca) | `->group('RF-PD-04')` |
| Mutación | MSI ≥ 80 % sobre el dominio de licencia | — |

**Verificación.**

```bash
php artisan license:show                       # sin clave: estado degradado, nunca error fatal
php artisan license:activate "<clave de prueba caducada>"
php artisan test --group=RF-PD-05              # el bloque que prueba que NO bloquea
make test-unit && make mutate
php artisan qa:traceability --check
```

Resultado esperado: con una clave caducada, un fichaje entra y se puede exportar el registro legal. `license:show` explica en lenguaje llano qué está degradado y qué hacer. En ninguna salida aparece un secreto.

**Terminado cuando.** Subconjunto aplicable de la DoD del §10.3:

- [ ] Deptrac en verde; verificación de firma en `Infrastructure/`, decisión de estado en el dominio.
- [ ] Pruebas en los niveles de la tabla; **la prueba de «licencia caducada no bloquea» existe y está etiquetada `RF-PD-05`**, y también las dos negativas de ADR-028 (alta con `max_employees` superado, emparejamiento con `max_devices` superado).
- [ ] Funcionalidades degradables de las Fases 1 y 2 (2.4, 2.8) **cableadas al `FeatureGate` único**, con la prueba de arquitectura que ADR-023 exige.
- [ ] PHPStan 9 limpio; Semgrep sin hallazgos de secretos.
- [ ] Contrato OpenAPI actualizado antes del código.
- [ ] Autorización negativa por rol.
- [ ] Activación escrita en `audit_log`.
- [ ] Migración reversible.
- [ ] Instrumentación: estado de licencia en `health` y disponible para `doctor`.
- [ ] Textos de aviso en español e inglés, y **redactados para el cliente**: qué está degradado, desde cuándo, qué hacer.
- [ ] Revisión de `seguridad-cumplimiento` cerrada sin hallazgos bloqueantes.
- [ ] ADR-018 y ADR-019 coherentes con lo implementado.

---

### Tarea 5.4 — Instalador, Compose de producción, comprobación de requisitos, generación de secretos

| | |
|---|---|
| **Horas** | 12–16 |
| **Agente / Skill** | `producto-licencia` + `devops-observabilidad` |
| **Requisitos** | `RF-PD-02` (doc 02 §11). Contribuye a `RQ-11` (etapa 8 de la CI, §10.1) y a `RS-08`/§7.7 (secretos) |
| **Precondiciones** | `5.1` (§11.3: `5.1 └─► 5.4→5.5→5.7`) |
| **Bloquea a** | `5.5` y, a través de ella, `5.7` (§11.3) |

**Objetivo.** El IT del cliente ejecuta un script en un servidor virgen y, sin conocer Laravel, obtiene el sistema en marcha con secretos generados localmente y verificado. Si el servidor no cumple los requisitos, el script lo dice **antes de tocar nada** y deja la máquina como estaba.

**Reglas duras aplicables.**

- **13 — Nada específico de un cliente.** El instalador es idéntico para todos; lo que cambia son las respuestas del `.env` y la configuración.
- **3 — `APP_TIMEZONE=UTC` siempre** (Anexo B). El instalador no ofrece cambiarlo: la zona de presentación va por centro.
- **15 — La instalación no puede exigir una licencia válida para completarse.** Un sistema recién instalado tiene que poder fichar.
- **6 — Auditoría.** El primer usuario administrador que cree el asistente (5.5) se audita; el instalador no crea usuarios por su cuenta.

**Principio que gobierna la tarea** (§3.5, «Scripts de instalación y operación»): *un instalador que falla a medias es peor que uno que no arranca*. De ahí el orden estricto: **comprobar todo → decidir → actuar**.

**Pasos.**

1. **`infra/compose.prod.yaml`**: Compose autocontenido y sin dependencias externas (§11.6.1) con los servicios de producción del §3.4: `nginx`, `app` (PHP 8.4-FPM), `postgres` 17 con WAL archiving, `redis` 7 con AOF, `horizon`, `scheduler`, `reverb`. Imágenes con **etiqueta de versión inmutable** desde el registro privado del fabricante: **nada de `latest` en producción** (§11.6.1). Cabeceras de seguridad del §7.2 en la configuración de Nginx, incluida `Permissions-Policy: camera=(self)`, sin la cual el quiosco no puede escanear.
2. **`.env.example` comentado** (§11.6.1: «con los valores que el cliente debe rellenar») a partir del Anexo B, marcando tres categorías: lo que rellena el cliente (`APP_URL`, `MAIL_*`, `BACKUP_PATH`), lo que **genera el instalador** y lo que no se toca (`APP_TIMEZONE=UTC`).
3. **Cabecera del script** con propósito, uso y **códigos de salida documentados** (§3.5, errores). Convenciones obligatorias: `set -euo pipefail`, `IFS=$'\n\t'`, guía de Shell de Google, formato `shfmt -i 2`.
4. **Fase 1 del script — comprobación de requisitos, sin escribir nada**, contra la tabla publicada del §11.6.2: Docker 24+ y Compose v2, CPU, RAM, espacio en disco, puertos libres, permisos de escritura en las rutas de datos y de copias, y resolución de `APP_URL`. Cada fallo produce **un mensaje que dice qué hacer**, no solo qué falta.
5. **Fase 2 — decisión y aviso de idempotencia.** Si detecta una instalación previa (contenedores, volúmenes o `.env`), **no reinstala**: informa del estado, indica que para actualizar se usa `update.sh` y termina con su código de salida propio. Re-ejecutar `install.sh` sobre una instalación buena no puede romperla (§3.5, idempotencia).
6. **Fase 3 — generación de secretos en el servidor del cliente**, y **nunca transmitidos** (§7.7, RS-08, doc 05 §10.2 punto 1): `APP_KEY`, `QR_SIGNING_KEY_CURRENT` (32 bytes base64) con su `QR_SIGNING_KEY_CURRENT_ID`, credenciales de PostgreSQL, secretos de Reverb (`REVERB_APP_ID`/`KEY`/`SECRET`) y `BACKUP_ENCRYPTION_KEY`. Permisos restrictivos en `.env`. **Ningún secreto se imprime por pantalla ni se escribe en el log del script** (§3.5, secretos), con la única excepción que haya que documentar: si el cliente debe custodiar `BACKUP_ENCRYPTION_KEY` fuera del servidor, el procedimiento va en `docs/cliente/operacion.md` y en `rotacion-secretos.md`, no en la salida del script.
7. **Fase 4 — arranque y esquema**: levantar servicios, esperar a que PostgreSQL y Redis estén listos por **condición, no por `sleep`**, aplicar migraciones y sembrar únicamente lo imprescindible: el perfil `ES-hosteleria` (5.2) y el catálogo de motivos de corrección (doc 01 Anexo C). **Cero datos de demostración.**
8. **Fase 5 — verificación posinstalación**: `php artisan product:doctor` (5.9) y sonda de `GET /api/v1/health`. El instalador **no se declara correcto sin verificar**.
9. **Fallo seguro en cualquier fase**: si algo falla, deshacer lo hecho en esta ejecución y devolver la máquina al estado previo, indicando qué se ha deshecho y qué corregir (§3.5, fallo seguro).
10. **Salida final accionable**: URL del panel, siguiente paso (el asistente de 5.5), y dónde está la documentación.
11. **No se escribe `install.ps1`** ([ADR-022](../docs/adr/ADR-022-sin-instalador-de-windows.md), contradicción **C-2** resuelta). El §11.6.1 del doc 02 ya no lo lista. El paquete entrega `install.sh` y nada más, y la documentación de instalación (5.11) **enuncia el requisito de Linux con Docker sin ambigüedad**, en lugar de dejar que el IT lo deduzca de la ausencia de un fichero. Un cliente con solo infraestructura Windows instala sobre máquina virtual Linux, y eso se dice antes de que empiece, no a mitad.

**Artefactos.**

```
infra/compose.prod.yaml
infra/docker/{php,nginx,postgres}/
infra/scripts/install.sh            # entregable del producto (§11.6.1)
.env.example
docs/cliente/instalacion.md          # se completa en 5.11
.github/workflows/ci.yml             # etapa 8: instalación limpia (§10.1)
```

**Pruebas exigidas.** El §9.5 no tiene fila para «script de operación»; las que aplican son las del §9.2 y el escenario ineludible del §9.4:

| Nivel | Qué se prueba | Etiquetas |
|---|---|---|
| Estilo y estático | **ShellCheck: 0 hallazgos** y `shfmt -i 2 -d` sin diferencias (§9.2, umbral bloqueante) | — |
| Instalación (§9.4) | **Instalación limpia desde cero** en máquina virgen, siguiendo solo la guía escrita, con `doctor` en verde al terminar. Etapa 8 de la CI (§10.1, RQ-11) | `@RF-PD-02`, `@RQ-11` |
| Idempotencia | Ejecutar `install.sh` dos veces seguidas: la segunda no rompe nada y sale con su código documentado | `@RF-PD-02` |
| Fallo seguro | Requisito no cumplido a propósito (poco disco, puerto ocupado, Docker ausente) → sale antes de escribir, con mensaje accionable, y la máquina queda intacta | `@RF-PD-02` |
| Seguridad | Semgrep: ningún secreto en el script; ningún secreto en su salida ni en su log | — |
| Contenedores | Trivy sobre las imágenes de `compose.prod.yaml`: 0 CVE críticos (§9.2) | — |

**Verificación.**

```bash
shellcheck infra/scripts/install.sh                 # 0 hallazgos
shfmt -i 2 -d infra/scripts/install.sh              # sin diferencias
./install.sh                                        # en VM virgen
./install.sh                                        # segunda vez: idempotente
docker compose -f compose.prod.yaml ps              # todos los servicios sanos
php artisan product:doctor                          # verde
curl -fsS https://<APP_URL>/api/v1/health
```

**Terminado cuando.** Subconjunto aplicable de la DoD del §10.3:

- [ ] ShellCheck y `shfmt -i 2 -d` en verde.
- [ ] Instalación limpia verde en la etapa 8 de la CI (RQ-11).
- [ ] Idempotencia y fallo seguro probados.
- [ ] Ningún secreto en el repositorio, en el script ni en su salida (§7.7).
- [ ] Códigos de salida documentados en la cabecera.
- [ ] Mensajes de error que dicen qué hacer, en español e inglés.
- [ ] `docs/cliente/instalacion.md` refleja el procedimiento real y se completa en 5.11.
- [ ] Runbook `actualizacion-cliente.md` referenciado desde el aviso de instalación previa detectada.

---

### Tarea 5.5 — Asistente de puesta en marcha, incluida la importación masiva de plantilla

| | |
|---|---|
| **Horas** | 11–16 |
| **Agente / Skill** | `producto-licencia` + `frontend-panel` |
| **Requisitos** | `RF-PD-03`, **`RF-GP-05`** (doc 02 §11 · Anexo A del doc 01) |
| **Precondiciones** | `5.4` (§11.3: `5.4→5.5→5.7`). Necesita también `5.1` (configuración) y, para el paso de licencia, `5.3` |
| **Bloquea a** | `5.7` (§11.3) |

**Objetivo.** Tras la instalación, quien abre el panel por primera vez es guiado por un asistente que deja el sistema **listo para fichar**: organización, el centro y su zona horaria, departamentos, perfil de convenio, primer administrador y primer quiosco vinculado.

**Reglas duras aplicables.**

- **3 — UTC en almacenamiento.** El paso de zona horaria configura la **zona del centro** (`sites.timezone`, por defecto `Europe/Madrid`, doc 01 §5), no la de la aplicación.
- **12 — El producto no depende del correo del empleado.** El asistente no puede exigir correo para crear el primer administrador de forma que dependa de entregabilidad; y desde luego no envía invitaciones a empleados (regla dura 11).
- **6 — Auditoría.** La creación del primer administrador, del centro y la activación de licencia se auditan.
- **13 — Nada específico de un cliente.** Lo que el asistente recoge son **datos**; ninguna respuesta genera código ni una variante del producto.
- **18 — Policy y autorización negativa** en los endpoints que use el asistente.

**Pasos.**

1. **Los siete pasos son los de RF-PD-03**, literalmente: datos de la organización, el centro y su zona horaria (`CreateSiteHandler`, que solo admite uno: ADR-040), departamentos, perfil de convenio, primer administrador y vinculación del primer quiosco. Ni uno más: cada paso extra es una barrera entre el cliente y su primer fichaje.
2. **Detección de instalación no inicializada**: si no hay usuarios ni centro (`InstallationSiteProvider` devuelve `null`), el panel redirige al asistente; una vez completado, el asistente **no vuelve a estar accesible** (es de un solo uso, como el emparejamiento de 5.6).
3. **Primer administrador con 2FA obligatorio** (RS-06, tarea 2.1): el asistente lo configura en el momento, mostrando el secreto TOTP una sola vez. No es una credencial de empleado (regla dura 11): es un usuario de gestión.
4. **Paso de perfil de convenio**: se ofrece `ES-hosteleria` preseleccionado (5.2) con sus umbrales visibles y la advertencia de que hay que contrastarlos con el convenio aplicable (RL-21).
5. **Paso de licencia**: activación de la clave (5.3), **omitible**. Un asistente que exija licencia para terminar convierte la licencia en requisito de arranque, y eso choca con la regla dura 15.
6. **Paso de primer quiosco**: integra el flujo de 5.6. Si no hay tablet disponible, el paso es **omitible** y el procedimiento queda apuntado en `alta-nuevo-quiosco.md`.
7. **Reanudable y sin callejones sin salida**: el asistente guarda lo hecho paso a paso, se puede abandonar y retomar, y ningún paso permite quedar en un estado del que no se pueda salir sin consola.
8. **Resumen final accionable**: qué queda por hacer antes del primer día (emitir e imprimir tarjetas **con antelación**, doc 05 §10.2 y §5.5 del doc 02), con enlace a `credentials:status --pending` y al panel de estado de credenciales (RF-QR-08).
9. **Implementar la carga de plantilla como paso 4 del asistente** (`RF-GP-05`, contradicción **C-1** resuelta: el requisito se movió aquí desde la tarea 3.10). Es lo que el doc 05 §10.2 promete al cliente, y sin ella el asistente obliga a teclear a mano la plantilla de un hotel. Alcance:
    - **Dos fases:** validación que produce un informe línea a línea —qué se crearía, qué se actualizaría, qué se rechaza y por qué— y aplicación, que solo ocurre tras confirmación explícita. Es el **modo simulación** que exige RF-GP-05 y que el Anexo B marca en el endpoint.
    - **`POST /api/v1/employees/import`** (Anexo B: `[rol: rrhh, modo simulación]`), con el contrato actualizado antes del código.
    - Lectura en **streaming** con `spatie/simple-excel` (§3.1): un fichero de plantilla completo no se carga en memoria.
    - Validaciones: `employee_code` opaco y único —`CITEXT UNIQUE` en el esquema, doc 01 §5.5, así que **la base de datos es la que impide el duplicado** y no una consulta previa—, departamento y centro existentes, fechas coherentes, **correo opcional** (regla dura 12: el importador no puede exigir columna de correo ni fallar si falta) y `national_id_hash` calculado, nunca el DNI en claro (RL-08).
    - **Reimportar el mismo fichero no duplica ni pisa historial** (regla dura 5).
    - **No manda credenciales por correo** (regla dura 11, ADR-014): deja las tarjetas pendientes de imprimir y de entregar, y el panel de estado de credenciales (RF-QR-08) es quien dice quién no puede fichar todavía. Enlazar con el runbook `alta-nuevo-empleado.md`, porque importar 40 personas sin emitir sus tarjetas reproduce exactamente el problema que ese panel existe para evitar.
    - El **mapa de columnas del CSV es configuración**, no una variante por cliente (regla dura 13), y se documenta en `docs/cliente/configuracion.md`.
    - El informe de validación **no vuelca nombres** a `error_events` ni al log técnico (regla dura 21).
    - Alta masiva auditada (regla dura 6).
10. **Accesibilidad y textos**: el asistente es la primera pantalla del producto. Textos en `i18n` ES/EN y verificación con `axe` (§9.2: 0 violaciones críticas o graves).

**Artefactos.**

```
frontend-admin/src/features/settings/       # o features/onboarding/, según la estructura por feature del §3.5
backend/app/Modules/Product/Http/           # endpoints del asistente
backend/app/Modules/Workforce/Http/         # sites, departments (CRUD del Anexo B)
docs/api/openapi.yaml
docs/cliente/instalacion.md                  # el asistente, paso a paso, con capturas (5.11)
```

**Pruebas exigidas.** Filas «Expone o modifica un **endpoint**» y «Tiene **recorrido de usuario**» del §9.5:

| Nivel | Qué se prueba | Etiquetas |
|---|---|---|
| Feature + Contrato | Cada endpoint del asistente contra `openapi.yaml`; que deja de estar disponible una vez completado | `->group('RF-PD-03')` |
| Autorización negativa | El asistente no es accesible con la instalación ya inicializada, y no permite crear un segundo administrador sin autenticación | `->group('RF-PD-03')` |
| Integración | Creación del centro con `timezone` y `compliance_profile_id`, y rechazo de un segundo (`sites_single_row_uidx`); auditoría del primer administrador | `->group('RF-PD-03', 'RL-04')` |
| E2E (Playwright) | **Recorrido completo en instalación limpia**: desde el panel vacío hasta poder fichar, con el paso de licencia y el de quiosco omitidos y luego completados | `@RF-PD-03` |
| Accesibilidad | `axe` sobre cada paso: 0 violaciones críticas o graves | `@RQ-04` |

**Verificación.**

```bash
make e2e -- --grep @RF-PD-03
php artisan test --group=RF-PD-03
php artisan qa:traceability --check
```

Resultado esperado: en una instalación limpia, una persona que no conoce el sistema llega desde el panel vacío hasta un fichaje real **sin abrir una consola**.

**Terminado cuando.** Subconjunto aplicable de la DoD del §10.3:

- [ ] Pruebas de feature, contrato, autorización negativa y E2E.
- [ ] Accesibilidad verificada en las pantallas nuevas.
- [ ] Contrato OpenAPI actualizado.
- [ ] Auditoría de la creación de administrador, del centro y activación de licencia.
- [ ] Textos en español e inglés.
- [ ] Nada específico de un cliente en el código.
- [ ] `docs/cliente/instalacion.md` incluye el asistente con **capturas de lo que debe verse** (5.11).
- [ ] Decisión escrita sobre C-1 (carga de plantilla).

---

### Tarea 5.6 — Vinculación de quiosco por código de emparejamiento

| | |
|---|---|
| **Horas** | 5–7 |
| **Agente / Skill** | `frontend-quiosco` + `backend-laravel` |
| **Requisitos** | `RF-PD-06` (doc 02 §11) |
| **Precondiciones** | El §11.3 no sitúa esta tarea. Determinado por los documentos: necesita `devices` y los tokens de dispositivo de la tarea 1.5, y el panel de gestión de la 2.1. Se integra en el paso 7 del asistente (5.5) |
| **Bloquea a** | Ninguna tarea según §11.3. El paso de primer quiosco de 5.5 depende de ella si no se omite |

**Objetivo.** Vincular una tablet nueva es introducir un código de emparejamiento de un solo uso. **El cliente no tiene por qué usar SSH** (RF-PD-06), y el procedimiento completo vive en el runbook `alta-nuevo-quiosco.md`.

**Reglas duras aplicables.**

- **19 — El quiosco nunca bloquea al empleado.** Una vinculación fallida o caducada no puede dejar la tablet en un estado del que no se salga; y una vez vinculada, un problema de red no la desvincula.
- **17 — Rechazos genéricos y de tiempo constante.** `POST /api/v1/kiosk/pair` es **público** (Anexo B): un código inválido, caducado o ya usado devuelve la misma respuesta y consume el mismo tiempo. Es el mismo criterio que RS-03 aplica al escaneo.
- **10 y 21 — Nada de PII ni identificadores secuenciales**, ni en el código de emparejamiento ni en los logs de la operación.
- **6 — Auditoría.** La vinculación y la desvinculación de un dispositivo se auditan con actor y momento.
- **18 — Policy y autorización negativa** en el lado del panel; en el lado público, `throttle` y un solo uso.

**Pasos.**

1. **El flujo, en tres pasos** (contradicción **C-3** resuelta: no había contradicción, faltaba escribir el flujo completo). RF-PD-06 dice «código mostrado en la tablet e introducido en el panel»; el Anexo B define `/kiosk/pair` como público y de un solo uso. Ambos encajan si el endpoint es *la tablet pide emparejarse*:

   | Paso | Quién | Qué ocurre |
   |:---:|---|---|
   | 1 | Tablet sin vincular | Llama a `POST /api/v1/kiosk/pair` —**público, un solo uso**— y recibe un código corto de vida breve. Crea una solicitud de emparejamiento pendiente, sin dispositivo ni centro asignados |
   | 2 | Tablet | **Muestra el código en pantalla**, legible desde lejos (RF-PD-06) |
   | 3 | Administrador | Lo teclea en el panel, que llama a `POST /api/v1/kiosk/pair/confirm` —`[rol: admin]`— y **vincula el dispositivo al centro emitiendo su token** con los ámbitos del §7.3 (`scan:write`, `roster:read`, `heartbeat:write`) |

   La tablet obtiene su token al confirmarse la solicitud. **`php artisan kiosk:pairing-code` se conserva como vía alternativa de consola**, no como flujo principal: es coherente con el «el cliente no tiene por qué usar SSH» de RF-PD-06, que declara la consola opcional y no obligatoria. Registrado en las notas de contrato del Anexo B del [doc 01](../docs/01-especificaciones-proyecto.md).

2. **Contrato**: `POST /api/v1/kiosk/pair` y `POST /api/v1/kiosk/pair/confirm` en `openapi.yaml` antes del código (ADR-013). El primero marcado público, con `throttle` de borde (§7.1) y **respuesta genérica en caso de fallo** — un código inválido, caducado o ya usado deben ser indistinguibles desde fuera, por la misma razón que los rechazos de escaneo (regla dura 17).
3. **Modelo del código**: de un solo uso, con caducidad corta, ligado al centro de la instalación (`site_id`, que es siempre el mismo: ADR-040), almacenado **hasheado** —igual que `devices.token_hash` y `credentials.secret_hash` del doc 01 §5—, y consumido en una transacción que impide el doble uso bajo concurrencia.
4. **Emisión desde el panel y desde consola**: pantalla en `frontend-admin/src/features/devices/` y comando `php artisan kiosk:pairing-code` (Anexo C), para el caso en el que el panel no esté accesible.
5. **Resultado de la vinculación**: se crea o actualiza la fila de `devices` (`site_id`, `name`, `token_hash`, `app_version`, `status`) y el quiosco recibe su token con los ámbitos mínimos del §7.3 (`scan:write`, `roster:read`, `heartbeat:write`, 90 días, rotación automática al 80 % de vida). Un token de quiosco comprometido no da acceso a la plantilla completa (RS-04).
6. **Pantalla de emparejamiento en la PWA** (`frontend-kiosk/src/features/pairing/`), legible desde lejos y usable con la tablet ya fijada en modo quiosco (§11.6.2). Accesible sin salir de la aplicación.
7. **Desvinculación**: al desvincular, **purgar el padrón cacheado del dispositivo** (doc 01 §8.1, mitigación de «filtración del padrón cacheado»: «purga al desvincular el dispositivo»).
8. **Runbook `alta-nuevo-quiosco.md`** (§12): el procedimiento completo, incluida la parte que **no es del producto** —fijar el dispositivo en modo quiosco, que es responsabilidad del IT del cliente (§11.6.2, glosario del doc 01 §13)—, arranque automático de la PWA, brillo y suspensión, y ventana de actualizaciones del sistema.

**Artefactos.**

```
backend/app/Modules/Kiosk/{Domain,Application,Infrastructure,Http}/
backend/app/Modules/Kiosk/Console/          # kiosk:pairing-code
frontend-kiosk/src/features/pairing/
frontend-admin/src/features/devices/
docs/api/openapi.yaml
docs/runbooks/alta-nuevo-quiosco.md
docs/cliente/instalacion.md                  # el procedimiento con capturas (5.11)
```

**Pruebas exigidas.** Filas «Expone o modifica un **endpoint**» y «Tiene **recorrido de usuario**» del §9.5. No es una escritura del quiosco en el sentido de fichaje, pero comparte dos exigencias de ese camino:

| Nivel | Qué se prueba | Etiquetas |
|---|---|---|
| Integración | Código de un solo uso bajo **concurrencia**: dos peticiones simultáneas con el mismo código → un solo dispositivo vinculado | `->group('RF-PD-06')` |
| Feature + Contrato | `POST /api/v1/kiosk/pair` contra `openapi.yaml`; código inválido, caducado y ya usado devuelven **la misma respuesta genérica** | `->group('RF-PD-06', 'RS-03')` |
| Feature | Tiempo constante entre los tres tipos de rechazo | `->group('RS-03')` |
| Autorización negativa | La emisión del código exige rol autorizado; un token de quiosco no puede emitir códigos ni leer `devices` | `->group('RF-PD-06')` |
| E2E | Vinculación completa en la PWA y primer fichaje con el token recién obtenido | `@RF-PD-06` |
| Integración | Desvincular purga el padrón cacheado | `->group('RF-PD-06', 'RL-12')` |

**Verificación.**

```bash
php artisan kiosk:pairing-code 1
php artisan test --group=RF-PD-06
make e2e -- --grep @RF-PD-06
php artisan kiosk:health
```

**Terminado cuando.** Subconjunto aplicable de la DoD del §10.3:

- [ ] Contrato OpenAPI actualizado antes del código y **decisión sobre C-3 escrita**.
- [ ] Feature, contrato, autorización negativa, integración concurrente y E2E.
- [ ] Rechazos genéricos y de tiempo constante probados.
- [ ] Vinculación y desvinculación en `audit_log`.
- [ ] Textos del quiosco en español e inglés, legibles a distancia y accesibles.
- [ ] Runbook `alta-nuevo-quiosco.md` escrito, incluido lo que corresponde al cliente (modo quiosco).

---

### Tarea 5.7 — Actualizador: copia previa, migraciones encadenadas, verificación, vuelta atrás

| | |
|---|---|
| **Horas** | 15–20 |
| **Agente / Skill** | `producto-licencia` + `/migracion-segura` |
| **Requisitos** | `RF-PD-10` (doc 02 §11). Contribuye a `RQ-11` (§10.1, etapa 8) |
| **Precondiciones** | `5.5` (§11.3: `5.4→5.5→5.7`). Necesita las copias verificadas de la tarea 2.11 |
| **Bloquea a** | Ninguna tarea posterior según §11.3. Es el final de la cadena de despliegue |

**Objetivo.** El IT del cliente ejecuta un script y su instalación pasa de la versión que tenga a la última, **encadenando las versiones intermedias**, con copia previa verificada como paso bloqueante y vuelta atrás automática si algo falla. Y el fichaje no se detiene.

> **Es la tarea con más riesgo de pérdida de datos de todo el plan.** El §11.2 lo dice del recorte de esta tarea: *«Actualizar veinte clientes a mano, cada uno en una versión distinta, con datos de nómina de por medio. Es el recorte con más probabilidad de acabar en pérdida de datos de un cliente»*. Y el principio del agente `producto-licencia`: *una actualización sin vuelta atrás no es una actualización*.

**Reglas duras aplicables.**

- **5 — Nada se borra ni se sobrescribe.** Una migración no puede destruir versiones anteriores de un registro corregido.
- **6 — `audit_log` es intocable.** La skill `/migracion-segura` lo remata: si una migración necesita alterar esa tabla, es una decisión de arquitectura que se consulta con `arquitecto-dominio` y `seguridad-cumplimiento` **antes** de escribirla.
- **7 — `daily_totals` es reconstruible.** Si una migración la toca, la recuperación es `attendance:reconcile`, no un `UPDATE` acumulativo.
- **15 — La licencia no bloquea.** Una licencia caducada **no puede impedir actualizar**: dejaría al cliente sin correcciones de seguridad sobre su registro legal.
- **19 — El quiosco nunca bloquea al empleado.** El modo mantenimiento del paso 3 solo es tolerable porque el quiosco encola.
- **1 y 3 — Dominio puro y UTC.** Ninguna migración introduce `TIMESTAMP` sin zona (skill `/migracion-segura`, reglas de tipos).

**Pasos.** Los **siete pasos literales del §11.6.4**, desarrollados. El desarrollo completo, con detalle de vuelta atrás e informe, está en [`08-entrega-despliegue-y-actualizacion.md`](08-entrega-despliegue-y-actualizacion.md) §3.

```
1. Verificar precondiciones: espacio, versión de origen soportada, servicios sanos
2. Copia de seguridad completa y verificada  ← bloqueante, sin esto no continúa
3. Modo mantenimiento (el quiosco sigue encolando offline)
4. Aplicar migraciones en orden de versión, con punto de control entre cada una
5. Arrancar y ejecutar la comprobación de salud
6. Si algo falla → vuelta atrás automática a la copia previa
7. Informe del resultado, guardado en el servidor del cliente
```

1. **Paso 1 — precondiciones, antes de tocar nada.** Espacio libre suficiente para la copia **y** para la migración; versión de origen dentro de la matriz de versiones soportadas (§11.6.5); servicios sanos (`product:doctor`). Si la versión de origen no está soportada, el script **no intenta el salto**: dice a qué versión intermedia hay que ir primero.
2. **Paso 2 — copia previa verificada, bloqueante.** No basta con generar la copia: hay que **verificarla** (`backup:run && backup:verify`, Anexo C; RF-PR-04). RF-PD-10 es explícito: *«si la copia falla, la actualización no continúa»*. Aquí no hay excepción ni bandera para omitirlo.
3. **Paso 3 — modo mantenimiento.** La API de gestión responde con mantenimiento; **la cola offline del quiosco sigue aceptando fichajes** (ADR-008). Es la ventaja inesperada del §11.6.4: convierte una parada en algo invisible para la plantilla. Documentar que los fichajes de la ventana llegan con `occurred_at` anterior a `recorded_at` y que eso es correcto (regla dura 9).
4. **Paso 4 — migraciones en orden de versión con punto de control entre cada una.** El actualizador **no asume el salto directo**: de 1.2.0 a 1.6.0 aplica 1.3 → 1.4 → 1.5 → 1.6, y entre cada versión deja un punto de control que permite saber exactamente dónde se detuvo. Cada migración cumple expand/contract (§10.4) y las especificidades de PostgreSQL de la skill: `CREATE INDEX CONCURRENTLY` fuera de transacción, `NOT NULL` en dos pasos con `NOT VALID` + `VALIDATE`, `lock_timeout` bajo. **Si alguna migración desactiva las restricciones de RN-01 o RN-02, el plan debe indicar cómo se restauran y cómo se verifica que ningún dato las viola al reactivarlas** (skill `/migracion-segura`).
5. **Paso 5 — arrancar y comprobar salud** con `product:doctor` (5.9) y la sonda `health`. Sin comprobación posterior no hay actualización terminada.
6. **Paso 6 — vuelta atrás automática.** Si la comprobación falla, se restaura la copia previa **sin intervención humana** y se informa. Es RF-PD-10 literal: *«vuelta atrás automática a la copia previa si la comprobación falla»*. La vuelta atrás se prueba, no se supone: *una migración cuyo `down()` no se ha probado no tiene `down()`*.
7. **Paso 7 — informe guardado en el servidor del cliente**: versión de origen y de destino, migraciones aplicadas, duración, resultado de la comprobación y, si hubo vuelta atrás, en qué punto y por qué. Es el documento que el cliente adjunta al paquete de diagnóstico si necesita soporte, y lo que evita la segunda ronda de preguntas.
8. **Convenciones de script** (§3.5): `set -euo pipefail`, `IFS=$'\n\t'`, `shfmt -i 2`, ShellCheck sin hallazgos, idempotencia (re-ejecutar sobre una instalación ya actualizada no hace nada y lo dice), fallo seguro, códigos de salida en la cabecera y **cero secretos** en el script o su salida.
9. **Matriz de versiones soportadas** implementada como dato, no como suposición: la versión menor vigente y las dos anteriores (§11.6.5).
10. **Runbook `actualizacion-cliente.md`** (§12): procedimiento y vuelta atrás, para cuando el script no basta.

**Artefactos.**

```
infra/scripts/update.sh              # entregable del producto (§11.6.1)
infra/scripts/backup.sh, restore.sh  # invocados por update.sh; su contenido viene de 2.11
backend/database/migrations/         # todas, revisadas contra expand/contract
docs/runbooks/actualizacion-cliente.md
docs/runbooks/restaurar-backup.md
docs/cliente/operacion.md            # se completa en 5.11
.github/workflows/ci.yml             # etapa 8: actualización desde cada versión soportada
```

**Pruebas exigidas.** Fila «Toca el **esquema o una restricción** de base de datos» del §9.5, más los escenarios ineludibles del §9.4:

| Nivel | Qué se prueba | Etiquetas |
|---|---|---|
| Integración | Cada migración con volumen realista (`VolumeSeeder`, no 10 filas) y **tiempo anotado**; `down()` ejecutado y verificado | `->group('RF-PD-10')` |
| Integración | **Invariantes de base de datos** tras la actualización: intento por SQL directo de crear un solape o un segundo turno abierto sigue siendo rechazado (§9.4) | `->group('RN-01', 'RN-02', 'RF-PD-10')` |
| Instalación (§9.4) | **Actualización desde cada versión soportada**, con verificación posterior. Etapa 8 de la CI (§10.1, RQ-11) | `@RF-PD-10`, `@RQ-11` |
| Instalación | **Salto no consecutivo**: de la más antigua soportada a la actual, encadenando intermedias | `@RF-PD-10` |
| Instalación | **Vuelta atrás**: fallo inyectado en la comprobación de salud → se restaura la copia y los conteos coinciden con los previos | `@RF-PD-10` |
| Instalación (§9.4) | **Restauración de copia**: restaurar la última copia en un contenedor limpio y validar integridad referencial y conteos | `@RF-PR-04`, `@RQ-09` |
| Integración | La cadena de auditoría sigue verificándose tras la actualización | `->group('RL-04', 'RS-07')` |
| Feature | Con licencia caducada, la actualización se completa igual | `->group('RF-PD-05', 'RF-PD-10')` |
| Estilo | ShellCheck 0 hallazgos y `shfmt -i 2 -d` sin diferencias | — |

**Verificación.**

```bash
shellcheck infra/scripts/update.sh && shfmt -i 2 -d infra/scripts/update.sh
php artisan db:seed --class=VolumeSeeder && time php artisan migrate
php artisan migrate:rollback && php artisan migrate
php artisan test --filter=DatabaseConstraints
./update.sh                                  # desde 1.2.0 hasta la actual, en CI
./update.sh                                  # segunda vez: no hace nada y lo dice
php artisan compliance:verify-audit-chain
php artisan attendance:reconcile --from= --to=
```

Resultado esperado: la actualización desde la versión más antigua soportada termina en verde; con un fallo inyectado, el sistema vuelve exactamente al estado previo; y en ambos casos hay informe en el servidor del cliente.

**Terminado cuando.** Subconjunto aplicable de la DoD del §10.3:

- [ ] **Migración reversible y verificada con datos de volumen realista** (es el punto crítico de esta tarea).
- [ ] Etapa 8 de la CI verde: instalación limpia **y** actualización desde cada versión soportada (RQ-11).
- [ ] Salto no consecutivo y vuelta atrás probados en CI.
- [ ] ShellCheck y `shfmt` en verde; códigos de salida documentados; cero secretos.
- [ ] Cadena de auditoría verificada después de actualizar.
- [ ] Informe de resultado generado y documentado.
- [ ] Runbooks `actualizacion-cliente.md` y `restaurar-backup.md` escritos.
- [ ] `docs/cliente/operacion.md` explica el procedimiento y qué hacer si falla cada paso.
- [ ] Lista de comprobación de entrega de la skill `/migracion-segura` completa para cada migración tocada.

---

### Tarea 5.8 — Marca blanca en las tres aplicaciones y en los PDF

| | |
|---|---|
| **Horas** | 12–18 |
| **Agente / Skill** | `producto-licencia` + los tres agentes de frontend |
| **Requisitos** | `RF-PD-08` (doc 02 §11). Prioridad **S** en el doc 01 §3.9 |
| **Precondiciones** | El §11.3 no sitúa esta tarea. Determinado por los documentos: necesita `5.1` (`BrandingProvider` y `installation_settings`), la PWA de 1.8, el panel de 2.4/2.5, el portal de 1.11 y los PDF de 1.10 y 2.9 |
| **Bloquea a** | Ninguna |

**Objetivo.** Logotipo, colores y nombre de la aplicación son configuración, y se aplican al quiosco, al panel, al portal y a las tarjetas y documentos PDF. **Lo que se personaliza es lo que se muestra en pantalla; los identificadores técnicos internos no se tocan.**

**Reglas duras aplicables.**

- **13 — Nada específico de un cliente en el código.** El logotipo es un fichero en una ruta configurada (`BRANDING_LOGO_PATH`), nunca un asset dentro del repositorio con el nombre de un hotel.
- **10 — El payload QR es `FH1.<key_id>.<token>.<sig>`.** El prefijo `FH1` **no se renombra**. El doc 02 lo dice en su nota de nomenclatura: *«renombrar el prefijo `FH1` invalidaría credenciales ya impresas»*. Renombrarlo en una actualización dejaría a la plantilla de un cliente sin poder fichar con las tarjetas que tiene en el bolsillo.
- **11 — La credencial es una tarjeta física.** La marca en la tarjeta es diseño del PDF, no una modalidad nueva de credencial.

**Qué se personaliza y qué no.** Del doc 02 (nota de nomenclatura) y del doc 01 §13:

| Se personaliza (es marca, RF-PD-08) | **No** se renombra (identificador técnico interno) |
|---|---|
| Nombre de la aplicación en pantalla (`BRANDING_APP_NAME`) | Prefijo `FH1` del payload QR |
| Logotipo (`BRANDING_LOGO_PATH`), aplicado a quiosco, panel, portal y PDF | `fichaje-hotel` como nombre del proyecto y del árbol |
| Colores de la interfaz | `BACKUP_PATH`, nombres de base de datos y de servicios de Compose |
| Cabecera y pie de tarjetas de credencial e informes sellados | Nombres de tablas, columnas, comandos, rutas de API |
| Título del documento y metadatos de los PDF | Etiquetas de métricas y claves de configuración |

**Pasos.**

1. **Implementar `BrandingProvider`** (puerto de 5.1) resolviendo por cascada `site` → `installation` → valor por defecto. **Definir la precedencia entre las variables `BRANDING_*` del Anexo B y las filas de `installation_settings`** — ningún documento la fija; ver [Puntos no cubiertos](#puntos-no-cubiertos-por-los-documentos).
2. **Endpoint o payload de marca** para los tres frontends, servido sin autenticación en lo estrictamente necesario (el quiosco y el portal necesitan la marca antes de identificar a nadie) y **sin filtrar ninguna otra configuración**.
3. **Colores como tokens de Tailwind 4** resueltos en tiempo de ejecución, no en tiempo de compilación: recompilar por cliente sería una variante del producto, y eso choca con ADR-017. Respetar el presupuesto del Anexo A (JS crítico ≤ 250 KB, CSS ≤ 40 KB).
4. **Quiosco** (`frontend-kiosk/`): logotipo y nombre en la pantalla de espera y en la de confirmación, con contraste suficiente para leerse a distancia. El logotipo debe estar **en la caché del service worker**: sin red, la marca sigue apareciendo.
5. **Panel** (`frontend-admin/`) y **portal** (`frontend-portal/`): cabecera, favicon, título de la pestaña y pantalla de acceso.
6. **PDF** (`spatie/laravel-pdf`, §3.1): tarjetas de credencial (RF-QR-04..06, tarea 1.10), impresión masiva en A4, informes sellados y la exportación legal para Inspección (RF-IN-05, RL-06). **La marca no puede desplazar ni reducir el QR**: el nivel de corrección de errores Q y la legibilidad a 20 cm son requisito (§5.1), y una tarjeta rediseñada que no se lee es un fallo de fichaje diario.
7. **Validación del logotipo al subirlo**: formato, tamaño y dimensiones. Un logotipo de 8 MB en la pantalla del quiosco rompe el presupuesto de rendimiento.
8. **Valores por defecto seguros**: sin configuración, se muestra el nombre y la marca del producto. La mayoría de los clientes no cambiará nada, así que el valor por defecto **es** el producto.
9. **Contraste y accesibilidad**: si los colores elegidos por el cliente no alcanzan el contraste mínimo, se avisa. `axe` con 0 violaciones críticas o graves (§9.2).

**Artefactos.**

```
backend/app/Modules/Product/Infrastructure/Adapter/    # BrandingProvider
backend/app/Modules/Product/Http/                      # endpoint de marca
frontend-kiosk/src/shared/                             # tema y logotipo, cacheado en el SW
frontend-admin/src/shared/
frontend-portal/src/shared/
backend/resources/views/pdf/                           # tarjetas e informes
docs/cliente/configuracion.md                          # cómo se cambia la marca (5.11)
```

**Pruebas exigidas.** Filas «Expone o modifica un **endpoint**», «Tiene **recorrido de usuario**» y «Genera un **informe o exportación**» del §9.5:

| Nivel | Qué se prueba | Etiquetas |
|---|---|---|
| Unitaria (front) | Vitest: el tema se aplica desde la configuración recibida; con configuración ausente se usa el valor por defecto | `RF-PD-08` |
| Feature + Contrato | Endpoint de marca contra `openapi.yaml`; no filtra configuración ajena a la marca | `->group('RF-PD-08')` |
| Autorización negativa | Cambiar la marca exige `admin`; el quiosco y el portal solo pueden **leerla** | `->group('RF-PD-08')` |
| Integración | Generación de tarjeta con marca: el QR conserva su tamaño y su nivel de corrección Q | `->group('RF-PD-08', 'RF-QR-04')` |
| E2E | Marca visible en las tres aplicaciones; **QR degradado** sigue leyéndose con el vídeo de prueba (§9.4) | `@RF-PD-08`, `@RQ-04` |
| E2E | El quiosco **sin red** muestra la marca (logotipo en caché del service worker) | `@RF-PD-08`, `@RF-KI-03` |
| Accesibilidad | `axe`: 0 violaciones críticas o graves con la marca aplicada | `@RQ-04` |
| Rendimiento | Presupuesto de bundle del Anexo A respetado (etapa 6 de la CI) | — |

**Verificación.**

```bash
npm run test -w frontend-kiosk && npm run test -w frontend-admin && npm run test -w frontend-portal
php artisan test --group=RF-PD-08
php artisan credentials:print 1        # inspeccionar el PDF: QR intacto, marca aplicada
make e2e -- --grep @RF-PD-08
```

**Terminado cuando.** Subconjunto aplicable de la DoD del §10.3:

- [ ] `vue-tsc` sin errores y sin `any` en el código nuevo.
- [ ] Pruebas unitarias de front, feature, contrato, autorización negativa, integración de PDF y E2E.
- [ ] Accesibilidad verificada en las pantallas nuevas; contraste avisado si no cumple.
- [ ] Presupuesto de rendimiento del Anexo A respetado.
- [ ] **Ningún identificador técnico interno renombrado**; el prefijo `FH1` intacto.
- [ ] Textos en español e inglés.
- [ ] Nada específico de un cliente en el código: ni un asset, ni un color, ni un nombre.
- [ ] `docs/cliente/configuracion.md` explica cómo cambiar la marca, con ejemplo y con los límites del logotipo.

---

### Tarea 5.9 — Paquete de diagnóstico anonimizado, comando `doctor`, accesos de soporte auditados

| | |
|---|---|
| **Horas** | 12–16 |
| **Agente / Skill** | `producto-licencia`, revisión de `seguridad-cumplimiento` |
| **Requisitos** | `RF-PD-09`, `RF-PD-11`, `RF-PD-13` (doc 02 §11). Del Anexo A del doc 01, también `RL-18` y `RL-19` |
| **Precondiciones** | El §11.3 no sitúa esta tarea. Determinado por los documentos: `product:doctor` lo invoca el instalador (5.4) y el actualizador (5.7), así que una versión mínima del comando debe existir antes de cerrar 5.4; el volcado de `error_events` al paquete depende de 5.12 (§11.6.6) |
| **Bloquea a** | La verificación posinstalación de 5.4 y la comprobación de salud de 5.7 usan `product:doctor` |

**Objetivo.** El cliente diagnostica solo con `doctor` y, cuando no puede, genera un paquete **anonimizado por defecto** que basta para resolver sin una segunda ronda de preguntas. Y si hace falta acceso directo, se concede de forma expresa, temporal, limitada, revocable y auditada.

**Reglas duras aplicables.**

- **16 — El fabricante no accede a los datos del cliente** salvo concesión expresa, temporal y auditada; el paquete va anonimizado por defecto (ADR-020). Es la regla que gobierna la tarea.
- **21 — Nunca nombres de empleados en logs técnicos ni en `error_events`.** El doc 02 §8.2.1 lo remata: *«aquí importa más porque esta tabla se envía al fabricante dentro del paquete de diagnóstico»*. **Si el paquete lleva PII, se ha filtrado.**
- **15 — La licencia no bloquea.** `doctor` y el paquete de diagnóstico deben funcionar con licencia caducada: son justamente lo que se necesita cuando algo va mal.
- **6 — Auditoría.** Conceder, usar y revocar un acceso de soporte se audita, y la auditoría es **visible para el cliente** (RF-PD-11).
- **18 — Policy y autorización negativa** en `POST /api/v1/diagnostics/bundle`, `POST /api/v1/support/grants` y `DELETE /api/v1/support/grants/{id}` (Anexo B: `[rol: admin]`; ámbitos `support:*` y `diagnostics:*` del §7.3).

**Pasos.**

1. **`php artisan product:doctor`** (Anexo C, RF-PD-13) valida lo que enumera el requisito: base de datos, colas, correo, certificados, permisos y espacio en disco. Y devuelve un **informe accionable**: cada comprobación en rojo dice qué hacer, no solo qué falló. Códigos de salida claros, porque lo invocan `install.sh` y `update.sh`.
2. **`doctor.sh`** (§11.6.1) como envoltorio ejecutable sin entrar al contenedor, para el caso en el que la aplicación no arranca y `artisan` no está disponible. Convenciones del §3.5 completas.
3. **Contenido del paquete**, exactamente el del §11.6.6: versión, configuración **sin secretos**, estado de los servicios, **el histórico de `error_events` del periodo con su agrupación por huella y su `trace_id`** (RF-PD-15, tarea 5.12), salud de quioscos, tamaño de las colas, resultado de `doctor` y métricas agregadas. Añadir el estado de licencia (5.3) y el informe de la última actualización (5.7), porque son las dos primeras preguntas de cualquier incidencia.
4. **Anonimización por defecto, verificada, no confiada**: identificadores de empleado sustituidos por sus **UUID**; sin nombres, sin correos, sin registros de jornada (§11.6.6, RL-19). La anonimización se implementa como **lista de permitidos**, no como lista de exclusiones: lo que no está explícitamente permitido no entra al paquete. Una lista de exclusiones falla en silencio cada vez que se añade un campo nuevo.
5. **Filtro de secretos**: `.env` filtrado por lista de permitidos, no por patrón. `LICENSE_KEY`, `QR_SIGNING_KEY_*`, `BACKUP_ENCRYPTION_KEY`, `REVERB_APP_SECRET`, credenciales de base de datos: fuera. Semgrep vigila el código; una prueba vigila la salida.
6. **Incluir datos personales es una acción distinta** (§11.6.6, RL-19): bandera explícita, aviso en la interfaz que explica qué se va a incluir y qué implica, y **entrada en `audit_log`**. Nunca es el valor por defecto ni un efecto secundario de otra opción.
7. **`php artisan product:diagnostics --anonymized`** (Anexo C) y `POST /api/v1/diagnostics/bundle` (Anexo B), para que el administrador pueda generarlo **con un clic** como promete el doc 05 §10.6.
8. **Accesos de soporte (RF-PD-11)**: tabla `support_grants` con los campos del doc 01 §5 (`granted_by_user_id`, `reason`, `scope`, `granted_at`, `expires_at`, `revoked_at`, `accessed_at`). Comandos `support:grant --hours=24 --reason=` y `support:revoke` (Anexo C), endpoints `POST /api/v1/support/grants` y `DELETE /api/v1/support/grants/{id}`. **Caducidad efectiva**: al expirar, el acceso deja de funcionar sin que nadie haga nada. `reason` obligatorio. Cada acceso efectivo actualiza `accessed_at` y escribe en `audit_log`, y el cliente lo ve en el panel.
9. **`scope` de la concesión**: el campo existe en el modelo pero **ningún documento enumera sus valores** — ver [Puntos no cubiertos](#puntos-no-cubiertos-por-los-documentos).
10. **Revisión de `seguridad-cumplimiento`**, exigida por el doc 02 §11, con la skill `/revision-cumplimiento`: bloques de privacidad, producto licenciado y auditoría. Pregunta obligatoria: *si tomo un paquete generado en una instalación real con 500 empleados, ¿puedo identificar a alguien?*
11. **Runbook `incidencia-sin-acceso.md`** (§12): cómo diagnostica el fabricante con el paquete que envía el cliente. Es el runbook que decide si el paquete está bien diseñado.
12. **Documentar el reparto de roles legales** en `obligaciones-legales.md` (5.11): RL-16 a RL-18, y en particular que el acceso de soporte convierte al fabricante en encargado del tratamiento **para ese supuesto concreto**, lo que exige contrato de encargo del art. 28 RGPD.

**Artefactos.**

```
backend/app/Modules/Product/
├── Application/UseCase/            # GenerateDiagnosticsBundleHandler, GrantSupportAccessHandler
├── Infrastructure/                 # recolectores, anonimizador, filtro de secretos
├── Http/                           # DiagnosticsController, SupportGrantController, Policies
└── Console/                        # product:doctor, product:diagnostics, support:grant, support:revoke
backend/database/migrations/        # create_support_grants_table
infra/scripts/doctor.sh
frontend-admin/src/features/settings/    # generar paquete, conceder y revocar soporte
docs/runbooks/incidencia-sin-acceso.md
docs/cliente/operacion.md, docs/cliente/obligaciones-legales.md    # 5.11
```

**Pruebas exigidas.** Filas «Expone o modifica un **endpoint**» y «Genera un **informe o exportación**» del §9.5 —el paquete es una exportación—, más autorización negativa:

| Nivel | Qué se prueba | Etiquetas |
|---|---|---|
| Unitaria | Anonimizador: dada una estructura con nombres, correos, DNI y horas de fichaje, la salida **no contiene ninguno**; los identificadores son UUID | `->group('RF-PD-09', 'RL-19')` |
| Unitaria | Filtro de secretos: ninguna clave del Anexo B marcada como secreta aparece en la salida | `->group('RF-PD-09', 'RS-08')` |
| Unitaria | Caducidad de la concesión con `Clock` inyectado: en `expires_at` exacto y un segundo después | `->group('RF-PD-11')` |
| Integración | Paquete generado **con volumen realista** (§9.5, «✅ con volumen»): 500 empleados, 90 días de fichajes, `error_events` poblado. Se inspecciona el contenido completo | `->group('RF-PD-09')` |
| Feature + Contrato | `POST /api/v1/diagnostics/bundle`, `POST`/`DELETE /api/v1/support/grants` contra `openapi.yaml` | `->group('RF-PD-09', 'RF-PD-11')` |
| Autorización negativa | 403 por cada rol distinto de `admin`, incluidos `auditor` y token de quiosco | `->group('RF-PD-09', 'RF-PD-11')` |
| Integración | Conceder, usar y revocar dejan rastro en `audit_log` **visible para el cliente**; incluir datos personales queda auditado como acción distinta | `->group('RF-PD-11', 'RL-19', 'RL-04')` |
| Feature | Con licencia caducada, `doctor` y el paquete funcionan | `->group('RF-PD-05')` |
| Estilo | ShellCheck y `shfmt -i 2 -d` sobre `doctor.sh` | — |

**Verificación.**

```bash
php artisan product:doctor                       # informe accionable, códigos de salida claros
./doctor.sh                                      # funciona con la app caída
php artisan product:diagnostics --anonymized
# Inspección manual obligatoria del paquete: buscar nombres, correos y horas. Debe salir vacío.
php artisan support:grant --hours=24 --reason="Incidencia #123"
php artisan support:revoke
php artisan test --group=RF-PD-09 --group=RF-PD-11
```

**Terminado cuando.** Subconjunto aplicable de la DoD del §10.3:

- [ ] Pruebas en los niveles de la tabla, incluida la de volumen.
- [ ] **Inspección manual de un paquete generado sobre datos realistas, sin una sola PII.**
- [ ] Autorización negativa por rol.
- [ ] Concesión, uso, revocación e inclusión explícita de datos personales en `audit_log`.
- [ ] ShellCheck y `shfmt` sobre `doctor.sh`; códigos de salida documentados.
- [ ] Textos e informe de `doctor` en español e inglés, **redactados para quien no conoce el sistema**.
- [ ] Revisión de `seguridad-cumplimiento` cerrada sin hallazgos bloqueantes.
- [ ] Runbook `incidencia-sin-acceso.md` escrito y probado contra un paquete real.
- [ ] ADR-020 coherente con lo implementado.

---

### Tarea 5.10 — Exportación íntegra de datos y telemetría opcional desactivada por defecto

| | |
|---|---|
| **Horas** | 5–8 |
| **Agente / Skill** | `producto-licencia` |
| **Requisitos** | `RF-PD-12`, `RF-PD-14` (doc 02 §11). Del Anexo A del doc 01, también `RL-20` |
| **Precondiciones** | El §11.3 no sitúa esta tarea. Determinado por los documentos: la exportación reutiliza el trabajo de 2.9 (exportaciones y exportación legal); la telemetría lee configuración de 5.1 y estado de licencia de 5.3 |
| **Bloquea a** | Ninguna |

**Objetivo.** El cliente puede llevarse **todos** sus datos en formato abierto, cuando quiera y sin pedir permiso; y la telemetría existe, viene apagada, y el sistema funciona idénticamente sin ella.

**Reglas duras aplicables.**

- **15 y ADR-019 — La exportación íntegra no puede depender de la licencia.** RL-20 lo dice sin ambigüedad: es la garantía del cliente *«para seguir cumpliendo su obligación de conservación aunque la relación comercial termine»*. Una exportación bloqueada por licencia caducada sería exactamente lo que ADR-019 prohíbe.
- **16 — El fabricante no accede a los datos.** La telemetría envía «versión y métricas técnicas agregadas, **jamás datos personales ni de jornada**» (RF-PD-12).
- **21 — Sin PII en lo que sale de la instalación.**
- **6 — Auditoría.** Una exportación íntegra es un acceso masivo a datos personales: se audita (RS-05).
- **5 — Nada se sobrescribe.** La exportación incluye las versiones anteriores de los registros corregidos, con autor y motivo: la skill `/informe-nuevo` es explícita —*un informe que oculte las correcciones no cumple*—, y aquí aplica igual.

**Pasos.**

1. **`php artisan product:export-all`** (Anexo C, RF-PD-14) en **formato abierto**: CSV o XLSX con `spatie/simple-excel` en modo streaming (§3.1: «no carga en memoria un mes de 500 empleados»), más JSON para lo estructurado, y un `README` en el propio paquete que explique cada fichero y cada columna. Una exportación que nadie sabe leer no cumple RL-20.
2. **Alcance «íntegro»**: plantilla, contratos, centros, departamentos, credenciales (sin secretos), tramos con todas sus versiones, totales diarios, correcciones con autor y motivo, incidencias, ausencias, `scan_events`, `audit_log` y configuración. **Los secretos no se exportan**; los hashes tampoco tienen por qué salir.
3. **Ejecutable por el propio cliente sin intervención del fabricante** (RF-PD-14): comando y también botón en el panel, con rol `admin`.
4. **Rendimiento verificado sobre volumen realista** (§9.5, «✅ con volumen»): 500 empleados y cuatro años de retención es el caso que hay que soportar sin agotar memoria.
5. **Auditar la exportación** con actor, momento y alcance.
6. **Telemetría (RF-PD-12)**: `TELEMETRY_ENABLED=false` en el Anexo B, y ese es el valor por defecto **del producto**. Cuando se activa, envía versión y métricas técnicas agregadas. El sistema funciona **idénticamente** sin ella: sin degradación, sin avisos, sin recordatorios insistentes.
7. **Aislamiento del fallo**: con la telemetría activada y sin salida a internet (escenario normal según el §11.6.2), el envío falla en silencio, con reintento acotado, y **no afecta a nada**. Nunca en el camino de una petición de fichaje.
8. **Contenido de la telemetría documentado campo a campo** en `docs/cliente/configuracion.md`: el cliente tiene que poder decidir con la lista delante. **Su destino y su protocolo no están definidos en ningún documento** — ver [Puntos no cubiertos](#puntos-no-cubiertos-por-los-documentos).

**Artefactos.**

```
backend/app/Modules/Product/Console/           # product:export-all
backend/app/Modules/Product/Infrastructure/    # exportador en streaming, cliente de telemetría
backend/app/Modules/Reporting/                 # reutilización de 2.9
frontend-admin/src/features/settings/          # botón de exportación íntegra
docs/cliente/configuracion.md, docs/cliente/obligaciones-legales.md   # 5.11
```

**Pruebas exigidas.** Fila «Genera un **informe o exportación**» del §9.5:

| Nivel | Qué se prueba | Etiquetas |
|---|---|---|
| Unitaria | Selección de campos: los secretos no entran; las correcciones salen con autor y motivo | `->group('RF-PD-14', 'RL-20')` |
| Integración | Exportación con **volumen realista** sin agotar memoria; los conteos por fichero cuadran con la base de datos | `->group('RF-PD-14')` |
| Feature + Contrato | Endpoint de exportación contra `openapi.yaml`; auditoría del acceso | `->group('RF-PD-14', 'RS-05')` |
| Autorización negativa | 403 por cada rol distinto de `admin` | `->group('RF-PD-14')` |
| Feature | **Con licencia caducada, la exportación íntegra funciona** | `->group('RF-PD-05', 'RL-20')` |
| Unitaria | Telemetría: con `TELEMETRY_ENABLED=false` no se construye ni se envía nada; con `true`, la carga útil no contiene PII ni datos de jornada | `->group('RF-PD-12')` |
| Feature | Telemetría activada y destino inalcanzable: el sistema responde igual y no hay error visible para el usuario | `->group('RF-PD-12')` |

**Verificación.**

```bash
php artisan product:export-all                  # sobre la semilla de volumen
# Inspección: conteos por fichero contra la base de datos; sin secretos; correcciones presentes
php artisan test --group=RF-PD-14 --group=RF-PD-12
php artisan qa:traceability --check
```

**Terminado cuando.** Subconjunto aplicable de la DoD del §10.3:

- [ ] Pruebas unitarias, de integración con volumen, feature, contrato y autorización negativa.
- [ ] **Prueba de que la exportación íntegra funciona con licencia caducada.**
- [ ] Exportación auditada.
- [ ] `TELEMETRY_ENABLED=false` por defecto y contenido documentado campo a campo.
- [ ] Textos en español e inglés.
- [ ] `docs/cliente/obligaciones-legales.md` recoge RL-20 y qué hace el cliente con la exportación.

---

### Tarea 5.11 — Documentación de instalación, operación, configuración y obligaciones legales

| | |
|---|---|
| **Horas** | 10–15 |
| **Agente / Skill** | `producto-licencia` |
| **Requisitos** | `RL-21` (doc 02 §11). Del Anexo A del doc 01, la fase incluye `RL-16..21`, y esta tarea es donde aterrizan todos |
| **Precondiciones** | El §11.3 no sitúa esta tarea. Determinado por los documentos: documenta lo que producen 5.1 a 5.10 y 5.12, así que se **cierra** al final, aunque se escribe en paralelo a cada tarea |
| **Bloquea a** | Ninguna tarea, pero **bloquea la venta**: sin ella no se cumple RF-PD-02 («el IT del cliente despliega el sistema siguiendo una guía, sin intervención del fabricante») |

> **La tarea más subestimada es la 5.11.** Una documentación de instalación mediocre se paga en horas de soporte con cada cliente, indefinidamente. Con veinte instalaciones, es la diferencia entre un producto rentable y una consultora encubierta.
> — [doc 02](../docs/02-stack-tecnologico-y-plan-implementacion.md) §11, Fase 5

**Objetivo.** Existen los cuatro documentos de `docs/cliente/` del §11.6.1, escritos para **personal de IT competente que no conoce este sistema, probablemente con prisa y probablemente con un problema**.

**Reglas duras aplicables.**

- **13 — Nada específico de un cliente.** Los ejemplos usan valores genéricos; ninguna guía se «adapta» a un cliente concreto.
- **15 — La documentación no puede prometer lo que ADR-019 prohíbe.** El apartado de licencia debe decir con claridad que la caducidad **no** detiene el fichaje ni el acceso al registro, igual que se le prometió en el doc 05 §4.7 y §10.5.
- **16 — Sin secretos en la documentación.** Ni claves de ejemplo que parezcan reales, ni salidas de comando con secretos dentro.
- **21 — Ninguna captura con nombres de empleados reales.** Las capturas se hacen sobre la instalación de referencia con datos de demostración (§10.2).
- **11 y 20 — Coherencia de producto.** La documentación no puede sugerir credencial en móvil ni biometría: no existen.

**Qué lleva cada documento.**

**`docs/cliente/instalacion.md`** — *«Para el IT del cliente»* (§11.6.1). El documento contra el que se mide el criterio de éxito de la fase.

1. Requisitos de servidor, tabla del §11.6.2, y qué se pierde exactamente **sin salida a internet** (§11.6.2: solo Let's Encrypt, SMTP externo y telemetría).
2. Requisitos por punto de fichaje: tablet Android 10+ con cámara trasera con autoenfoque, soporte, cobertura wifi y **modo quiosco**, con la aclaración de que el modo quiosco lo configura el IT del cliente y **no es funcionalidad del producto** (§11.6.2).
3. Descarga del paquete y qué contiene (árbol del §11.6.1).
4. Ejecución de `install.sh`, con **comandos completos y copiables** y la salida esperada.
5. Qué hacer si la comprobación de requisitos falla, caso por caso, con el código de salida.
6. Asistente de puesta en marcha, paso a paso, **con capturas de lo que debe verse**.
7. Vinculación del primer quiosco, con capturas.
8. Certificados: Let's Encrypt o certificado propio del cliente (§3.4).
9. **`KIOSK_VLAN_CIDR`**, con la advertencia que el §7.1 exige por escrito: es el rango desde el que la zona de fichaje admite **600 r/m con ráfaga de 50**, y **si los quioscos quedan fuera de ese rango caen a 30 r/m**. El fallo es **silencioso** —se manifiesta como «el quiosco va lento a las 06:00», no como error— y por eso tiene que estar en la guía y no solo en el `.env.example`.
10. Verificación final: `doctor` en verde, `health` responde, un fichaje de prueba.
11. **«Qué hacer si…» para cada fallo previsible**: Docker ausente o antiguo, puerto ocupado, disco insuficiente, certificado no válido, el quiosco no accede a la cámara (§7.2: `Permissions-Policy: camera=(self)`, «un fallo de configuración que se diagnostica mal y cuesta horas»), la tablet no encuentra el servidor, el código de emparejamiento no funciona.

**`docs/cliente/operacion.md`** — *«Copias, actualizaciones, incidencias comunes»* (§11.6.1).

1. Rutina diaria y semanal: qué mirar y dónde.
2. Copias: qué copia `backup.sh`, dónde (`BACKUP_PATH`), cómo se cifran, cómo se **verifican** y dónde custodiar `BACKUP_ENCRYPTION_KEY` (RF-PR-04, §7.7).
3. **Restauración**, con simulacro trimestral (RQ-09) y remisión a `restaurar-backup.md`.
4. Actualización: los siete pasos del §11.6.4 en lenguaje de operador, qué esperar en pantalla, cuánto dura, qué pasa con el quiosco durante la ventana, y qué hacer si vuelve atrás.
5. Diagnóstico: `doctor`, la pantalla de errores del panel (5.12) y cómo generar y enviar el paquete de diagnóstico.
6. Acceso de soporte: cómo se concede, con qué caducidad, cómo se revoca y dónde se ve lo que se hizo.
7. **«Qué hacer si…»**: un quiosco no responde, la cola de un quiosco crece, la copia falló, no se puede fichar, el panel no carga, un empleado no aparece, la actualización falló. Cada entrada remite al runbook del §12 correspondiente.
8. Qué **no** debe hacerse nunca: modificar datos por SQL directo, borrar filas de `audit_log` (el usuario de aplicación no puede, y por qué), tocar `daily_totals` a mano (es reconstruible: `attendance:reconcile`).

**`docs/cliente/configuracion.md`** — *«Todos los parámetros y qué hace cada uno»* (§11.6.1).

1. Las dos capas y su relación: variables de entorno del **Anexo B** y configuración con ámbito en el panel (`installation_settings`, 5.1), con la precedencia entre ambas.
2. Tabla por parámetro: nombre, qué hace, valor por defecto, valores admitidos, **cuándo hace falta cambiarlo** y si su cambio afecta al cálculo de horas.
3. Perfil de cumplimiento: cada umbral, de dónde sale el valor del perfil `ES-hosteleria`, y la advertencia de contrastarlo con el convenio aplicable. Incluye la **decisión de retroactividad** de 5.2.
4. Marca blanca: cómo cambiar nombre, logotipo y colores; límites del logotipo; y qué **no** se personaliza (identificadores técnicos internos, prefijo `FH1`).
5. Idiomas y funcionalidades activas.
6. Telemetría: qué se enviaría, campo a campo, y que viene desactivada.
7. Lo que **no se toca**: `APP_TIMEZONE=UTC` (regla dura 3) y por qué; la zona horaria se configura **por centro**.
8. Umbrales operativos del Anexo B (`ATTENDANCE_DEBOUNCE_SECONDS`, `ATTENDANCE_MAX_SHIFT_HOURS`, `ATTENDANCE_MAX_CLOCK_SKEW_MINUTES`, `PIN_MAX_ATTEMPTS`, `PORTAL_INTERNAL_CIDR`), con la advertencia de RF-AT-10: el desfase de reloj **genera incidencia, nunca rechaza el fichaje**.

**`docs/cliente/obligaciones-legales.md`** — *«Qué le corresponde al cliente (RL-21)»*.

1. **El cliente es responsable del tratamiento** (RL-16) y qué implica: registro de actividades de tratamiento, información a la plantilla y a su representación legal (arts. 20.3 ET y 87-91 LOPDGDD, doc 01 §7.4), evaluación de impacto si procede (RL-13), custodia y copia de los datos.
2. **El fabricante no es encargado del tratamiento** en operación ordinaria (RL-17), y **sí lo es, de forma acotada**, durante una intervención de soporte autorizada (RL-18), lo que exige contrato de encargo del art. 28 RGPD limitado a soporte.
3. El paquete de diagnóstico no lleva datos personales por defecto, e incluirlos es decisión explícita del cliente, avisada y auditada (RL-19).
4. Continuidad: cómo ejercer la exportación íntegra (RL-20) y para qué sirve.
5. Registro horario: qué obliga la ley y qué hace el producto — conservación **4 años** (RL-02, RL-11), acceso del trabajador a su propio registro (RL-05), exportación para Inspección (RL-06) con remisión a `requerimiento-inspeccion.md`.
6. Retención por tipo de dato (RL-11) y quién confirma la purga (RF-PR-03).
7. Derechos ARSULIPO y su procedimiento (RL-10), con remisión a `solicitud-derechos-rgpd.md`.
8. Brechas: procedimiento de 72 h (RL-15), con remisión a `brecha-de-seguridad.md`.
9. **Lo que el producto no hace**: sin biometría (ADR-009) y sin credencial en móvil (ADR-014), por decisión y con el motivo explicado.
10. Cierre explícito: **el producto facilita el cumplimiento; no lo sustituye** (RL-21). Y que la validación jurídica del marco recogido corresponde a la asesoría laboral del cliente, no al fabricante (doc 03 §4.3: el agente `seguridad-cumplimiento` «no da asesoramiento jurídico»).

**Pasos.**

1. Escribir cada documento **en paralelo a su tarea**, no al final: quien acaba de implementar el instalador es quien sabe qué falla.
2. **Comandos completos y copiables**, nunca fragmentos con puntos suspensivos ni rutas de ejemplo del entorno de desarrollo.
3. **Capturas de lo que debe verse** en cada paso, hechas sobre la instalación de referencia con datos de demostración (§10.2), sin PII.
4. **Una sección «qué hacer si…» por cada fallo previsible**, alineada con los códigos de salida de los scripts y con los runbooks del §12.
5. Validar por lectura: alguien que no ha participado en la tarea sigue la guía en una máquina limpia. Cada duda que le surja es un defecto de la guía, no del lector.
6. Español e inglés (§3.5, transversal).
7. Referencia cruzada única: cada fallo se explica **en un solo sitio** y los demás enlazan. Documentación duplicada es documentación que divergirá.

**Artefactos.**

```
docs/cliente/instalacion.md
docs/cliente/operacion.md
docs/cliente/configuracion.md
docs/cliente/obligaciones-legales.md
```

Los cuatro viajan **dentro del paquete de entrega** (`docs/` del árbol del §11.6.1), no en un portal externo: la instalación puede estar en una red sin internet.

**Pruebas exigidas.** El §9.5 no tiene fila para documentación. Lo que la verifica es el escenario ineludible «**Instalación y actualización**» del §9.4 y la etapa 8 de la CI:

| Verificación | Cómo |
|---|---|
| La guía es suficiente | **Instalación limpia en una máquina virgen siguiendo solo la guía escrita**, por alguien que no la escribió (criterio de terminado del doc 03 §6.5) |
| Los comandos funcionan | Extraídos y ejecutados en la etapa 8 de la CI, no copiados a mano |
| Las capturas corresponden a la versión | Revisión en cada versión menor; una captura desfasada desorienta más que su ausencia |
| No hay PII ni secretos | Revisión y Semgrep sobre los bloques de código de la documentación |
| Cada parámetro del Anexo B está documentado | Comprobación cruzada Anexo B ↔ `configuracion.md`; y cada clave nueva de `installation_settings` |
| Cada modo de fallo tiene su «qué hacer si…» | Comprobación cruzada códigos de salida de los scripts ↔ `instalacion.md` y `operacion.md` |

**Verificación.**

```bash
# En una VM virgen, con solo el paquete de entrega y docs/instalacion.md delante:
./install.sh && php artisan product:doctor
# Después, sin ayuda externa: completar el asistente, vincular un quiosco y fichar.
```

Resultado esperado: se llega al primer fichaje **sin preguntar nada a nadie**. Cualquier pregunta que haya que hacer es un defecto de esta tarea.

**Terminado cuando.** Subconjunto aplicable de la DoD del §10.3:

- [ ] Los cuatro documentos escritos, en español e inglés.
- [ ] Instalación limpia completada por alguien ajeno siguiendo **solo** la guía.
- [ ] Todos los comandos verificados en la etapa 8 de la CI.
- [ ] Capturas actualizadas, sin PII.
- [ ] «Qué hacer si…» completo frente a los códigos de salida de los cinco scripts.
- [ ] Cada parámetro del Anexo B y cada clave de `installation_settings` documentados.
- [ ] `obligaciones-legales.md` cubre `RL-16..21` uno a uno.
- [ ] Ningún secreto y ninguna clave de aspecto real en la documentación.
- [ ] Coherencia verificada con el doc 05 (§10.8 promete exactamente estos cuatro manuales).

---

### Tarea 5.11b — Guía de uso para RRHH y hoja de instrucciones del empleado

| | |
|---|---|
| **Horas** | 6–8 |
| **Agente / Skill** | `producto-licencia`, con apoyo de `frontend-panel` |
| **Requisitos** | `RL-05` (el acceso del trabajador a su registro, que un portal sin explicar cumple solo de forma formal), y la superficie de uso de `RF-PA-01..07`, `RF-IN-01..05` y `RF-PA-04` (correcciones trazadas) |
| **Precondiciones** | **5.11**, con la que comparte carpeta `docs/cliente/`, convenciones de redacción, criterio de capturas y exigencia de español e inglés |
| **Bloquea a** | Ninguna tarea. Bloquea la **puesta en marcha real**: sin ella, RRHH aprende el producto preguntando |

**Objetivo.** Existe una guía de uso del panel para RRHH, una guía del portal del empleado y una **hoja de una cara** con instrucciones básicas de fichaje que se entrega junto con la tarjeta.

> **Por qué falta y por qué hace falta.** Los cuatro documentos de la tarea 5.11 son **todos para el IT del cliente**: instalación, operación, configuración y obligaciones legales. Ninguna tarea del plan producía manual del empleado, guía del quiosco, **manual de uso del panel para RRHH** ni guía del portal. El empleado que acerca una tarjeta a una tablet no necesita manual —el producto está diseñado para eso—, pero **RRHH operando la bandeja de incidencias, las correcciones trazadas y la exportación para Inspección sí**, y es lo que sostiene RL-05 en la práctica: un portal cuyo acceso nadie explica cumple la obligación de forma solo formal.
>
> **No contradice el documento 05:** su §10.8 promete exactamente los cuatro manuales de la 5.11 y no promete formación. Es hueco de producto, no contradicción comercial — y por eso se cubre con horas propias en lugar de discutirse.

**Reglas duras aplicables.**

- **13 — Nada específico de un cliente.** Las guías se escriben sobre la instalación de referencia con la marca por defecto. Un cliente con marca blanca ve otros colores, no otro texto.
- **21 — Ninguna captura con nombres de empleados reales.** Datos de demostración (§10.2), igual que en la 5.11.
- **11 y 20 — Coherencia de producto.** Ninguna guía menciona credencial en móvil ni biometría: no existen. La hoja del empleado dice qué hacer si **pierde la tarjeta**, no cómo llevarla en el teléfono.
- **15 y ADR-028 — Nada de lo que se explica se bloquea por licencia.** La guía no puede decir «si la licencia caduca, no podrás…» sobre nada del registro legal.
- **17 y 12 — El PIN.** La guía del portal explica que el acceso es con **código de empleado y PIN** (ADR-015), que el PIN se entrega en mano y que **no hay recuperación por correo**: se pide restablecimiento a RRHH (tarea 1.13).

**Qué lleva cada documento.**

**`docs/cliente/guia-rrhh.md`** — *para quien opera el producto a diario, que no es IT.*

1. **Vocabulario primero**, con el glosario del doc 01 §13 en el idioma del negocio: jornada, tramo, incidencia, corrección, credencial. Sin él, el resto de la guía no se entiende.
2. Alta de un empleado de principio a fin: ficha, contrato, emisión de tarjeta (1.10) y **entrega del PIN** (1.13), con la advertencia de que el PIN se ve **una sola vez**.
3. Presencia en vivo (2.4) y detalle de jornada (2.5): qué significa cada estado y qué **no** significa.
4. **Bandeja de incidencias** (2.5, 2.6): qué genera cada tipo, cuál es urgente y cuál no, y cómo se resuelve. Explicación explícita de que **el sistema nunca cierra un turno por su cuenta** (RN-08) y de por qué eso es una garantía y no una carencia.
5. **Correcciones trazadas** (2.3): cuándo corregir, el catálogo de motivos del Anexo C del doc 01 con un ejemplo de cada uno, y que **el valor anterior se conserva siempre**. Es lo que hay que saber explicar ante una inspección.
6. Informes (2.8), exportaciones (2.9) y **exportación legal para Inspección** (RL-06): qué entregar, en qué formato y qué contiene.
7. Ausencias (3.10) y perfil de cumplimiento (5.2), con la advertencia de que cambiar un umbral cambia qué se considera incidencia.
8. **«Qué hacer si…» de gestión**, no de sistema: un empleado dice que su registro está mal, alguien olvidó fichar la salida, una tarjeta se pierde, un empleado pide su registro, llega un requerimiento de Inspección, un empleado causa baja.

**`docs/cliente/guia-portal-empleado.md`** — *para entregar o publicar internamente.*

1. Cómo entrar: código de empleado y PIN, desde dónde (`PORTAL_INTERNAL_CIDR`, Anexo B) y qué hacer si el PIN no funciona o está bloqueado (1.12).
2. Qué se ve: jornadas, tramos y totales, y qué significa cada uno.
3. Cómo descargar el propio registro (RL-05) y para qué sirve.
4. Qué hacer si algo no cuadra: **avisar a RRHH**, que corrige de forma trazada. El empleado no edita su registro, y la guía dice por qué.

**`docs/cliente/hoja-empleado.pdf`** — *una cara, para entregar con la tarjeta.*

1. Cómo fichar: acercar la tarjeta, esperar la confirmación en pantalla. Con imagen.
2. Qué significa cada confirmación, incluida la de **«fichaje registrado, pendiente de validar»** cuando el quiosco está sin red (regla dura 19): **está registrado**, no hay que repetirlo.
3. Qué hacer sin tarjeta: **PIN de respaldo** (1.12) y avisar al responsable.
4. Cómo consultar su registro: dirección del portal, código y PIN.
5. A quién avisar si algo va mal.
6. **Se entrega en el mismo acto que la tarjeta** (tarea 1.10, que registra la entrega). No es un envío aparte: es un único momento presencial junto con la tarjeta y el PIN.

**Pasos.**

1. Escribir sobre la instalación de referencia con datos de demostración (§10.2), con capturas reales y sin PII.
2. **Lenguaje de negocio, no de sistema.** El glosario del doc 01 §13 es el puente: la guía dice *jornada* y *tramo*, no `WorkDay` ni `ShiftEntry`.
3. Un recorrido completo por documento, de principio a fin, en vez de una referencia por pantalla. RRHH aprende haciendo un alta entera, no leyendo la descripción de un botón.
4. La hoja del empleado **cabe en una cara** y se lee de pie. Si necesita dos, sobra contenido.
5. Español e inglés (§3.5, transversal, misma exigencia que la 5.11). La hoja del empleado, además, en los idiomas activos de la instalación si el cliente los tiene configurados.
6. Referencia cruzada única con la 5.11: lo que ya está en `operacion.md` no se repite, se enlaza.
7. Validación por lectura: alguien de perfil no técnico completa un alta, resuelve una incidencia y genera la exportación legal **siguiendo solo la guía**.

**Artefactos.**

```
docs/cliente/guia-rrhh.md
docs/cliente/guia-portal-empleado.md
docs/cliente/hoja-empleado.md        # fuente; se genera el PDF de una cara
```

Los tres viajan **dentro del paquete de entrega**, como los cuatro de la 5.11: la instalación puede estar en una red sin internet.

**Pruebas exigidas.** El §9.5 no tiene fila para documentación, igual que en la 5.11:

| Verificación | Cómo |
|---|---|
| La guía de RRHH es suficiente | Alguien de perfil no técnico completa **alta con tarjeta y PIN, resolución de una incidencia y exportación legal** siguiendo solo la guía |
| La guía del portal es suficiente | Una persona entra al portal y descarga su registro sin ayuda |
| La hoja cabe en una cara | Comprobación del PDF generado |
| Las capturas corresponden a la versión | Revisión en cada versión menor, como en la 5.11 |
| No hay PII | Revisión de todas las capturas |
| Coherencia de producto | Ninguna guía menciona móvil, biometría ni recuperación de PIN por correo |

**Verificación.**

```bash
# Con la instalación de referencia levantada y solo las guías delante:
#  1. Alta de un empleado, emisión de tarjeta y entrega de PIN
#  2. Resolución de una incidencia de la bandeja
#  3. Exportación legal de un periodo
#  4. Entrada al portal con código y PIN, y descarga del propio registro
```

Resultado esperado: los cuatro recorridos se completan **sin preguntar nada a nadie**. Cada pregunta que haya que hacer es un defecto de esta tarea, no del lector.

**Terminado cuando.** Subconjunto aplicable de la DoD del §10.3:

- [ ] Los tres documentos escritos, en español e inglés.
- [ ] Los cuatro recorridos completados por alguien ajeno siguiendo **solo** las guías.
- [ ] La hoja del empleado cabe en una cara y está referenciada desde la tarea 1.10, que es donde se entrega.
- [ ] Capturas actualizadas, sin PII.
- [ ] El glosario del doc 01 §13 usado de forma coherente en los tres documentos.
- [ ] Ninguna mención a credencial en móvil, biometría ni recuperación de PIN por correo.

---

### Tarea 5.12 — Histórico de errores en base de datos

| | |
|---|---|
| **Horas** | 6–8 |
| **Agente / Skill** | `producto-licencia` + `backend-laravel` + `frontend-panel` |
| **Requisitos** | `RF-PD-15` (doc 02 §11) |
| **Precondiciones** | El §11.3 no sitúa esta tarea. Determinado por los documentos: el paquete de diagnóstico incluye «el histórico de `error_events` del periodo con su agrupación por huella y su `trace_id`» (§11.6.6), así que debe estar antes de cerrar 5.9 |
| **Bloquea a** | El contenido del paquete de diagnóstico de 5.9 (§11.6.6) |

**Objetivo.** Existe `error_events`: todo error de aplicación, de cola, de tarea programada o de los tres clientes queda persistido **agrupado por huella**, consultable desde el panel por el IT del cliente sin conocer el sistema, purgado a 90 días y volcado al paquete de diagnóstico. **Sin datos personales.**

**Por qué no es redundante con Loki** (§8.2.1, literal en lo esencial): Loki es opcional en la instalación de un cliente —puede desactivarlo, puede no tener quien lo mire, puede perderlo al reinstalar— y el fabricante no puede entrar a consultarlo (ADR-020). *«Si el único rastro de un error vive en un stack que el cliente quizá no conserve, la primera pregunta de cada incidencia será "¿puedes mirar los logs?" — y la respuesta será que no.»* `error_events` vive en la misma base de datos que se respalda a diario y viaja en el paquete de diagnóstico. Y aquí hay un motivo adicional de calendario: **el stack de observabilidad es la tarea 3.1 y todavía no existe** cuando se ejecuta la Fase 5.

**Por qué se agrupa por huella** (§8.2.1): *«Un fallo en el endpoint de fichaje durante un cambio de turno genera cientos de errores idénticos. Sin agrupación, la tabla se llena de ruido y el error importante queda enterrado.»* La huella es el **hash de clase de excepción, punto de fallo y mensaje normalizado —sin identificadores variables—**, y cada repetición **incrementa `occurrences` y actualiza `last_seen_at`**. Un fallo que se repite mil veces es una fila con `occurrences = 1000`, no mil filas (doc 01 §5).

**Reglas duras aplicables.**

- **21 — Nunca nombres de empleados en logs técnicos ni en `error_events`.** Se usa `employee_uuid`. *«El histórico de errores viaja al fabricante dentro del paquete de diagnóstico: si lleva PII, se ha filtrado.»* Es la regla central de esta tarea.
- **16 — El fabricante no accede a los datos del cliente.** Esta tabla es una de las que sale de la instalación.
- **15 — La licencia no bloquea.** El histórico de errores tiene que funcionar sobre todo cuando algo va mal.
- **6 — `audit_log` es otra cosa.** `error_events` no es auditoría y no la sustituye: distinta retención (90 días frente a 4 años), distinto propósito y distinto lector (§8.2.1).
- **18 — Policy y autorización negativa** en `GET /api/v1/diagnostics/errors` y `POST /api/v1/diagnostics/errors/{id}/resolve` (Anexo B: `[rol: admin]`).

**Qué NO puede contener** (§8.2.1 y doc 01 §5): **nombres, correos, DNI ni horas de fichaje de nadie.** El contexto se limita a `trace_id`, `employee_uuid`, `device_id` y datos técnicos.

**Pasos.**

1. **Migración de `error_events`** con los campos exactos del doc 01 §5: `id`, `fingerprint` (UNIQUE), `level` (`error`|`critical`), `source` (`api`|`worker`|`scheduler`|`console`|`kiosk`|`admin`|`portal`), `module`, `code`, `message`, `exception_class`, `file`, `line`, `context` (`JSONB`, **sin PII**), `trace_id`, `device_id` (NULL), `employee_uuid` (NULL, **nunca el nombre**), `app_version`, `occurrences`, `first_seen_at`, `last_seen_at`, `resolved_at`, `resolved_by_user_id`. `level` y `source` como `enum` de PHP (§3.5).
2. **Cálculo de la huella**: hash de clase de excepción + punto de fallo (`file`:`line`) + mensaje **normalizado**, eliminando identificadores variables (UUID, números, rutas, direcciones IP, marcas de tiempo). La normalización es la parte delicada: si deja pasar un identificador, la agrupación no agrupa; si normaliza de más, junta errores distintos.
3. **Escritura idempotente por huella**: `INSERT ... ON CONFLICT (fingerprint) DO UPDATE` que incrementa `occurrences` y actualiza `last_seen_at`. Debe ser correcto bajo concurrencia: cientos de errores simultáneos en un cambio de turno es el caso normal, no el excepcional.
4. **Saneado obligatorio antes de escribir**: el mensaje y el contexto pasan por un filtro que elimina PII y secretos, con **lista de permitidos** en `context`. Es el mismo criterio que el anonimizador de 5.9, y conviene que sea el mismo código.
5. **Captación desde los cuatro orígenes del servidor**: manejador de excepciones de la API, `failed` de los trabajos de cola (Horizon), errores del scheduler y de los comandos de consola. Con `trace_id` para poder correlacionar con el log técnico cuando el cliente sí tenga Loki.
6. **Captación desde los tres clientes** (`kiosk`, `admin`, `portal`): endpoint de recepción de errores de cliente con rate limiting, saneado en el servidor —nunca se confía en que el cliente sanee— y `app_version` del cliente para correlacionar con la versión desplegada (§10.5). **En el quiosco, reportar un error no puede bloquear ni retrasar un fichaje** (regla dura 19): el envío es asíncrono y descartable.
7. **Pantalla en el panel** (`frontend-admin/src/features/settings/` o `features/diagnostics/`): lista agrupada con filtros por **origen, severidad y periodo** (RF-PD-15), ordenada por `last_seen_at`, con `occurrences` visible y acción de **marcar como resuelto**. Escrita para el IT del cliente: *«que el cliente vea qué está fallando y desde cuándo, sin conocer el sistema»* (§8.2.1). Cada error muestra qué hacer o a qué runbook ir.
8. **Comandos** del Anexo C: `php artisan product:errors --since=24h --level=` y `php artisan product:errors:prune`, este último **en el scheduler**.
9. **Retención**: `ERROR_HISTORY_RETENTION_DAYS=90` (Anexo B), «igual que el log técnico (RL-11)». La purga es de datos técnicos, no del registro legal: no la confunde con RF-PR-03.
10. **Volcado al paquete de diagnóstico** (§11.6.6): el histórico del periodo con su agrupación y su `trace_id`. Conecta con 5.9.
11. **Métrica** `application_errors_total{source,level}` (§8.2), disponible para cuando llegue la Fase 3, y la alerta del doc 01 §9.3: «Errores nuevos de severidad crítica en `error_events`, cualquiera en 5 min, Alta (IT del cliente)». Toda alerta lleva runbook (§8.4).
12. **Runbook `errores-en-el-panel.md`** (§12): *«Cómo lee el IT del cliente el histórico de `error_events` y qué hacer con cada severidad»*.

**Artefactos.**

```
backend/app/Modules/Product/
├── Domain/                        # ErrorFingerprint, ErrorLevel, ErrorSource
├── Application/UseCase/           # RecordErrorEventHandler, ResolveErrorEventHandler
├── Infrastructure/                # sanitizador, escritura ON CONFLICT, captadores
├── Http/                          # ErrorEventController, Policy
└── Console/                       # product:errors, product:errors:prune
backend/app/Providers/             # enganche del manejador de excepciones
backend/database/migrations/       # create_error_events_table
frontend-admin/src/features/       # pantalla de errores
frontend-kiosk/src/features/diagnostics/    # reporte asíncrono y descartable
docs/api/openapi.yaml
docs/runbooks/errores-en-el-panel.md
docs/cliente/operacion.md          # cómo se usa (5.11)
```

**Pruebas exigidas.** Filas «Toca el **esquema**» y «Expone o modifica un **endpoint**» del §9.5:

| Nivel | Qué se prueba | Etiquetas |
|---|---|---|
| Unitaria | Huella: dos errores con distinto UUID, distinto número y distinta ruta en el mensaje producen **la misma** huella; dos errores de clases distintas producen huellas distintas | `->group('RF-PD-15')` |
| Unitaria | **Sanitizado**: un mensaje con nombre, correo, DNI y hora de fichaje sale sin ninguno de los cuatro | `->group('RF-PD-15')`, `->group('RL-19')` |
| Integración | Agrupación **bajo concurrencia**: 50 escrituras simultáneas de la misma huella → una fila con `occurrences = 50` | `->group('RF-PD-15')` |
| Integración | Purga a `ERROR_HISTORY_RETENTION_DAYS` días; no toca `audit_log` ni datos de jornada | `->group('RF-PD-15', 'RL-11')` |
| Feature + Contrato | `GET /api/v1/diagnostics/errors` con filtros de origen, severidad y periodo, y `POST /api/v1/diagnostics/errors/{id}/resolve`, contra `openapi.yaml` | `->group('RF-PD-15')` |
| Autorización negativa | 403 por cada rol distinto de `admin`; el endpoint de recepción de errores de cliente no permite consultar el histórico | `->group('RF-PD-15')` |
| Feature | El endpoint de recepción **sanea en servidor** aunque el cliente envíe PII | `->group('RF-PD-15', 'RL-19')` |
| E2E | El IT del cliente encuentra en el panel un error provocado a propósito, con su origen, su severidad y su recuento | `@RF-PD-15` |
| E2E | **Un fallo al reportar un error de cliente no bloquea ni retrasa un fichaje** en el quiosco | `@RF-PD-15`, `@RF-KI-03` |

**Verificación.**

```bash
php artisan test --group=RF-PD-15
php artisan product:errors --since=24h --level=critical
php artisan product:errors:prune
# Inspección obligatoria de la tabla tras un día de uso con la semilla realista:
# ni un nombre, ni un correo, ni una hora de fichaje.
php artisan product:diagnostics --anonymized   # el histórico va dentro y sigue sin PII
```

**Terminado cuando.** Subconjunto aplicable de la DoD del §10.3:

- [ ] Deptrac en verde; `Product/Domain/` sin framework.
- [ ] Pruebas en los niveles de la tabla, incluida la de concurrencia.
- [ ] **Inspección manual de la tabla sin una sola PII.**
- [ ] Contrato OpenAPI actualizado.
- [ ] Autorización negativa por rol.
- [ ] Migración reversible con `down()` probado.
- [ ] Instrumentación: `application_errors_total{source,level}` y la alerta del doc 01 §9.3 con su runbook.
- [ ] Purga en el scheduler, a 90 días por defecto.
- [ ] Textos de la pantalla en español e inglés, **legibles por quien no conoce el código**.
- [ ] Runbook `errores-en-el-panel.md` escrito.
- [ ] El histórico viaja en el paquete de diagnóstico de 5.9 con su agrupación y su `trace_id`.

---

## La suma de horas y por qué no es la suma

| # | Tarea | Horas |
|---|---|---|
| 5.1 | Módulo `Product`: configuración con ámbito, resolución en cascada, auditoría de cambios | 8–10 |
| 5.2 | Perfiles de cumplimiento; extraer RN-10/11/12 a parámetros; perfil `ES-hosteleria` | 10–12 |
| 5.3 | Licencia: emisión firmada, verificación local, límites y degradación honesta | 15–20 |
| 5.4 | Instalador, Compose de producción, comprobación de requisitos, generación de secretos | 12–16 |
| 5.5 | Asistente de puesta en marcha, **incluida la importación masiva de plantilla** | 11–16 |
| 5.6 | Vinculación de quiosco por código de emparejamiento | 5–7 |
| 5.7 | Actualizador: copia previa, migraciones encadenadas, verificación, vuelta atrás | 15–20 |
| 5.8 | Marca blanca en las tres aplicaciones y en los PDF | 12–18 |
| 5.9 | Paquete de diagnóstico anonimizado, comando `doctor`, accesos de soporte auditados | 12–16 |
| 5.10 | Exportación íntegra de datos y telemetría opcional desactivada por defecto | 5–8 |
| 5.11 | Documentación de instalación, operación, configuración y obligaciones legales | 10–15 |
| 5.11b | Guía de uso para RRHH, guía del portal y hoja de instrucciones del empleado | 6–8 |
| 5.12 | Histórico de errores en base de datos | 6–8 |
| | **Suma bruta** | **127–174 h** |
| | **Fase, con solapamiento aplicado** | **117–161 h** |

La nota del doc 02 §11, literal:

> *(Suma bruta 127–174 h; se aplica solapamiento realista entre 5.4, 5.5 y 5.7, que comparten andamiaje de despliegue.)*

Lo que comparten esas tres tareas y justifica el descuento: el `compose.prod.yaml`, la gestión del `.env` y de los secretos, la espera por condición a que los servicios estén sanos, la invocación de `product:doctor` y las convenciones de script del §3.5. Quien escribe `install.sh` deja hecho la mitad de `update.sh`.

**Qué son estas horas** (§11.0): horas de **una persona desarrollando con el andamiaje de agentes** del doc 03, no de desarrollo manual. Incluyen diseño, implementación, pruebas de los niveles del §9.5, documentación y **la revisión humana de lo que produce el agente**. No incluyen aprender el dominio, esperar decisiones del cliente ni las tres validaciones que el §11.0 excluye explícitamente (asesoría laboral, prueba de campo del hardware, contraste de costes de impresión).

**Y una advertencia que afecta especialmente a esta fase**: el §11.0 avisa de que *«la asistencia no acelera todo por igual»*, y cita entre lo que rinde bien la **marca blanca** (5.8) y entre lo que apenas se acelera la **documentación de cliente** (5.11), donde *«la estimación es prácticamente la manual»*. No conviene recortar las 10–15 h de la 5.11 —ni las 6–8 de la 5.11b— esperando que el andamiaje las comprima.

---

## Parámetros de configuración que introduce la fase

Del Anexo B del doc 02, los que aparecen con esta fase, con su valor por defecto y su justificación:

| Parámetro | Valor por defecto | Tarea | Por qué ese valor |
|---|---|---|---|
| `COMPLIANCE_PROFILE` | `ES-hosteleria` | 5.2 | El perfil español se entrega de serie (RF-PD-07). La mayoría de clientes no cambiará nada, así que el valor por defecto **es** el producto |
| `LICENSE_KEY` | vacío | 5.3 | Sin clave, el sistema funciona en estado degradado. Exigirla para arrancar chocaría con la regla dura 15 |
| `TELEMETRY_ENABLED` | `false` | 5.10 | RF-PD-12 lo exige: opcional y desactivada por defecto. El sistema funciona idénticamente sin ella |
| `ERROR_HISTORY_RETENTION_DAYS` | `90` | 5.12 | Igual que el log técnico (RL-11). Son datos técnicos, no registro legal |
| `BRANDING_APP_NAME` | vacío | 5.8 | Sin valor, se muestra el nombre del producto |
| `BRANDING_LOGO_PATH` | vacío | 5.8 | Sin valor, se muestra el logotipo del producto |

Más las claves de `installation_settings` que introduce 5.1 (marca, umbrales, idiomas y funcionalidades activas) y los umbrales de `compliance_profiles` que introduce 5.2.

**No se añaden parámetros «por si acaso».** Cada uno es superficie que documentar en `configuracion.md`, probar y soportar durante años. Configurable es lo que un cliente real necesita distinto.

---

## Runbooks que se redactan en esta fase

De los 20 del §12, estos tienen su origen en tareas de la Fase 5. El desarrollo completo de los 20, con su asignación, está en [`08-entrega-despliegue-y-actualizacion.md`](08-entrega-despliegue-y-actualizacion.md) §9.

| Runbook | Cuándo se usa | Tarea |
|---|---|---|
| `alta-nuevo-quiosco.md` | Emparejamiento por código y vinculación (incluye el modo quiosco, que es del cliente) | 5.6 |
| `actualizacion-cliente.md` | Procedimiento y vuelta atrás | 5.7 |
| `incidencia-sin-acceso.md` | Cómo diagnosticar con el paquete que envía el cliente | 5.9 |
| `errores-en-el-panel.md` | Cómo lee el IT del cliente el histórico de `error_events` y qué hacer con cada severidad | 5.12 |
| `restaurar-backup.md` | Recuperación y simulacro trimestral. Existe desde 2.11; la vuelta atrás de 5.7 lo usa y lo completa | 2.11 → 5.7 |
| `rotacion-secretos.md` | Rotación programada o compromiso. Existe desde §7.7; 5.4 añade los secretos que genera el instalador | 5.4 |

---

## Cierre de fase

Prompt literal del [doc 03](../docs/03-agentes-y-skills-ia.md) §6.6, con `<N>` = 5:

```
Cierra la Fase 5 del plan.

1. seguridad-cumplimiento: revisa todo lo implementado contra STRIDE,
   RGPD y art. 34.9 ET. Informe por severidad.
2. revisor-codigo: revisión final buscando duplicación, complejidad
   innecesaria e incumplimientos de la Definición de Terminado.
3. qa-testing: verifica cobertura, MSI y que cada requisito de la fase
   (Anexo A del documento 01) tiene prueba que lo cubre.
4. devops-observabilidad: comprueba que lo nuevo está instrumentado y
   que cada alerta añadida tiene su runbook.

Entrégame: los hallazgos bloqueantes, los requisitos de la fase sin
cobertura de prueba, y qué queda pendiente para pasar a la siguiente.
```

**Lo que el cierre de esta fase debe verificar en particular**, además de lo genérico:

| Comprobación | Origen |
|---|---|
| `RF-PD-01..15`, `RL-16..21` y `RQ-11` tienen prueba que los referencia | Anexo A del doc 01, §9.6 |
| Existe y está en verde la prueba de que **una licencia caducada no bloquea el fichaje ni el registro** | ADR-019, regla dura 15, RF-PD-05 |
| Existe y está en verde la prueba de que **la verificación de licencia no hace ninguna llamada de red** | ADR-018, RF-PD-04 |
| Un paquete de diagnóstico generado sobre datos realistas **no contiene PII** | ADR-020, RL-19, regla dura 21 |
| Etapa 8 de la CI en verde: **instalación limpia + actualización desde cada versión soportada** | §10.1, RQ-11 |
| **Salto no consecutivo y vuelta atrás** probados | RF-PD-10, §11.6.4 |
| **Nada específico de un cliente** ha entrado en el código, y no existe ninguna rama por cliente | ADR-017, regla dura 13, DoD §10.3 |
| Los cuatro documentos de cliente existen y **una persona ajena ha instalado siguiendo solo la guía** | RF-PD-02, doc 03 §6.5 |
| Las tres contradicciones con el doc 05 (C-1, C-2, C-3) están resueltas y el documento corregido si procede | `CLAUDE.md`, orden de autoridad |

Al cerrar esta fase se alcanza el hito del §11.1: **producto vendible, 336–442 h acumuladas, «el cliente lo instala, configura y opera»**. La siguiente fase es la 3.

---

## Qué se pierde si se recorta esta fase

Del §11.2, literal:

> | **Fase 5 entera** | **No hay producto.** Cada cliente nuevo consume al equipo de desarrollo. Es el recorte que decide si esto es un negocio de software o una consultora |
> | Solo la documentación de cliente (tarea 5.11) | Falso ahorro. Cada instalación se paga en horas de soporte para siempre |
> | Solo el actualizador (tarea 5.7) | Actualizar veinte clientes a mano, cada uno en una versión distinta, con datos de nómina de por medio. Es el recorte con más probabilidad de acabar en pérdida de datos de un cliente |

Y el §11.1 lo cuantifica: *«Las ~110 h de la Fase 5 son la inversión que hace que el cliente número veintiuno cueste lo mismo que el segundo.»*

---

## Puntos no cubiertos por los documentos

Cada uno de estos puntos es una decisión que hay que tomar **antes o durante** la tarea indicada. No se rellenan con criterio propio en este plan.

| # | Tarea | Punto |
|---|---|---|
| 1 | 5.1, 5.8 | **Precedencia entre las variables de entorno del Anexo B y las filas de `installation_settings`** para la misma propiedad (caso claro: `BRANDING_APP_NAME` frente a la clave de marca en base de datos; también `COMPLIANCE_PROFILE` frente a `sites.compliance_profile_id`). La cascada del ADR-017 define los ámbitos `installation` y `site`, pero no sitúa el entorno en ella. ⚠️ No cubierto por los documentos — decidir |
| 2 | 5.2 | **Valores de `max_weekly_hours`, `week_starts_on` y `holiday_calendar` del perfil `ES-hosteleria`.** El doc 01 §5 los declara campos del perfil y RF-PD-07 los enumera como configurables, pero ningún documento fija el valor que se entrega de serie (RN-10, RN-11 y RN-12 sí lo fijan: 12 h, 9 h y 6 h). ⚠️ No cubierto por los documentos — decidir |
| 3 | 5.2 | **Retroactividad al cambiar un umbral del perfil**: ¿se recalculan las incidencias ya generadas, y posiblemente ya resueltas, de jornadas pasadas? La skill `/nueva-regla-de-negocio` **obliga** a decidirlo y documentarlo; ningún documento lo decide. ⚠️ No cubierto por los documentos — decidir |
| 4 | 5.3 | ✅ **RESUELTO Y CONFIRMADO** — La frontera está enumerada en [ADR-023](../docs/adr/ADR-023-frontera-registro-legal-y-funcionalidad-accesoria.md), con dos reglas que se implementan y no solo se leen: lo no clasificado es **no degradable** por defecto, y el conjunto legal **no es licenciable** (no aparece en `features`, así que no existe forma de expresar su desactivación). La lista es contractual y **fue confirmada como decisión de producto el 13 de agosto de 2026**; una oferta comercial concreta que la amplíe o restrinja se resuelve con un ADR nuevo |
| 5 | 5.3 | **Antelación y cadencia de los avisos de caducidad** de licencia (a cuántos días empieza el aviso, dónde se muestra, con qué frecuencia). ⚠️ No cubierto por los documentos — decidir |
| 6 | 5.4, 5.7, 5.9 | **Los valores concretos de los códigos de salida** de los cinco scripts. El §3.5 exige que estén «documentados en la cabecera del script», pero no fija ninguno, y `install.sh` y `update.sh` deben ser consistentes entre sí. ⚠️ No cubierto por los documentos — decidir |
| 7 | 5.4, 5.11 | ✅ **RESUELTO** — `install.ps1` se retira del paquete ([ADR-022](../docs/adr/ADR-022-sin-instalador-de-windows.md)). El §11.6.1 del doc 02 ya no lo lista, y la documentación de instalación enuncia el requisito de Linux con Docker sin ambigüedad en lugar de dejar que se deduzca de la ausencia de un fichero |
| 8 | 5.4 | **A qué tarea se imputa el endurecimiento de `backup.sh` y `restore.sh` a las convenciones del §3.5.** Son entregables del §11.6.1 y su contenido funcional viene de la tarea 2.11 (Fase 2), pero ninguna tarea de la Fase 5 los menciona, y `update.sh` depende de ellos. ⚠️ No cubierto por los documentos — decidir |
| 9 | 5.5, 5.11 | ✅ **RESUELTO** — RF-GP-05 se movió de la tarea 3.10 a esta 5.5 (Anexo A del doc 01, §11 del doc 02). **El documento comercial no se corrige porque decía la verdad**: era el plan el que tenía el requisito en la fase equivocada. Son 3–4 h que cambian de fase, no que se suman |
| 10 | 5.6 | ✅ **RESUELTO en lo esencial** — No había contradicción: faltaba escribir el flujo. `/kiosk/pair` es *la tablet pide emparejarse y recibe el código que muestra*; el administrador lo teclea en el panel, que llama a `/kiosk/pair/confirm` y emite el token. `kiosk:pairing-code {site}` queda como vía alternativa de consola, y los tres documentos permanecen intactos. ⚠️ Siguen sin fijar la **longitud, el formato y la caducidad** del código — decidir |
| 11 | 5.7 | **Cómo se materializa el «punto de control entre cada migración»** del §11.6.4: si es una copia incremental, un `savepoint`, una marca de versión o un volcado por versión. El §11.6.4 exige el punto de control, no su mecanismo. ⚠️ No cubierto por los documentos — decidir |
| 12 | 5.9 | **Valores admitidos del campo `scope` de `support_grants`.** El doc 01 §5 lo declara y RF-PD-11 exige «alcance limitado», pero ningún documento enumera los alcances posibles. ⚠️ No cubierto por los documentos — decidir |
| 13 | 5.9 | **Formato, cifrado y canal de entrega del paquete de diagnóstico.** El §11.6.6 y el doc 05 §10.6 describen el contenido y que «se envía al soporte», pero no el formato del fichero, si va cifrado ni por qué canal viaja. Relevante porque atraviesa la frontera de datos del cliente. ⚠️ No cubierto por los documentos — decidir |
| 14 | 5.10 | **Destino, protocolo y periodicidad de la telemetría** cuando `TELEMETRY_ENABLED=true`, y la lista exacta de campos enviados. RF-PD-12 fija qué **no** puede llevar (datos personales ni de jornada) y que viene desactivada; el resto no está definido. ⚠️ No cubierto por los documentos — decidir |
| 15 | Transversal | **Duración temporal del soporte de una versión menor.** El §11.6.5 fija «la versión menor vigente y las dos anteriores», que es una política por número de versiones, no por tiempo. Con una cadencia de publicación desconocida, un cliente no puede planificar. ⚠️ No cubierto por los documentos — decidir |

---

← Anterior: [Fase 2 — Gestión y cumplimiento](04-fase-2-gestion-y-cumplimiento.md) · Siguiente: [Fase 3 — Operación y refuerzo](06-fase-3-operacion-y-refuerzo.md) · [Índice](README.md)
