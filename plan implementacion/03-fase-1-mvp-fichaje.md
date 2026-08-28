# 03 · Fase 1 — MVP de fichaje

| Campo | Valor |
|---|---|
| **Fase** | 1 — MVP de fichaje |
| **Horas** | **135–172 h** (doc 02 §11, tabla de la Fase 1, con la tarea 1.13 añadida y las tareas 1.14–1.18 de [ADR-032](../docs/adr/ADR-032-la-fase-1-entrega-un-sistema-legalmente-defendible.md)) |
| **Orden de ejecución** | **Segunda.** El orden global de fases es **0 → 1 → 2 → 5 → 3 → 4** (doc 02 §11, doc 01 Anexo A) |
| **Documento origen** | [`../docs/02-stack-tecnologico-y-plan-implementacion.md`](../docs/02-stack-tecnologico-y-plan-implementacion.md) §11 (plan), §3.2 (invariantes en BD), §5 (credencial), §6 (protocolo offline), §7 (seguridad), §9 (pruebas), Anexo A (rendimiento del quiosco), Anexo B (variables), Anexo C (comandos) · [`../docs/01-especificaciones-proyecto.md`](../docs/01-especificaciones-proyecto.md) §3, §4, §5.5, §11, Anexo A, Anexo B · [`../docs/03-agentes-y-skills-ia.md`](../docs/03-agentes-y-skills-ia.md) §5, §6.2, §6.3, §6.4, §6.6 |

**Entregable (literal del doc 02 §11).**

> **Entregable:** un empleado recibe su tarjeta y ficha en la tablet, con o sin red, con credencial infalsificable y registro correcto, **corregible con trazabilidad completa, respaldado con copia verificada y exportable a Inspección de Trabajo**. **Instalable y legalmente defendible** ([ADR-032](../docs/adr/ADR-032-la-fase-1-entrega-un-sistema-legalmente-defendible.md)) — no es aún «producto vendible a escala», que sigue siendo la Fase 5.

**Requisitos que cubre la fase** (doc 01, Anexo A — Trazabilidad requisito → fase):

`RF-AT-01..09`, `RF-AT-11`, `RF-QR-01..06`, `RF-QR-08`, `RF-ID-01..02` (**autenticación de gestión básica, sin 2FA**), `RF-ID-04..09`, `RF-KI-01..06`, `RF-KI-09`, `RF-GP-01`, `RF-GP-03`, `RF-PA-03..04`, `RF-IN-05`, `RF-PR-04`, `RN-01..09`, `RN-13`, `RN-15`, `RL-01`, `RL-03..06`, `RL-09`, `RL-12`, `RS-01..04`, `RS-07`, `RS-12`, `RS-13`, `RNF-D-02`, `RNF-D-05`, `RQ-09`

> **Nota del Anexo A del doc 01 sobre el reparto de `RF-ID-*`.** *«La Fase 1 necesita una autenticación de gestión mínima —sin ella, RRHH no puede emitir tarjetas ni ver el panel de estado de credenciales (tarea 1.10)—, pero el 2FA obligatorio y el ámbito por departamento llegan con la tarea 2.1.»*
>
> **[ADR-032](../docs/adr/ADR-032-la-fase-1-entrega-un-sistema-legalmente-defendible.md) — quince requisitos que eran de la Fase 2 se adelantan aquí.** `RN-13`, `RL-04`, `RF-PA-04` (correcciones trazadas, 1.15); `RS-07` (auditoría encadenada, 1.14); `RF-PA-03` (detalle de jornada, 1.16); `RL-03`, `RL-06`, `RF-IN-05` (exportación legal, 1.17); `RF-PR-04`, `RNF-D-02`, `RNF-D-05`, `RQ-09` (copias verificadas, 1.18); `RN-15`, `RL-09`, `RL-12` (ya los construían 1.8 y 1.9, solo se corrige la fase). Sin ellos, cerrar `0+1` dejaba un «piloto interno controlado» que no satisfacía el art. 34.9 ET.

**Agentes protagonistas** (doc 03 §2.2):

> **Fase 1 — MVP de fichaje:** `arquitecto-dominio` → `qa-testing` → `backend-laravel`, con `frontend-quiosco` y `frontend-portal-empleado` en paralelo.

**Aviso del camino crítico (doc 02 §11, literal).**

> **Camino crítico:** 1.1 y 1.2 bloquean todo lo demás y son las más fáciles de subestimar. **No empezar la interfaz del quiosco hasta que el dominio esté cerrado y sus pruebas en verde.** Un cambio en las reglas de cálculo con el frontend construido cuesta el triple.

---

## Índice de tareas

Numeradas en el orden del doc 02 §11 (que **no** es el orden de ejecución: 1.13 ya se ejecuta
antes que 1.11 pese a su número). La columna **Orden** da la posición real; el detalle completo
está en el diagrama de [«Camino crítico completo de la fase»](#camino-crítico-completo-de-la-fase)
al cierre de este documento.

| # | Orden | Tarea | h | Requisitos | Agente / Skill |
|---|---|---|---|---|---|
| [1.1](#tarea-11--dominio-attendance-workday-shiftentry-objetos-de-valor-clockingpolicy-eventos) | 1 | Dominio `Attendance`: `WorkDay`, `ShiftEntry`, objetos de valor, `ClockingPolicy`, eventos | 14–18 | RN-01..09 | `arquitecto-dominio` |
| [1.2](#tarea-12--pruebas-unitarias-del-dominio-incluidas-dst-y-medianoche-con-mutación) | 2 | Pruebas unitarias del dominio, incluidas DST y medianoche, con mutación | 10–12 | RQ-01, RQ-02 | `qa-testing` |
| [1.3](#tarea-13--esquema-y-migraciones-con-todas-las-restricciones-declarativas) | 3 | Esquema y migraciones con **todas** las restricciones declarativas | 6–8 | RN-01..03 | `backend-laravel` + `/migracion-segura` |
| [1.4](#tarea-14--caso-de-uso-registerscan-con-idempotencia-y-proyección-transaccional) | 5 | Caso de uso `RegisterScan` con idempotencia y proyección transaccional | 8–10 | RF-AT-01..09 | `backend-laravel` + `/crear-caso-de-uso` |
| [1.5](#tarea-15--módulo-identity-credenciales-hmac-key_id-revocación-tokens-de-dispositivo) | 4 | Módulo `Identity`: credenciales HMAC, `key_id`, revocación, tokens de dispositivo | 8–10 | RF-QR-01..03, RF-ID-04 | `backend-laravel`, revisión de `seguridad-cumplimiento` |
| [1.6](#tarea-16--workforce-básico-más-autenticación-de-gestión-mínima) | 4 | `Workforce` básico + autenticación de gestión mínima (login y roles, **sin 2FA**) | 8–10 | RF-GP-01, RF-GP-03, RF-ID-01..02 básicos | `backend-laravel` |
| [1.7](#tarea-17--endpoints-de-fichaje-lote-padrón-y-latido-con-rate-limiting) | 6 | Endpoints de fichaje, lote, padrón y latido, con rate limiting | 6–8 | RS-02..04 | `backend-laravel` + `/endpoint-api` |
| [1.8](#tarea-18--pwa-quiosco-escaneo-zxing-feedback-visual-y-sonoro-i18n-accesibilidad) | 7 | PWA quiosco: escaneo ZXing, feedback visual y sonoro, i18n, accesibilidad | 12–16 | RF-KI-01..02, RF-KI-05..06, RF-KI-09, RL-09 | `frontend-quiosco` |
| [1.9](#tarea-19--cola-offline-dexie-con-sincronización-reintentos-e-indicador) | 8 | Cola offline Dexie con sincronización, reintentos e indicador | 10–12 | RF-KI-03..04, RN-15, RL-12 | `frontend-quiosco` |
| [1.10](#tarea-110--generación-de-tarjetas-en-pdf-impresión-masiva-registro-de-entrega-y-panel-de-estado) | 5 | Generación de tarjetas en PDF, impresión masiva, registro de entrega y panel de estado | 6–8 | RF-QR-04..06, RF-QR-08 | `backend-laravel` + `frontend-panel` |
| [1.11](#tarea-111--portal-del-empleado-acceso-con-código-y-pin-mi-registro-mi-exportación) | 9 | Portal del empleado: acceso con código y PIN, mi registro, mi exportación | 6–8 | RF-ID-05..08, RL-05 | `frontend-portal-empleado` + `backend-laravel` |
| [1.12](#tarea-112--pin-de-respaldo-de-6-dígitos-en-el-quiosco-con-bloqueo-por-intentos) | 9 | PIN de respaldo de 6 dígitos en el quiosco, con bloqueo por intentos | 4–5 | RF-AT-11, RS-12 | `backend-laravel` + `frontend-quiosco` |
| [1.13](#tarea-113--provisión-entrega-y-restablecimiento-del-pin) | 8 | Provisión, entrega y restablecimiento del PIN | 4–5 | RF-ID-09 | `backend-laravel` + `frontend-panel` |
| [1.14](#tarea-114--audit_log-encadenado-comando-de-verificación-y-permisos) | **4** | `audit_log` encadenado, comando de verificación y permisos | 8–10 | RS-07 | `backend-laravel` + `/revision-cumplimiento` |
| [1.15](#tarea-115--correcciones-trazadas-versionado-catálogo-de-motivos-anulación) | 6 | Correcciones trazadas: versionado, catálogo de motivos, anulación | 10–12 | RN-13, RL-04, RF-PA-04 | `arquitecto-dominio` → `backend-laravel` |
| [1.16](#tarea-116--panel-detalle-de-jornada) | 7 | Panel: detalle de jornada | 6–8 | RF-PA-03 | `frontend-panel` |
| [1.17](#tarea-117--exportación-legal-para-inspección) | 7 | Exportación legal para Inspección | 5–6 | RL-03, RL-06, RF-IN-05 | `backend-laravel` + `/informe-nuevo` |
| [1.18](#tarea-118--copias-cifradas-verificadas-con-prueba-de-restauración) | 2 | Copias cifradas, verificadas, con prueba de restauración | 4–6 | RF-PR-04, RNF-D-02, RNF-D-05, RQ-09 | `devops-observabilidad` |
| [—](#cierre-de-fase-doc-03-66) | — | Cierre de fase | — | — | `revisor-codigo` + `seguridad-cumplimiento` + `qa-testing` + `devops-observabilidad` |

**Añadidas por [ADR-032](../docs/adr/ADR-032-la-fase-1-entrega-un-sistema-legalmente-defendible.md):** 1.14–1.18, numeradas al final para no romper las referencias cruzadas de 1.1–1.13, pero ejecutadas donde su dependencia real las sitúa — `1.14` justo tras el esquema (orden 4, entre 1.3 y 1.4); `1.18` en paralelo desde el principio (orden 2, solo necesita 0.1); `1.15`–`1.17` tras lo que corrigen y exportan.

---

## Prompt de arranque del dominio (doc 03 §6.2 — tareas 1.1 y 1.2, camino crítico)

```
Diseña e implementa el dominio del módulo Attendance.

Usa arquitecto-dominio para el diseño y qa-testing para las pruebas.
NO implementes todavía persistencia, endpoints ni interfaz.

Alcance: agregado WorkDay, entidad ShiftEntry, objetos de valor
(TimeRange, WorkedDuration, WorkDate, ScanOrigin), ClockingPolicy,
eventos de dominio y excepciones.

Reglas a cubrir: RN-01 a RN-09 del documento 01 §4.

Requisitos innegociables:
- Cero imports de Illuminate en Domain/
- El reloj se inyecta mediante el puerto Clock
- Los umbrales legales llegan por el puerto CompliancePolicy, no son constantes
- WorkDay protege las invariantes; nadie toca ShiftEntry por fuera
- El total se recalcula, nunca se incrementa (RN-06)
- Un turno que cruza medianoche NO se parte (RN-05, ADR-006)

Pruebas obligatorias: los dos cambios de hora de Europe/Madrid en ambos
sentidos, turno 22:00→06:00, límites exactos de duración mínima y máxima,
jornada partida de 4 tramos.

Criterio de terminado: `make test-unit` en verde y dentro de su presupuesto de duración,
cobertura del dominio ≥ 90 %, y `make mutate` con MSI ≥ 80 %.

Esta es la tarea del camino crítico. No pases a otra cosa hasta cerrarla.
```

---

## Las tareas, desarrolladas

### Tarea 1.1 — Dominio `Attendance`: `WorkDay`, `ShiftEntry`, objetos de valor, `ClockingPolicy`, eventos

| | |
|---|---|
| **Horas** | 14–18 |
| **Agente / Skill** | `arquitecto-dominio` |
| **Requisitos** | **RN-01..09** (literal del doc 02 §11). Contrastado con el Anexo A del doc 01: `RN-01..09` están en la Fase 1; `RN-10..15` **no** (Fase 2) |
| **Precondiciones** | **0.3** (§11.3: `0.1→0.2→0.3 ──► 1.1→1.2`) |
| **Bloquea a** | **1.2** y, a través de ella, **todo lo demás** de la fase (§11.3) |

**Objetivo.** Existe el núcleo de negocio del fichaje —agregado `WorkDay`, entidad `ShiftEntry`, objetos de valor, `ClockingPolicy`, eventos y excepciones— puro, sin framework, capaz de calcular duraciones correctas en DST y en turnos nocturnos. Sin persistencia, sin endpoints y sin interfaz.

**Reglas duras aplicables.**

- **1** (`Domain/` es puro): es la tarea donde la regla se gana o se pierde. Un solo `use Illuminate\...` aquí y todo el hexágono deja de tener sentido.
- **2** (nunca `now()`, `time()`, `Carbon::now()` ni `new DateTime()`): sin el puerto `Clock` inyectado no se puede probar DST ni medianoche de forma determinista, que es justo lo que la tarea 1.2 tiene que probar.
- **3** (todo instante en UTC): `TimeRange` opera sobre instantes UTC. La zona del centro solo interviene para resolver `WorkDate` (RN-05) y en presentación.
- **4** (los turnos no se parten a medianoche): un turno 22:00→06:00 es **un único** `ShiftEntry`, atribuido a la jornada de su hora de inicio (RN-05, ADR-006).
- **7** (`daily_totals` es proyección reconstruible): el total **se recalcula** como suma de los tramos de la jornada; nunca se incrementa (RN-06, ADR-007). En el dominio esto significa que `WorkDay` expone un cálculo, no un acumulador mutable.
- **14** (los umbrales legales se leen del perfil de cumplimiento): `ClockingPolicy` **recibe** los umbrales ya resueltos por el puerto `CompliancePolicyProvider`, que vive en `Shared/Application/Port/` ([ADR-025](../docs/adr/ADR-025-frontera-de-dependencias-del-nucleo.md)). El dominio nunca consulta la configuración (§1.5, §1.6).

**Pasos.** Sin skill asignada. Orden literal del método del agente `arquitecto-dominio` (doc 03 §4.3): módulo → capa → invariantes con su `RN-*` → objetos de valor → puertos → firmas y casos de prueba → implementación.

1. **Módulo.** `Attendance`, el núcleo (§1.6). Puede depender solo de `Shared`. Confirmar que nada de lo que se diseña pertenece a `Workforce` (el empleado) ni a `Compliance` (la incidencia).
2. **Capa.** Todo lo de esta tarea es `Domain/`. Lo que parezca orquestación va a `Application/` (tarea 1.4); lo que parezca detalle técnico, a `Infrastructure/` (1.3 y 1.4).
3. **Invariantes con su `RN-*`.** Enumerarlas antes de escribir una línea. `WorkDay` es la frontera transaccional del fichaje (doc 01 §5.2) y protege:

   | Invariante | Regla | Enunciado |
   |---|---|---|
   | Un solo turno abierto | **RN-01** | Un empleado no puede tener más de un turno abierto simultáneamente. Invariante de dominio **y** restricción en BD (1.3) |
   | Sin solapes | **RN-02** | Los tramos de un mismo empleado no pueden solaparse en el tiempo |
   | Orden de las marcas | **RN-03** | `clocked_out_at` estrictamente posterior a `clocked_in_at` |
   | Almacenamiento UTC | **RN-04** | Todas las marcas en UTC; cálculo e informes en la zona del centro |
   | Jornada por hora de inicio | **RN-05** | `work_date` es la fecha civil, en la zona del centro, del `clocked_in_at` del tramo **que abre la jornada**. Un turno 22:00→06:00 pertenece **íntegramente** al día de inicio. Los tramos que **continúan** una jornada abierta —la vuelta de una pausa (RF-AT-12)— **heredan su `work_date`** y no abren jornada nueva, aunque empiecen en otro día natural ([ADR-024](../docs/adr/ADR-024-la-pausa-son-dos-tramos.md)) |
   | Total recalculado | **RN-06** | El total diario se recalcula como suma de los tramos; **nunca se incrementa** |
   | Duración mínima | **RN-07** | Mínima computable: 1 minuto. Por debajo se registra el evento pero se marca como incidencia |
   | Duración máxima | **RN-08** | Máxima antes de anómala: 12 h (**configurable**). **Nunca se cierra automáticamente** sin intervención humana |
   | Inmunidad a DST | **RN-09** | El cálculo usa aritmética sobre instantes UTC. El día del cambio de hora una jornada natural puede tener 23 o 25 horas y los informes deben reflejarlo |

4. **Objetos de valor, antes que las entidades** (principio 4 del agente). Del doc 01 §5.3 y del prompt §6.2, los de este módulo:
   - `TimeRange` — instantes de inicio y fin en UTC. Se valida al construirse: fin posterior a inicio (RN-03).
   - `WorkedDuration` — minutos, **no negativo**. El constructor lo rechaza; no se comprueba después (principio 5 del agente: los estados imposibles no se pueden construir).
   - `WorkDate` — fecha **más zona**. Es lo que hace RN-05 expresable sin ambigüedad. **El `WorkDate` es propiedad de la jornada, no del tramo:** lo resuelve `WorkDay` al abrirse y los tramos que la continúan lo heredan. Si cada `ShiftEntry` lo derivase de su propio `clocked_in_at`, la vuelta de una pausa a las 02:30 caería en el día siguiente y ADR-024 obligaría a reabrir el agregado en la tarea 3.5.
   - `ScanOrigin` — `enum` con `QR_KIOSK` | `PIN_KIOSK` | `MANUAL_ADMIN` | `IMPORT` (doc 01 §5.3; §3.5 exige `enum` en lugar de constantes de clase).
   - Todos `readonly` y `final`, con `declare(strict_types=1)` (§3.5).
5. **Puertos.** Todos en la capa de **aplicación** (§1.5), ninguno en `Domain/`. El dominio **recibe** lo que necesita; no lo pide. **Quién declara y quién implementa cada uno lo fija [ADR-025](../docs/adr/ADR-025-frontera-de-dependencias-del-nucleo.md)**: el núcleo declara, el satélite implementa, y la arista va del satélite a `Attendance`, nunca al revés.

   **Los cinco puertos de `Attendance/Application/Port/`:**
   - `WorkDayRepository` y `EventPublisher` — los implementa la propia `Infrastructure` de `Attendance`. Los usa el caso de uso de 1.4.
   - `CredentialResolver` — de un payload QR firmado al `employee_uuid`, o rechazo genérico. **Lo implementa `Identity`** en la tarea 1.5, que es donde vive la tabla `credentials`.
   - `EmployeeDirectory` — estado y adscripción del empleado, necesario para RN-14 (rechazar al empleado de baja). **Lo implementa `Workforce`.** Devuelve un objeto de valor inmutable `EmployeeSnapshot`, **nunca un modelo Eloquent**: es lo que impide que el acoplamiento se cuele por el tipo de retorno.
   - `SiteCalendar` — zona horaria del centro, sin la cual RN-05 no es expresable («la fecha civil **en la zona del centro**»). **Lo implementa `Workforce`**, que tiene `sites`.

   **Los tres que no son de `Attendance` aunque los consuma:**
   - `Shared/Application/Port/Clock` — el reloj inyectado (regla dura 2). **En `Shared`, no en `Attendance`**, y en `Application`, no en `Domain` ([ADR-021](../docs/adr/ADR-021-clock-en-shared.md)).
   - `Shared/Application/Port/CompliancePolicyProvider` — entrega los **umbrales legales** ya resueltos desde el perfil de cumplimiento: descanso entre jornadas (RN-10), jornada diaria (RN-11), tramo continuo sin pausa (RN-12) y años de retención. Devuelve un objeto de valor `CompliancePolicy`.
   - `Shared/Application/Port/OperationalSettingsProvider` — entrega los **umbrales operativos**, que son otra cosa: duración anómala de tramo (RN-08), anti-rebote (RF-AT-06), tolerancia de desfase de reloj (RF-AT-10) y tránsito mínimo entre quioscos (RN-16).
   - Los dos proveedores de umbrales están en `Shared` y no en `Attendance` porque los consumen varios módulos —`Compliance` y `Reporting` el legal, `Kiosk` el operativo por RF-AT-10— y no representan una regla de negocio de ninguno: es el criterio de admisión de ADR-021 aplicado por ADR-025. **Sus adaptadores viven en `Product`**, que es donde están `compliance_profiles` e `installation_settings`.
   - Nombres en el lenguaje del negocio: `findOpenWorkDayFor`, no `getByEmployeeIdAndDate` (skill `crear-caso-de-uso`, paso 2).

   > **Dos puertos y no uno, porque son dos fuentes distintas.** El doc 01 §4 lo dice sin ambigüedad: RN-10, RN-11 y RN-12 son umbrales **legales** y viven en `compliance_profiles` (RF-PD-07); RN-08 y RN-16 son **operativos** —no provienen del marco normativo, los fija el hotel— y viven en `installation_settings` (RF-PD-01). Meter RN-08 en `CompliancePolicyProvider` sería un error de fondo: un umbral legal lo fija la jurisdicción y uno operativo lo fija el cliente, y `compliance_profiles` **ni siquiera tiene columna** para la duración anómala de tramo.

   > **Nomenclatura fijada aquí y válida para todo el proyecto**, porque el lenguaje ubicuo existe para evitar exactamente esto: puerto **`Shared/Application/Port/CompliancePolicyProvider`**, adaptador **`Product/Infrastructure/Adapter/DbCompliancePolicyProvider`**, objeto de valor devuelto **`CompliancePolicy`**. Una sola declaración de cada uno en todo el árbol, y una prueba de arquitectura lo verifica (ADR-025).
6. **Firmas y casos de prueba antes de la implementación** (paso 6 del método del agente). Escribir la firma de `WorkDay`, `ShiftEntry`, `ClockingPolicy` y sus excepciones, y enumerar los casos que la tarea 1.2 tendrá que cubrir. `ShiftEntry` **no se toca por fuera del agregado**.
7. **Eventos de dominio.** Del doc 01 §5.4, los que emite este módulo: `EmployeeClockedIn`, `EmployeeClockedOut`, `ScanRejected`, `DailyTotalsRecalculated`. `ShiftCorrected` es de la tarea 2.3. `Attendance` **no llama** a `Compliance` ni a `Reporting`: emite y ellos reaccionan (principio 6 del agente, §1.6).
8. **Excepciones específicas** en `Domain/Exception/`, con nombres del dominio y no del patrón (§3.5).
9. **Implementación**, y solo ahora. `ClockingPolicy` en `Domain/Policy/` recibe los umbrales por constructor.
10. Verificar con `make test-unit` y `vendor/bin/deptrac` (regla de conducta del agente `arquitecto-dominio`).

**Artefactos.** Rutas del árbol del §2:

- `backend/app/Modules/Attendance/Domain/Model/` — `WorkDay`, `ShiftEntry`
- `backend/app/Modules/Attendance/Domain/ValueObject/` — `TimeRange`, `WorkedDuration`, `WorkDate`, `ScanOrigin`
- `backend/app/Modules/Attendance/Domain/Event/` — `EmployeeClockedIn`, `EmployeeClockedOut`, `ScanRejected`, `DailyTotalsRecalculated`
- `backend/app/Modules/Attendance/Domain/Policy/` — `ClockingPolicy`
- `backend/app/Modules/Attendance/Domain/Exception/`
- `backend/app/Modules/Attendance/Application/Port/` — `WorkDayRepository`, `EventPublisher`, `CredentialResolver`, `EmployeeDirectory`, `SiteCalendar`
- `backend/app/Modules/Shared/Application/Port/Clock.php` — **el reloj vive aquí** ([ADR-021](../docs/adr/ADR-021-clock-en-shared.md))
- `backend/app/Modules/Shared/Infrastructure/Adapter/SystemClock.php` — **y su implementación también**, no en `Attendance`
- `backend/app/Modules/Shared/Application/Port/` — `CompliancePolicyProvider`, `OperationalSettingsProvider` ([ADR-025](../docs/adr/ADR-025-frontera-de-dependencias-del-nucleo.md))
- `backend/app/Modules/Shared/Domain/ValueObject/EmployeeSnapshot.php` — lo que devuelve `EmployeeDirectory`. En `Shared` porque cruza la frontera entre dos módulos, y **con su segmento de capa**: Deptrac se configura por ruta, y un fichero sin capa es una violación esperando a ocurrir en la tarea 1.6

> **`Clock` en `Shared/Application/Port/`, y por qué en esa capa.** `Compliance` lo necesita para la fecha de corte de retención y para sellar `audit_log`, `Kiosk` para calcular el desfase entre `occurred_at` y `recorded_at`, `Reporting` para resolver periodos relativos y el scheduler para la detección nocturna. Declararlo en `Attendance` obligaría a los demás a importarlo —rompiendo la frontera del §1.6— o a duplicar cuatro veces una interfaz de un método.
>
> **Y en `Application`, no en `Domain`,** porque la capa la decide quién consume la abstracción: el dominio recibe instantes ya resueltos, y quien llama al reloj es el caso de uso. Ponerlo en `Domain/Port/` habría chocado con la regla de Deptrac que prohíbe a `*/Domain` depender del `Domain` de otro módulo — es decir, **habría tumbado el reloj el primer día**.
>
> **`SystemClock` va en `Shared/Infrastructure/Adapter/`.** Si viviera en `Attendance`, `Compliance` no podría importarlo sin violar el §1.6 y acabaría con una segunda implementación: la duplicación que ADR-021 existe para evitar.
>
> **Criterio para que `Shared` no se convierta en cajón de sastre:** entra lo que **más de un módulo necesita y no representa una regla de negocio de ninguno**. Los cinco puertos de `Attendance` de esta lista se quedan donde están: `CredentialResolver`, `EmployeeDirectory` y `SiteCalendar` **sí representan una regla del núcleo** —definen qué necesita saber `Attendance` para decidir un fichaje— aunque el dato lo tengan otros. Que un módulo ajeno implemente el adaptador no mueve el puerto: esa es toda la idea de ADR-025.

> **Los dos proveedores de umbrales se adelantan desde la Fase 5, y esto resuelve una tensión de orden real.** La regla dura 14 exige que los umbrales lleguen resueltos por un puerto y nunca sean constantes, pero `compliance_profiles` e `installation_settings` son las tareas **5.1 y 5.2**, que en el orden real (0 → 1 → 2 → 5 → 3 → 4) van **después** de las Fases 1 y 2. Tal cual, la regla 14 se incumpliría durante dos fases enteras.
>
> **Se adelanta solo lo barato, no la funcionalidad:**
>
> - **Aquí (1.1):** los dos puertos, que son interfaces. En `Shared/Application/Port/` y no en `Attendance` ([ADR-025](../docs/adr/ADR-025-frontera-de-dependencias-del-nucleo.md)); sus adaptadores son de `Product` y llegan con 5.1 y 5.2.
> - **En 1.3:** las dos tablas y sus filas semilla. `compliance_profiles` con `ES-hosteleria` y los valores que RN-10, RN-11 y RN-12 **ya fijan** —12 h de descanso, 9 h de jornada, 6 h sin pausa— más 4 años de retención (RL-02); `installation_settings` con los valores por defecto del Anexo B: `ATTENDANCE_MAX_SHIFT_HOURS`, `ATTENDANCE_DEBOUNCE_SECONDS`, `ATTENDANCE_MAX_CLOCK_SKEW_MINUTES` y `ATTENDANCE_MIN_TRANSIT_SECONDS`.
> - **Se queda en 5.1 y 5.2 lo caro:** edición desde el panel, resolución en cascada por ámbito, auditoría del cambio, endpoints y el resto de campos del perfil (RF-PD-07).
>
> Ninguna semilla inventa un número: todos están fijados en el doc 01 §4 o en el Anexo B del doc 02. Coste aproximado de 2 h adelantadas, cero trabajo repetido, y el dominio nace recibiendo umbrales por puerto en lugar de leyendo constantes que habría que extraer más tarde — que es exactamente el refactor que la regla 14 existe para evitar.

**Pruebas exigidas** (tabla del §9.5). Esta tarea *introduce reglas de negocio* (`RN-*`):

| Nivel | ¿Aplica? | Detalle |
|---|:---:|---|
| Unitaria | ✅ **obligatoria** | Toda regla `RN-01..09`. Sin BD, sin framework (RQ-01) |
| Integración | — | No aplica: aquí no hay esquema |
| Feature + Contrato | — | No aplica: aquí no hay endpoint |
| Autorización negativa | — | No aplica |
| E2E | — | No aplica |

La escritura material de esas pruebas es la tarea 1.2. Lo que 1.1 entrega son las **firmas y la lista de casos** (paso 6). Etiquetas del §9.6: `->group('RN-01')` … `->group('RN-09')`, más `RF-AT-08` en la prueba de medianoche.

**Verificación.**

```bash
make test-unit            # verde, dentro del presupuesto de duración (gate propio)
vendor/bin/deptrac        # 0 violaciones de frontera
make quality              # Pint + PHPStan 9 + Deptrac + Rector dry-run

# Comprobación de pureza, a mano y sin margen de duda
grep -rn "Illuminate\|Eloquent\|Carbon\|now()\|time()\|new DateTime" \
  backend/app/Modules/Attendance/Domain/    # sin resultados

# El núcleo no nombra a ningún satélite (ADR-025). Si esto devuelve algo,
# una flecha se ha invertido y la frontera está rota
grep -rn "Modules\\\\\(Identity\|Workforce\|Product\|Compliance\)" \
  backend/app/Modules/Attendance/           # sin resultados
```

Resultado esperado: `make test-unit` en verde y dentro de su presupuesto de duración, Deptrac y PHPStan 9 limpios, y **cero coincidencias** en los dos `grep`.

**Terminado cuando** (subconjunto aplicable de §10.3):

- [ ] Código conforme a la arquitectura (Deptrac en verde).
- [ ] Pruebas unitarias de cada `RN-*` (se cierra con 1.2); cobertura de dominio y MSI dentro de umbral.
- [ ] Pruebas etiquetadas con los requisitos que cubren, y `qa:traceability --check` en verde.
- [ ] Convenciones del §3.5: `declare(strict_types=1)`, tipado completo, objetos de valor `readonly`, nombres en inglés según el glosario del doc 01 §13 (`ShiftEntry`, no `Tramo`).
- [ ] PHPStan nivel 9 sin errores.
- [ ] ADR escrito si la decisión es estructural (ubicación de `Clock`, [ADR-021](../docs/adr/ADR-021-clock-en-shared.md); dirección de las dependencias del núcleo, [ADR-025](../docs/adr/ADR-025-frontera-de-dependencias-del-nucleo.md)).
- [ ] **`Attendance` no importa nada de `Identity`, `Workforce`, `Product` ni `Compliance`**, y los puertos no exponen en su firma tipos de esos módulos ni de `Illuminate\*` (ADR-025).

*No aplican en esta tarea:* contrato OpenAPI, autorización, migración, textos i18n, accesibilidad, instrumentación, auditoría — nada de eso existe todavía en el dominio puro.

---

### Tarea 1.2 — Pruebas unitarias del dominio, incluidas DST y medianoche, con mutación

| | |
|---|---|
| **Horas** | 10–12 |
| **Agente / Skill** | `qa-testing` |
| **Requisitos** | **RQ-01, RQ-02** (literal del doc 02 §11). Del Anexo A del doc 01, la fase incluye además `RN-01..09`, que son los que estas pruebas cubren |
| **Precondiciones** | **1.1** (§11.3) |
| **Bloquea a** | **1.3, 1.5, 1.6** y, por la nota del §11.3, **todo lo demás** |

**Objetivo.** Cada regla `RN-01..09` tiene al menos una prueba unitaria que puede fallar; la suite completa corre dentro del presupuesto de duración que verifica `make test-unit` (§9.2 del doc 02); la cobertura del dominio supera el 90 % y el MSI el 80 %.

**Reglas duras aplicables.**

- **2**: sin el `Clock` inyectado, las pruebas de DST no son deterministas. Si alguna prueba necesita congelar el tiempo con un truco del framework, la regla 2 se ha roto en 1.1.
- **4**: la prueba de medianoche verifica **duración, atribución a `work_date` y ausencia de tramos artificiales**. Las tres cosas, no una.
- **7**: la prueba de RN-06 debe demostrar que el total se **recalcula**. Una prueba que solo suma no distingue recálculo de incremento: hay que provocar una corrección o una anulación y comprobar que el total no arrastra el valor anterior.

**Pasos.** Sin skill asignada. Orden derivado del agente `qa-testing` (selección de nivel, escenarios ineludibles, umbrales) y del §9.4.

1. Escribir **primero las pruebas que fallan**, contra las firmas que 1.1 entregó (regla de conducta del agente). Si una prueba se escribe después y pasa a la primera, **romper la implementación a propósito** para verificar que puede fallar.
2. **Cambio de hora, los dos, en ambos sentidos** (§9.4, prompt §6.2). Casos fijos para el último domingo de marzo y de octubre en `Europe/Madrid`, con turnos que atraviesan el salto. **La duración se compara contra el intervalo UTC real**, nunca contra la aritmética de horas locales. El doc 01 §11 da un caso literal:

   ```gherkin
   Escenario: Cambio de hora de otoño
     Dado un centro con zona horaria "Europe/Madrid"
     Y un empleado que ficha entrada el 25 de octubre a las 01:30 CEST
     Cuando ficha salida ese mismo día a las 03:00 CET
     Entonces la duración calculada es de 150 minutos
   ```

   Etiqueta: `->group('RN-09', 'RQ-02')`.
3. **Turno que cruza medianoche** (§9.4). Del doc 01 §11:

   ```gherkin
   Escenario: Turno nocturno que cruza la medianoche
     Dado un empleado "Marc" que ficha entrada el día 14 a las 22:00
     Cuando ficha salida el día 15 a las 06:00
     Entonces el tramo dura 480 minutos
     Y el tramo se atribuye a la jornada del día 14
     Y no se ha creado ningún tramo artificial a las 23:59
   ```

   Etiqueta: `->group('RN-05', 'RF-AT-08')`.

   **Y su variante de ADR-024, que es la que fija que el `work_date` es de la jornada y no del tramo:**

   ```gherkin
   Escenario: La vuelta de una pausa hereda la jornada, aunque sea otro día natural
     Dado un empleado que ficha entrada el día D a las 22:00
     Y ficha salida el día D+1 a las 02:00 para hacer una pausa
     Cuando ficha entrada de nuevo el día D+1 a las 02:30
     Y ficha salida el día D+1 a las 06:00
     Entonces el total de la jornada del día D es de 450 minutos
     Y el total del día D+1 es de 0 minutos
     Y los dos tramos comparten el mismo work_date
   ```

   Etiqueta: `->group('RN-05', 'RN-12', 'RF-AT-12')`. Es la prueba que falla si el agregado deriva `work_date` por tramo, y por eso se escribe **ahora** y no en la tarea 3.5: la 3.5 añade la intención declarada por el cliente, no el modelo de la jornada.
4. **Límites exactos de duración mínima y máxima** (prompt §6.2). Los valores límite se escriben **como números explícitos, no como cálculo** (§3.5):
   - RN-07: tramo de **0 minutos** y de **1 minuto**. Por debajo del mínimo, el evento se registra y se marca como incidencia; **no se descarta**.
   - RN-08: **11:59**, **12:00** y **12:01**. Y un tramo de **13 horas** (escenario del agente `qa-testing`). Comprobar que **nunca se cierra automáticamente**.
5. **Jornada partida de 4 tramos** (prompt §6.2, RF-AT-04): suma correcta y `daily_total` cuadrando con los tramos origen. Del doc 01 §11, el caso del acumulado: tramo previo cerrado de 120 min + tramo de 240 min = **360 min** de total diario.
6. **RN-01 y RN-02 en el dominio**: intentar abrir un segundo turno con uno ya abierto, e intentar insertar un tramo que solapa. El agregado debe rechazarlo. *La prueba de que la base de datos también lo rechaza es de la tarea 1.3, nivel integración.*
7. **RN-06 con corrección**: corromper el escenario para que un recálculo dé distinto del acumulado y verificar que el resultado es el recálculo.
8. Aplicar las convenciones de código de pruebas del §3.5, que son del agente `qa-testing`:
   - El nombre describe el comportamiento: `it('no parte un turno que cruza medianoche')`, nunca `testCalculateDuration`.
   - Un concepto por prueba, con *arrange / act / assert* visible.
   - *Factories* legibles: `Employee::factory()->withOpenShiftSince('22:00')`.
   - **Sin condicionales ni bucles con lógica**; para varios casos, *datasets*.
   - **Sin `sleep()`**: se inyecta el reloj.
9. Añadir **pruebas basadas en propiedades** sobre el cálculo de duraciones (RQ-02, §9.2 fila «Propiedades»: duraciones, DST, medianoche).
10. Ejecutar la mutación sobre `Modules/Attendance/Domain` y subir el MSI hasta ≥ 80 % (§9.2, RQ-10). El §9.3 explica por qué: *«un `>` en lugar de `>=` produce minutos incorrectos en la nómina de alguien»*.
11. Etiquetar **toda** prueba con sus requisitos (§9.6). Una prueba sin etiqueta es una prueba que no cuenta.

**Artefactos.**

- `backend/tests/Unit/Attendance/` — pruebas de dominio, sin BD (§2)
- `backend/tests/Support/Factory/` — *factories* de dominio puro (`WorkDayFactory`, `ShiftEntryFactory`…). Viven en `tests/`, no en `app/`: son código de prueba, no de producción, y `Domain/` tiene que seguir sin depender de nada que no sea PHP puro (regla dura 1). Las de Eloquent (`Employee::factory()`) son distintas y pertenecen a `Infrastructure/Persistence/Factory/` desde la tarea 1.3

**Pruebas exigidas** (§9.5). La tarea **es** las pruebas de una funcionalidad que introduce reglas de negocio:

| Nivel | ¿Aplica? | Umbral (§9.2) |
|---|:---:|---|
| Unitaria | ✅ obligatoria | Cobertura de dominio **≥ 90 %**; suite completa dentro del presupuesto de duración de `make test-unit` (doc 02 §9.2) |
| Mutación | ✅ | **MSI ≥ 80 %** sobre `Modules/*/Domain` |
| Propiedades | ✅ | Duraciones, DST, medianoche (RQ-02) |
| Integración / Feature / E2E | — | No aplican: no hay BD ni endpoint todavía |

**Verificación.**

```bash
make test-unit                    # verde, dentro del presupuesto de duración
make mutate                       # MSI ≥ 80 % sobre Modules/*/Domain
php artisan test --coverage --min=90 --testsuite=Unit    # dominio ≥ 90 %
php artisan qa:traceability       # RN-01..09 con al menos una prueba cada uno
php artisan qa:traceability --check
```

Resultado esperado: los cuatro en verde, y la matriz mostrando `RN-01` a `RN-09`, `RQ-01`, `RQ-02` y `RF-AT-08` con prueba asociada.

**Terminado cuando** (subconjunto de §10.3):

- [ ] Pruebas en todos los niveles que la tabla del §9.5 marque como aplicables; cobertura y MSI dentro de umbral.
- [ ] Pruebas etiquetadas con los requisitos que cubren, y `qa:traceability --check` en verde.
- [ ] Convenciones del §3.5 respetadas, incluidas las siete del código de pruebas.
- [ ] PHPStan nivel 9 sin errores nuevos.
- [ ] Revisado por otra persona, o por `revisor-codigo` y validado por una persona.

> **Puerta del camino crítico.** Hasta que esta tarea esté en verde con sus tres umbrales (2 s, 90 %, MSI 80 %), no se abre la tarea 1.8. Es el aviso del §11 y no admite interpretación.

---

### Tarea 1.3 — Esquema y migraciones con **todas** las restricciones declarativas

| | |
|---|---|
| **Horas** | 6–8 |
| **Agente / Skill** | `backend-laravel` + skill **`/migracion-segura`** |
| **Requisitos** | **RN-01..03** (literal del doc 02 §11). Concuerda con el Anexo A del doc 01 (Fase 1: `RN-01..09`). También **RNF-D-04** (ninguna migración requiere parada: es el patrón *expand / migrate / contract* que aplica la skill `/migracion-segura`) |
| **Precondiciones** | **1.2** (§11.3: `1.1→1.2 ├─► 1.3→1.4`) |
| **Bloquea a** | **1.4** (§11.3) |

**Objetivo.** El esquema de PostgreSQL 17 existe con las tres restricciones declarativas del doc 01 §5.5, y una prueba de integración demuestra que la base de datos rechaza por SQL directo lo que el dominio prohíbe.

**Reglas duras aplicables.**

- **3** (todo instante en `TIMESTAMPTZ`): la skill lo repite como regla de tipos: *«Todo instante es `TIMESTAMPTZ`. Nunca `TIMESTAMP` sin zona, nunca `DATETIME`»*. `work_date` es `DATE`, sin hora.
- **5** (nada se borra ni se sobrescribe): `shift_entries` lleva `version` y `superseded_by_id` desde el principio (doc 01 §5.5), aunque las correcciones sean de la tarea 2.3. Añadirlos después sería una migración sobre tabla con datos.
- **6** (`audit_log` solo-append): la skill es explícita — *«`audit_log` es intocable»*. El usuario de aplicación tiene `INSERT` y `SELECT`, **nunca** `UPDATE` ni `DELETE`. La tabla se materializa en la tarea 2.2; los **permisos del usuario `fichaje_app`** se establecen aquí, con el esquema.
- **8** (idempotencia por `scan_id`): `scan_events.scan_id` es `UUID` **UNIQUE**. Es la restricción que hace la idempotencia real, no un `SELECT` previo.
- **9** (dos marcas de tiempo): `occurred_at` y `recorded_at`, ambas `TIMESTAMPTZ`, en `scan_events`.

**Pasos.** Skill **`/migracion-segura`**, cuyo patrón son **3 despliegues** (doc 03 §5). Aquí el esquema nace vacío, así que todo cae en **Expand**; el patrón completo se aplicará en cada cambio posterior. Orden literal de la skill:

1. **Regla absoluta.** *«Nunca se renombra ni se elimina una columna en el mismo despliegue en que se deja de usar.»* En la primera migración no hay nada que renombrar, pero el esquema se diseña ya para no tener que hacerlo: todas las columnas que el doc 01 §5.5 lista existen desde el principio.
2. **Despliegue 1 — Expand.** Crear las tablas del doc 01 §5.5 que esta fase necesita, todas con estructura nueva y compatible:

   | Tabla | Por qué en la Fase 1 | Tarea que la consume |
   |---|---|---|
   | `sites` | `shift_entries.site_id`, y la zona horaria del centro que RN-05 necesita | 1.4, 1.6 |
   | `departments` | RF-GP-01 | 1.6 |
   | `employees` | RF-GP-01, RF-GP-03, `pin_hash` para RF-AT-11 y RF-ID-06 | 1.6, 1.11, 1.12 |
   | `credentials` | RF-QR-01..03, RF-QR-06 (`printed_at`, `delivered_at`) | 1.5, 1.10 |
   | `devices` | RF-ID-04, `pending_queue_size` y `last_seen_at` para el latido | 1.5, 1.7 |
   | `scan_events` | RF-AT-01, RF-AT-07, RF-AT-09. `scan_id` UNIQUE, `intent` desde el primer día (ADR-024), `clock_skew_seconds` desde el primer día (RF-AT-10) y `flagged_for_review` desde el primer día (RF-AT-11) | 1.4 |
   | `shift_entries` | RN-01..09 | 1.4 |
   | `daily_totals` | RN-06, proyección con UNIQUE `(employee_id, work_date)` | 1.4 |
   | `users`, `roles`, `permissions`, `personal_access_tokens` | RF-ID-01..02 básicos | 1.6 |

   > **Decisión: todo el esquema de la Fase 1 se crea aquí**, en un conjunto de migraciones ordenado por dependencia de claves foráneas. El doc 02 §11 asigna a 1.3 «el esquema» sin desglosarlo, y el motivo de no partirlo es material: `shift_entries.employee_id` es clave foránea de `employees`, cuyo módulo `Workforce` es de la tarea 1.6, de modo que repartir el esquema obligaría a migraciones que se referencian hacia adelante o a crear la tabla sin su restricción y añadirla después. **Las tareas 1.5 y 1.6 no crean migraciones**: consumen las de aquí.
   >
   > Además se crea aquí `compliance_profiles` con la fila semilla `ES-hosteleria`. Ver la nota sobre el orden de las Fases 2 y 5 en la tarea 1.1.

   Fuera de esta fase: `employment_contracts` (2.8), `shift_corrections` (2.3), `incidents` (2.6), `absences` (3.10), `error_events` (5.12), `audit_log` y `audit_chain_anchors` (2.2), `license` / `support_grants` (5.3).

   > **`compliance_profiles` e `installation_settings` no están en esa lista a propósito.** Se crean **aquí** (decisiones 1-4, 2-1 y 2-2), porque la regla dura 14 exige que los umbrales legales se lean del perfil desde el primer cálculo, no desde la Fase 5. Las tareas 5.1 y 5.2 **amplían** estas dos tablas —cascada por ámbito, índices, puertos de escritura—, no las crean.

3. **Restricciones de integridad del dominio.** Van con el SQL **literal** del doc 01 §5.5 y del doc 02 §3.2. Son la última línea de defensa de RN-01, RN-02 y RN-03:

   ```sql
   CREATE EXTENSION IF NOT EXISTS btree_gist;

   -- RN-01: como máximo un turno abierto por empleado
   CREATE UNIQUE INDEX one_open_shift_per_employee
       ON shift_entries (employee_id)
       WHERE clocked_out_at IS NULL AND status NOT IN ('voided', 'superseded');

   -- RN-02: los tramos vigentes de un mismo empleado no pueden solaparse
   ALTER TABLE shift_entries ADD CONSTRAINT shift_entries_no_overlap
       EXCLUDE USING gist (
           employee_id WITH =,
           tstzrange(clocked_in_at, clocked_out_at) WITH &&
       ) WHERE (status NOT IN ('voided', 'superseded'));

   -- RN-03: la salida es posterior a la entrada
   ALTER TABLE shift_entries ADD CONSTRAINT shift_entries_chk_order
       CHECK (clocked_out_at IS NULL OR clocked_out_at > clocked_in_at);
   ```

   El §3.2 explica por qué esto no es decorativo: *«en un sistema con valor probatorio la integridad no puede depender solo del código de aplicación»*.

   > **El enum de `status` nace con `superseded` (ADR-026).** `open`|`closed`|`anomalous`|`voided`|`superseded`, y los dos predicados excluyen **los dos** estados no vigentes. No es una anticipación gratuita de la tarea 2.3: sin `superseded`, la primera corrección de la Fase 2 —que conserva la fila anterior por la regla dura 5— haría solapar la versión vieja con la nueva, `shift_entries_no_overlap` **rechazaría la corrección**, y si no lo hiciera el recálculo de `daily_totals` sumaría las dos y duplicaría los minutos del día. **Aquí el esquema nace vacío y corregirlo no toca datos**; en la Fase 2 sería una migración sobre la tabla con valor probatorio del producto.
   >
   > **`scan_events.intent` nace ahora aunque su uso llegue en la 3.5 (ADR-024).** El campo es `auto`|`break_start`|`break_end`, con `auto` por defecto, y hasta RF-AT-12 el quiosco lo envía siempre como `auto`. Se crea aquí porque el mismo campo tiene que existir en el registro de la **cola offline** (tarea 1.9), y **cambiar el esquema de IndexedDB con la cola cargada en producción es caro**: obliga a migrar peticiones pendientes de fichaje, que son registro legal sin escribir. El doc 01 §5.5 ya lo define, junto a la nota de por qué `intent` y `result` son dos campos.
   >
   > **`scan_events.clock_skew_seconds` nace ahora por el mismo motivo.** RF-AT-10 exige que el desfase de reloj **nunca rechace el fichaje** (regla dura 19), y la tarea 1.4 ya calcula `recorded_at - occurred_at` para decidirlo. La incidencia `clock_skew` que consume ese dato es de la **3.5**: si el campo no existe hasta entonces, el desfase de cada fichaje de la Fase 1 y la Fase 2 se pierde sin registrar, y la 3.5 no tiene con qué construir la incidencia hacia atrás. Nullable, entero, en segundos con signo.
   >
   > **`scan_events.flagged_for_review`** (booleano, por defecto `false`) es la misma historia con el fichaje por PIN: RF-AT-11 exige que quede marcado para revisión del responsable, y la bandeja que trabaja esa marca es la **2.5**, en la Fase 2. Sin el campo desde el primer fichaje por PIN, la bandeja nacería sin histórico que mostrar.

4. **Reglas de tipos y nombres** (skill): tablas en plural `snake_case`, claves foráneas `{singular}_id`, **restricciones e índices con nombre explícito y descriptivo** (`one_open_shift_per_employee`, no el autogenerado). `TIMESTAMPTZ` para instantes, `DATE` para `work_date`, `JSONB` con GIN si se consulta (`client_meta`), `UUID` para identificadores públicos, `BIGINT` para claves primarias internas, **enteros para duraciones, nunca coma flotante**.
5. **Especificidades de PostgreSQL** (skill): `CREATE INDEX CONCURRENTLY` con `public $withinTransaction = false;` en tablas con datos, `SET lock_timeout = '3s'` y `SET statement_timeout = '30s'`. En la migración inicial las tablas están vacías, pero los ajustes se establecen ya para que la plantilla de migraciones sea la correcta.
6. **Permisos del usuario de aplicación.** `DB_USERNAME=fichaje_app` **sin DDL** y, cuando exista `audit_log`, **sin `UPDATE` ni `DELETE`** sobre ella (Anexo B, §7.1 capa Datos, regla dura 6).
7. **Hash del DNI, no el DNI.** `employees.national_id_hash` (doc 01 §5.5, RL-08). `pgcrypto` disponible (§3.2).
8. **Verificación antes de dar por buena** (skill, cuatro comandos):

   ```bash
   php artisan db:seed --class=VolumeSeeder   # volumen realista: cientos de miles, no 10 filas
   time php artisan migrate
   php artisan migrate:rollback && php artisan migrate
   php artisan test --filter=DatabaseConstraints
   ```

   *«Una migración cuyo `down()` no se ha probado no tiene `down()`.»*
9. Escribir el **plan de despliegue** indicando qué va en cada despliegue (lista de comprobación de la skill), aunque en este caso sea uno solo.

**Artefactos.**

- `backend/database/migrations/` (§2) — una migración por tabla, con verbo en el nombre (`create_..._table`, §3.5)
- `backend/tests/Integration/Attendance/` — pruebas de restricciones contra PostgreSQL real
- `backend/database/seeders/` — **amplía el esqueleto de la tarea 0.1**, que solo pudo sembrar centros y departamentos porque el esquema de fichaje no existía. Aquí se añaden empleados, credenciales, dispositivos y los **90 días de tramos** del `VolumeSeeder`; los casos límite (turnos nocturnos, los dos cambios de hora, olvido de salida) los añade la tarea 1.4 y las correcciones la **1.15**. Vive en `backend/database/seeders/`, la ubicación estándar de Laravel: el árbol del §2 no la lista porque es infraestructura de framework, no una decisión de diseño propia, igual que no lista `bootstrap/` ni `routes/`. El §10.2 exige la semilla con casos límite y la skill menciona `VolumeSeeder`

**Pruebas exigidas** (§9.5). Esta tarea *toca el esquema y las restricciones de base de datos*:

| Nivel | ¿Aplica? | Detalle |
|---|:---:|---|
| Unitaria | — | No aplica: aquí no hay regla de negocio nueva |
| Integración | ✅ | Repositorios y **restricciones de BD reales** |
| Feature + Contrato | — | No aplica |
| Autorización negativa | — | No aplica |
| E2E | — | No aplica |

Escenario ineludible del §9.4, y el agente `qa-testing` lo subraya como *«la prueba de que la última línea de defensa existe»*:

> **Invariantes de base de datos** — Intento **directo por SQL** de crear un solape o un segundo turno abierto → la base de datos debe rechazarlo.

Etiquetas: `->group('RN-01')`, `->group('RN-02')`, `->group('RN-03')`.

**Verificación.**

```bash
time php artisan migrate                        # tiempo anotado
php artisan migrate:rollback && php artisan migrate
php artisan test --filter=DatabaseConstraints   # el SQL directo debe fallar

# Comprobación directa de que las restricciones existen
psql -c "\d+ shift_entries"                     # one_open_shift_per_employee,
                                                # shift_entries_no_overlap,
                                                # shift_entries_chk_order
psql -c "SELECT extname FROM pg_extension;"     # btree_gist presente
```

Resultado esperado: la migración aplica y revierte limpiamente, las tres restricciones aparecen en `\d+ shift_entries`, y el intento de solape por SQL directo devuelve error de PostgreSQL, **no un error de aplicación**.

**Terminado cuando** (subconjunto de §10.3):

- [ ] **Migración reversible y verificada con datos de volumen realista.**
- [ ] Pruebas de integración de las restricciones, etiquetadas con `RN-01..03`.
- [ ] Convenciones del §3.5: nombres de Laravel canónicos, restricciones con nombre explícito.
- [ ] PHPStan nivel 9 sin errores nuevos.
- [ ] Lista de comprobación de entrega de la skill `/migracion-segura` completa, incluidos `down()` probado y tiempo anotado.

---

### Tarea 1.4 — Caso de uso `RegisterScan` con idempotencia y proyección transaccional

| | |
|---|---|
| **Horas** | 8–10 |
| **Agente / Skill** | `backend-laravel` + skill **`/crear-caso-de-uso`** |
| **Requisitos** | **RF-AT-01..09** (literal del doc 02 §11). Concuerda con el Anexo A del doc 01 |
| **Precondiciones** | **1.3** (§11.3: `1.3→1.4`) y **1.14**, que crea `audit_log` (§11.3: `1.3→1.14→1.4`). Y el dominio de 1.1 y 1.2 |
| **Bloquea a** | **1.7** (§11.3: `1.3→1.4 ──► 1.7`) y **1.15**, que corrige lo que este caso de uso crea |

**Objetivo.** Existe el caso de uso que convierte un escaneo en un tramo: idempotente por `scan_id`, con la proyección `daily_totals` recalculada en la misma transacción y las dos marcas de tiempo siempre presentes.

**Reglas duras aplicables.**

- **7** (`daily_totals` es proyección reconstruible): *«se **recalcula** en la misma transacción, nunca se incrementa acumulativamente»* (RN-06, ADR-007). El handler es donde esto se cumple o se rompe.
- **8** (idempotencia por `scan_id`): UUID v7 generado en el cliente. Un reenvío devuelve la respuesta original, nunca un error ni un duplicado (RF-AT-07). **Se resuelve con el UNIQUE de `scan_events.scan_id`, no con un `SELECT` previo**, que tiene condición de carrera y bajo concurrencia crea duplicados (agente `backend-laravel`, doc 03 §4.3).
- **9** (dos marcas de tiempo): `occurred_at` viaja desde el cliente y puede venir de la cola offline; `recorded_at` lo pone el servidor. **El registro legal usa `occurred_at`** (RF-AT-09, §6).
- **2**: el handler recibe el `Clock`; no llama a `now()`.
- **17** (rechazos genéricos de tiempo constante): el resultado detallado va a `scan_events.result` y al log; al cliente, mensaje genérico.
- **19** (el quiosco nunca bloquea al empleado): el caso de uso debe aceptar un escaneo cuyo `occurred_at` esté desfasado y generar incidencia, no rechazarlo. *La incidencia `clock_skew` es de la tarea 3.5 (RF-AT-10, Anexo A); en la Fase 1 lo exigible es que el fichaje **no se rechace** y que el desfase se persista en `scan_events.clock_skew_seconds` (tarea 1.3), para que la 3.5 tenga con qué construir la incidencia hacia atrás sin perder los fichajes ya ocurridos.*
- **21** (nunca nombres de empleados en logs técnicos): el log estructurado lleva `employee_uuid`, `scan_id`, `device_id`, `trace_id`.

**Pasos.** Skill **`/crear-caso-de-uso`**, **8 pasos** (doc 03 §5), de dentro hacia fuera. *«Nunca empieces por el controlador.»*

*Antes de empezar (preámbulo de la skill):* leer `CLAUDE.md`, los `RF-AT-01..09` del doc 01 §3.1 y las `RN-01..09` del §4. Confirmar el módulo destino: `Attendance`.

1. **Dominio.** Si la operación introduce reglas nuevas, van en `Attendance/Domain/`. Aquí no debería introducir ninguna: `RN-01..09` se cerraron en 1.1. **La única regla nueva de esta tarea es RF-AT-06, el anti-rebote configurable (por defecto 60 s, `ATTENDANCE_DEBOUNCE_SECONDS`)**, y por ser una regla de negocio pertenece a `Domain/`, no al handler. Si aparece cualquier otra, el agente `backend-laravel` tiene instrucción de **parar y preguntar**, no de decidirla.
2. **Puertos.** Los declara la tarea 1.1; aquí solo se consumen los que este caso de uso necesita. De `Attendance/Application/Port/`: `WorkDayRepository`, `EventPublisher`, `CredentialResolver`, **`EmployeeDirectory`** (RN-14, el empleado de baja) y **`SiteCalendar`** (RN-05, la fecha civil en la zona del centro). De `Shared/Application/Port/`: `Clock`, `CompliancePolicyProvider` y `OperationalSettingsProvider` ([ADR-021](../docs/adr/ADR-021-clock-en-shared.md) y [ADR-025](../docs/adr/ADR-025-frontera-de-dependencias-del-nucleo.md)). Métodos mínimos y en el lenguaje del negocio. **El handler no importa nada de `Identity` ni de `Workforce`**: recibe sus implementaciones por el contenedor.
3. **Comando y handler.**
   - `Application/Command/RegisterScanCommand.php` — DTO `readonly` con los datos ya tipados: `scan_id`, payload, `occurred_at`, `device_id`, origen. **Nada de arrays asociativos.**
   - `Application/UseCase/RegisterScanHandler.php` — la orquestación en los ocho pasos que la skill fija:

     ```
     1. Abrir transacción
     2. Cargar el agregado por su repositorio
     3. Invocar el método de dominio (aquí viven las reglas, no aquí dentro)
     4. Persistir
     5. Actualizar proyecciones EN LA MISMA TRANSACCIÓN
     6. Escribir auditoría si la acción tiene relevancia legal
     7. Publicar eventos de dominio
     8. Devolver un resultado tipado
     ```

     *«El handler orquesta, no decide. Si estás escribiendo un `if` con una regla de negocio dentro del handler, esa regla pertenece al dominio.»*
   - El paso 6 escribe en `audit_log` — un fichaje **tiene relevancia legal** (RL-01). Resuelto por [ADR-032](../docs/adr/ADR-032-la-fase-1-entrega-un-sistema-legalmente-defendible.md): la tabla encadenada por hash es de la tarea **1.14**, que precede a esta. La entrada se escribe encadenada desde el primer fichaje, no sin cadena a la espera de que una tarea posterior la selle.
4. **Adaptadores.**
   - `Infrastructure/Persistence/` — `EloquentWorkDayRepository` con el mapeo explícito entre modelo de persistencia y modelo de dominio. **No se filtra un `Model` hacia arriba.**
   - `Infrastructure/Adapter/` — `LaravelEventBus`, el único adaptador de esta tarea que es de `Attendance`. `SystemClock` es de `Shared` (ADR-021); `DbCompliancePolicyProvider` y `DbOperationalSettingsProvider` son de `Product`, y `HmacSignatureVerifier` de `Identity` (ADR-025). Aquí se **consumen**, no se declaran.
   - `Infrastructure/Projection/` — el listener que mantiene `daily_totals`, invocado **dentro de la transacción** (§2, regla dura 7).
   - Registro de los enlaces en `AttendanceServiceProvider`.
5. **Contrato OpenAPI, antes que el controlador.** `POST /api/v1/scan` ya existe en `docs/api/openapi.yaml` desde 0.6; aquí se completa con la respuesta real: `{action, employee_name, today_minutes}` (§6, diagrama de secuencia), errores en `application/problem+json`, ámbito `scan:write` y cabecera **`Idempotency-Key`**.
6. **HTTP.** FormRequest con validación estricta, `ScanController` delgado, Resource con solo los campos que el ámbito de quiosco debe ver, y **Policy obligatoria**. *La ruta con su rate limiting es la tarea 1.7*; aquí se deja el controlador listo.
7. **Instrumentación.** Del §8.2: `scans_total{device,result}` (counter) y `scan_processing_duration_seconds` (histogram), span de traza que propaga el `trace_id` recibido, y log estructurado sin nombres de empleado.
8. **Pruebas, los cuatro niveles.** Tabla de la skill, cruzada con el §9.5 (ver abajo).

**Artefactos.**

- `backend/app/Modules/Attendance/Application/Command/RegisterScanCommand.php`
- `backend/app/Modules/Attendance/Application/UseCase/RegisterScanHandler.php`
- `backend/app/Modules/Attendance/Application/Port/` (completado)
- `backend/app/Modules/Attendance/Infrastructure/Persistence/`, `Infrastructure/Adapter/`, `Infrastructure/Projection/`
- `backend/app/Modules/Attendance/Http/{Request,Controller,Resource,Policy}/`
- `docs/api/openapi.yaml` (actualizado)
- `backend/database/seeders/` — **amplía la semilla con los casos límite del §10.2**: turnos nocturnos que cruzan medianoche, los dos días de cambio de hora en `Europe/Madrid` y un olvido de salida. Es esta tarea y no la 1.3 porque es la que implementa el fichaje y la que sabe qué caso límite produce cada error. *«Un dataset de datos "bonitos" oculta exactamente los errores que este dominio produce»*

**Pruebas exigidas** (§9.5). Esta tarea es una **escritura del quiosco**, la fila más exigente de la tabla:

| Nivel | ¿Aplica? | Detalle |
|---|:---:|---|
| Unitaria | ✅ | El anti-rebote de RF-AT-06 y el recálculo de RN-06 |
| Integración | ✅ | Repositorio y proyección contra PostgreSQL real; el UNIQUE de `scan_id` |
| Feature + Contrato | ✅ | El endpoint completo y Spectator contra `openapi.yaml` |
| Autorización negativa | ✅ | **Por cada rol no autorizado** |
| E2E | ✅ **+ idempotencia concurrente** | El ciclo completo (1.8, 1.9) y la prueba concurrente aquí |

Escenarios ineludibles del §9.4 que aplican:

- **Idempotencia bajo concurrencia** — 10 peticiones paralelas con el mismo `scan_id` → **exactamente un tramo, diez respuestas idénticas** (RQ-03). Etiqueta `->group('RF-AT-07', 'RQ-03')`.
- **Cambio de turno real** — 30 empleados distintos fichando simultáneamente en el mismo quiosco → un tramo por persona, sin duplicados y con `daily_totals` cuadrando con los eventos origen. *«Es el pico que ocurre a diario, no un caso de laboratorio.»*
- **Reconciliación** (parcial en esta fase) — corromper `daily_totals` y comprobar que el recálculo lo corrige. El comando `attendance:reconcile` y su alerta son de la tarea 2.7.

Del doc 01 §11, los Gherkin que esta tarea debe satisfacer: *Primer fichaje de la jornada*, *Cierre de turno con acumulado*, *Anti-rebote* (`rejected_debounce`), *Idempotencia ante reintento*, *Fichaje offline y sincronización posterior* (el lado servidor).

**Verificación.**

```bash
make quality && make test              # verificación final de la skill

php artisan test --filter=Idempotency  # 10 peticiones paralelas, un solo tramo
php artisan test --filter=ShiftChange  # 30 empleados simultáneos

# La proyección cuadra con los eventos origen
psql -c "SELECT employee_id, work_date, total_minutes FROM daily_totals;"
psql -c "SELECT employee_id, work_date, SUM(duration_minutes)
         FROM shift_entries WHERE status NOT IN ('voided','superseded')
         GROUP BY 1,2;"                # ambos resultados deben coincidir
```

Resultado esperado: `make quality && make test` en verde; la prueba concurrente con **un solo tramo y diez respuestas idénticas**; y las dos consultas SQL devolviendo exactamente lo mismo.

**Terminado cuando** (subconjunto de §10.3, y lista de la skill):

- [ ] Las reglas de negocio están en `Domain/`, no en el handler.
- [ ] La proyección se actualiza **en la misma transacción**.
- [ ] Contrato OpenAPI actualizado y validado en las pruebas.
- [ ] Autorización probada, incluido el caso negativo por rol.
- [ ] Instrumentación añadida: métrica, traza y log donde corresponda.
- [ ] Eventos con relevancia legal escriben en `audit_log`.
- [ ] Pruebas en los cuatro niveles, etiquetadas, con `qa:traceability --check` en verde.
- [ ] PHPStan nivel 9 sin errores nuevos.

---

## Prompt de arranque de credenciales y tarjetas (doc 03 §6.3 — tareas 1.5 y 1.10)

```
Implementa la emisión de credenciales y la generación de tarjetas.

Usa backend-laravel, con revisión obligatoria de seguridad-cumplimiento
antes de dar nada por terminado.

Alcance:
- Formato FH1.<key_id>.<token>.<sig> según docs/02 §5.1
- Emisión, revocación y reemisión de credenciales
- Verificación con hash_equals (tiempo constante) y rechazo genérico
- Rotación de clave con solape mediante key_id
- Generación de tarjetas PDF con endroid/qr-code: formato tarjeta de
  crédito (85,6 × 54 mm) y hoja A4 con varias por página
- Corrección de errores nivel Q, para que la tarjeta aguante una temporada
- Registro de entrega: fecha y responsable, auditado
- Panel de estado: emitida, pendiente de imprimir, pendiente de entregar
- Comandos: credentials:issue, print, print-batch, deliver, revoke,
  rotate-key, status

Requisitos innegociables:
- El token nunca se almacena en claro, solo su hash
- Todos los rechazos devuelven la misma respuesta y consumen el mismo tiempo
- El QR no contiene PII ni identificadores secuenciales
- Emisión, entrega y revocación quedan en audit_log
- NO hay credencial en móvil, ni invitaciones por correo, ni TOTP (ADR-014)

Criterio de terminado: prueba que demuestre que un payload con firma
manipulada se rechaza; prueba que demuestre que "código inexistente",
"credencial revocada" y "firma inválida" son indistinguibles desde fuera;
y una tarjeta impresa de prueba que el quiosco escanea correctamente.
```

---

### Tarea 1.5 — Módulo `Identity`: credenciales HMAC, `key_id`, revocación, tokens de dispositivo

| | |
|---|---|
| **Horas** | 8–10 |
| **Agente / Skill** | `backend-laravel`, **revisión de `seguridad-cumplimiento`** |
| **Requisitos** | **RF-QR-01..03, RF-ID-04** (literal del doc 02 §11). Del Anexo A del doc 01, la fase incluye además `RS-01..04`, que son los requisitos de seguridad que esta tarea materializa |
| **Precondiciones** | **1.2** (§11.3: `1.1→1.2 ├─► 1.5`) y el esquema de `credentials` y `devices` de 1.3 |
| **Bloquea a** | **1.10** (§11.3: `1.5 ──► 1.10`). Y de hecho al quiosco: sin credenciales no hay nada que escanear |

**Objetivo.** El sistema emite credenciales con payload `FH1.<key_id>.<token>.<sig>`, las verifica en tiempo constante con rechazo genérico, las revoca y reemite, soporta rotación de clave con solape, y emite tokens de dispositivo de ámbito restringido.

**Reglas duras aplicables.**

- **10** (el payload QR va firmado con HMAC): `FH1.<key_id>.<token>.<sig>`. **Nunca PII ni identificadores secuenciales en el QR** (RF-QR-01, ADR-005).
- **17** (rechazos genéricos y de tiempo constante): *«Nunca se revela si un código no existe, está revocado o tiene mala firma»* (RS-03). Es el criterio de terminado del prompt §6.3.
- **11** (la credencial es una tarjeta física impresa): si algo en esta tarea sugiere credencial en móvil, invitación por correo o TOTP, **para y pregunta** (ADR-014).
- **6** (auditoría): emisión, entrega y revocación **quedan en `audit_log`** (prompt §6.3, RF-QR-06).
- **21**: los logs de rechazo llevan `employee_uuid`, nunca el nombre.

**Pasos.** Sin skill asignada. Orden derivado del §5 (diseño de la credencial) y de las reglas de implementación del agente `backend-laravel`.

1. **Formato del payload**, literal del §5.1:

   ```
   FH1.<key_id>.<token>.<sig>

   FH1      Prefijo y versión del esquema (permite migrar sin ambigüedad)
   key_id   Identificador de la clave de firma (2 caracteres). Habilita rotación con solape
   token    22 caracteres base64url = 128 bits de aleatoriedad. Opaco, sin PII, no enumerable
   sig      Primeros 16 caracteres base64url de HMAC-SHA256(key[key_id], "FH1." + key_id + "." + token)

   Ejemplo: FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa
   ```

2. **Verificación en el servidor**, los **6 pasos** del §5.2, en este orden y sin atajos:
   1. Comprobar el prefijo `FH1`. Si no coincide → rechazo genérico.
   2. Resolver la clave por `key_id`. Clave desconocida o retirada → rechazo genérico.
   3. Recalcular el HMAC y comparar en **tiempo constante** (`hash_equals`).
   4. Buscar la credencial por **hash del `token`** (nunca se almacena el token en claro).
   5. Verificar que la credencial no está revocada y que el empleado está activo.
   6. **Todos los rechazos devuelven la misma respuesta y consumen el mismo tiempo** (RS-03): un atacante no puede distinguir «no existe» de «revocada» de «firma inválida». El detalle solo va al log del servidor y a `scan_events.result`.
3. Implementar `HmacSignatureVerifier` como adaptador del puerto `CredentialResolver`, **que declara `Attendance` y que implementa `Identity`** ([ADR-025](../docs/adr/ADR-025-frontera-de-dependencias-del-nucleo.md)). El dominio no conoce HMAC. Dos condiciones que hacen que esto no rompa la frontera: el adaptador vive en `Identity/Infrastructure/Adapter/` y solo importa de `Attendance\Application\Port` —de nada más de `Attendance`—, y el enlace puerto→adaptador se declara en `IdentityServiceProvider`, no en `Attendance`, que sigue sin saber quién le sirve la credencial.
4. **Emisión, revocación y reemisión** (RF-QR-03): revocación con `revoked_at` y `revoked_reason`; una credencial activa por empleado (doc 01 §5.2). RN-14: un empleado dado de baja conserva su historial, su credencial queda revocada y sus escaneos son rechazados — *RN-14 es de la Fase 2 según el Anexo A; en la Fase 1 lo exigible es que la revocación funcione*.
5. **Rotación de clave con solape**, §5.3: dos claves activas simultáneamente (`current` y `previous`) en el gestor de secretos, `QR_SIGNING_KEY_CURRENT_ID` / `QR_SIGNING_KEY_PREVIOUS_ID` del Anexo B. *«Sin `key_id` habría que reimprimir toda la plantilla en un solo día.»* **El flujo completo de rotación con reimpresión progresiva es la tarea 2.12 (RF-QR-07)**; aquí se implementa el soporte de dos claves en la verificación.
6. **Tokens de dispositivo** (RF-ID-04, RS-04): Sanctum con los ámbitos del §7.3 — `scan:write`, `roster:read`, `heartbeat:write`, caducidad **90 días** y **rotación automática al 80 % de vida**. `devices.token_hash`, nunca el token. *«Un token de quiosco comprometido no da acceso a la plantilla completa.»*
7. **Auditoría**: emisión, revocación y (en 1.10) entrega escriben en `audit_log` (prompt §6.3).
8. **Instrumentación** del §8.2: `scans_total{device,result}` distingue ya `rejected_signature`, `rejected_revoked` y `rejected_unknown` (valores de `scan_events.result`, doc 01 §5.5). El contador de rechazos por firma es lo que el Gherkin *QR falsificado* exige.
9. **Solicitar la revisión de `seguridad-cumplimiento`** antes de dar nada por terminado. Es agente de **solo lectura**: entrega hallazgos con severidad, ubicación y escenario de explotación, no correcciones.
10. Generar una **tarjeta impresa de prueba** y comprobar que el quiosco la escanea (criterio de terminado del prompt §6.3). Requiere 1.10 y 1.8: se cierra en paralelo, no antes.

**Artefactos.**

- `backend/app/Modules/Identity/Domain/` — `Credential` como agregado (doc 01 §5.2), `QrPayload` como objeto de valor (§5.3)
- `backend/app/Modules/Identity/Application/{UseCase,Port,Command}/`
- `backend/app/Modules/Identity/Infrastructure/{Persistence,Adapter}/` — `HmacSignatureVerifier`
- `backend/app/Modules/Identity/Http/`
- `backend/app/Modules/Identity/IdentityServiceProvider.php`
- Endpoints del Anexo B del doc 01 que esta tarea expone: `POST /api/v1/credentials` (emitir, rol `rrhh`), `POST /api/v1/credentials/{uuid}/revoke` (revocar, rol `rrhh`)

**Pruebas exigidas** (§9.5). La tarea *expone endpoints* y *cambia configuración con efecto en el camino de fichaje*:

| Nivel | ¿Aplica? | Detalle |
|---|:---:|---|
| Unitaria | ✅ | Construcción y validación de `QrPayload`; verificación de firma |
| Integración | ✅ | `credentials.secret_hash`, unicidad de credencial activa |
| Feature + Contrato | ✅ | `POST /credentials`, `POST /credentials/{uuid}/revoke` |
| Autorización negativa | ✅ | **Por cada rol no autorizado**: un `responsable_departamento` o un token de quiosco contra `/credentials` → 403 |
| E2E | ✅ | Se cierra con la tarjeta impresa de prueba escaneada en el quiosco |

Escenarios ineludibles del §9.4 y del agente `qa-testing`:

- **Firma HMAC inválida, `key_id` desconocido, credencial revocada, empleado dado de baja: todos devuelven la misma respuesta** y ninguno revela la causa. Etiqueta `->group('RS-03', 'RF-QR-02')`.
- **Respuesta de tiempo constante**: medir que los cuatro rechazos consumen el mismo tiempo.
- **Token de quiosco contra endpoints de gestión: rechazado** (`->group('RS-04')`).

Del doc 01 §11: *QR falsificado* (`rejected_signature`, contador incrementado, error genérico) y *Reemisión por pérdida* (la anterior deja de ser aceptada; revocación y emisión auditadas).

**Verificación.**

```bash
make quality && make test

php artisan credentials:issue {employee}       # Anexo C del doc 02
php artisan credentials:revoke {credential} --reason=

php artisan test --filter=ConstantTimeRejection
php artisan test --filter=SignatureVerification

# El token nunca en claro
psql -c "SELECT secret_hash FROM credentials LIMIT 1;"   # hash, no 22 caracteres base64url
grep -rn "token" storage/logs/                            # ningún token en claro en logs
```

Resultado esperado: los cuatro rechazos indistinguibles en respuesta y en tiempo; ningún token en claro en base de datos ni en logs; y el informe de `seguridad-cumplimiento` sin hallazgos bloqueantes.

**Terminado cuando** (subconjunto de §10.3):

- [ ] Contrato OpenAPI actualizado y validado en las pruebas.
- [ ] Autorización probada, incluido el caso negativo por rol.
- [ ] Instrumentación añadida: contador de rechazos por causa.
- [ ] Eventos con relevancia legal escriben en `audit_log` (emisión, revocación).
- [ ] Migración reversible y verificada.
- [ ] Nada específico de un cliente ha entrado en el código: la clave HMAC es configuración (§7.7).
- [ ] **Revisión de `seguridad-cumplimiento` sin hallazgos bloqueantes** (columna «Agente / Skill» del §11).

---

### Tarea 1.6 — `Workforce` básico, más autenticación de gestión mínima

**Nombre literal del doc 02 §11:** *«`Workforce` básico: empleados, departamentos, centros, alta y baja, más autenticación de gestión mínima (login y roles, **sin 2FA**)»*

| | |
|---|---|
| **Horas** | 8–10 |
| **Agente / Skill** | `backend-laravel` |
| **Requisitos** | **RF-GP-01, RF-GP-03, RF-ID-01..02 básicos, RS-13** (literal del doc 02 §11). El Anexo A del doc 01 lo confirma: `RF-ID-01..02` en la Fase 1 son *«autenticación de gestión básica, sin 2FA»*. **RS-13** se cita desde el 29 de agosto de 2026: el rastro de autenticación de este canal lo entregó la rama SSDLC (ADR-039) sobre lo construido aquí |
| **Precondiciones** | **1.2** (§11.3: `1.1→1.2 ├─► 1.6`) y el esquema de 1.3 |
| **Bloquea a** | **1.11** (§11.3: `1.6 ──► 1.11`). Y, por la nota del §11, **1.10** |

**Objetivo.** Existen empleados, departamentos y centros con alta y baja lógica, y una persona de RRHH puede entrar al panel con usuario y contraseña, sin 2FA.

**Dependencia implícita, en cita literal del doc 02 §11:**

> **Dependencia implícita que conviene hacer explícita:** la tarea 1.10 necesita que alguien pueda entrar al panel, así que la Fase 1 incluye una **autenticación de gestión mínima** (login, roles `admin`/`rrhh`, sin 2FA) dentro de 1.6. El 2FA obligatorio y el ámbito por departamento son de la tarea 2.1 y no se adelantan. Anotarlo evita el descubrimiento tardío de que el panel de estado de credenciales no tiene puerta de entrada.

**Reglas duras aplicables.**

- **5** (nada se borra): la baja de empleado es **desactivación lógica, nunca borrado** (RF-GP-03). El historial se conserva 4 años.
- **12** (el producto no depende del correo del empleado): `employees.email` es `CITEXT NULL`, **opcional** (RF-GP-01, doc 01 §5.5). Ninguna funcionalidad puede exigirlo.
- **18** (cada endpoint tiene su policy y su prueba de autorización negativa): aplica a todo el CRUD de esta tarea, sin excepciones.
- **20** (cero biometría): `employees.photo_path` existe pero la funcionalidad está **desactivada por defecto** (doc 01 §5.5, RL-08). No es biometría y no debe convertirse en ella.
- **13**: los roles del sistema son los seis de RF-ID-02, iguales para todos los clientes.

**Pasos.** Sin skill asignada. Orden derivado de las reglas de implementación del agente `backend-laravel` y del §1.6 (`Workforce` es subdominio de soporte, así que usa la **variante ligera** del hexágono, ADR-002: *«los módulos de soporte usan una variante ligera para no sobredimensionar»*).

1. **`Workforce`**: `Site`, `Department`, `Employee` con los campos del doc 01 §5.5. `employee_code` es **CITEXT UNIQUE, opaco y aleatorio** — no secuencial, porque es la mitad de la credencial del portal (RF-ID-06).
2. `sites.timezone` con `Europe/Madrid` por defecto. **Es el dato del que depende RN-05**: sin la zona del centro no se puede resolver `work_date`.
3. `national_id_hash`: hash, **no el DNI en claro** (RL-08). `pgcrypto` (§3.2).
4. Alta y baja: `status` (`active` | `suspended` | `terminated`), `hired_at`, `terminated_at`. **Desactivación lógica** (RF-GP-03).
5. **Autenticación de gestión mínima**: Laravel Sanctum (§3.1) con contraseña, política de robustez y **bloqueo por intentos** (RF-ID-01). **Sin 2FA**: `pragmarx/google2fa` se instala pero no se activa hasta 2.1.
6. **RBAC con `spatie/laravel-permission`** (§3.1) y los seis roles de RF-ID-02: `admin`, `rrhh`, `responsable_departamento`, `auditor`, `empleado`, `kiosk`. En esta fase se usan `admin` y `rrhh` (nota del §11). **El ámbito por departamento (RF-ID-03) es de 2.1 y no se adelanta.**
7. **Endpoints**, los del Anexo B del doc 01 que corresponden:

   | Endpoint | Autorización (Anexo B) | Nota |
   |---|---|---|
   | `POST /api/v1/auth/login` | público, **throttle 5 r/m** | §7.1: autenticación 5 r/m |
   | `POST /api/v1/auth/logout` | autenticado | — |
   | `GET /api/v1/auth/me` | autenticado | Usuario, rol y ámbito |
   | `GET /api/v1/employees`, `GET /api/v1/employees/{uuid}` | rol **manager+** (Anexo B). En esta fase resuelve a `admin`/`rrhh`: `responsable_departamento` no existe hasta 2.1 | RF-GP-01 |
   | `POST /api/v1/employees` (alta), `PATCH /api/v1/employees/{uuid}` | rol **rrhh+** | RF-GP-01 |
   | `POST /api/v1/employees/{uuid}/offboard` (baja) | rol **rrhh+** | RF-GP-01, RN-14 |
   | `CRUD /api/v1/departments`, `CRUD /api/v1/sites` | rol **rrhh+** | — |

   > Rol por verbo resuelto aquí y propagado al Anexo B del doc 01: el CRUD de `employees`/`departments`/`sites` no llevaba anotación de rol. Consulta separada de escritura porque **RF-ID-03 (ámbito por departamento) es de 2.1**: en Fase 1, un `responsable_departamento` no existe todavía, así que `manager+` resuelve al conjunto `{admin, rrhh}` hasta que 2.1 añada el tercer rol y su ámbito.

   **`POST /api/v1/auth/2fa/verify` NO se implementa en esta fase**: el Anexo A sitúa el 2FA en la Fase 2 (tarea 2.1).
8. Ámbitos de token del §7.3 para los roles de gestión, declarados aunque el 2FA no esté activo.
9. **Policy en cada endpoint** y su prueba negativa (regla dura 18, RQ-07).
10. **Auditoría** de todo acceso a datos personales de terceros (RS-05) y de alta y baja de empleado. *`RS-05` es Fase 2 según el Anexo A; lo que aquí no se puede hacer es diseñar de forma que después haya que retrofitar la auditoría.*

**Artefactos.**

- `backend/app/Modules/Workforce/` con la variante ligera del hexágono
- `backend/app/Modules/Identity/` — usuarios, roles y permisos (§1.6: `Identity` es quien los tiene)
- `backend/routes/api_v1.php` — rutas de `auth` y CRUD
- `docs/api/openapi.yaml` (actualizado)

**Pruebas exigidas** (§9.5). La tarea *expone endpoints*:

| Nivel | ¿Aplica? | Detalle |
|---|:---:|---|
| Unitaria | — | El CRUD no introduce regla `RN-*`. Sí la unicidad de `employee_code` (doc 01 §5.2) |
| Integración | ✅ | `employee_code` CITEXT UNIQUE, `email` nullable, baja lógica |
| Feature + Contrato | ✅ | Login, logout, me, y el CRUD |
| Autorización negativa | ✅ | **Por cada rol no autorizado**, y el token de quiosco contra el CRUD → 403 |
| E2E | ✅ | Recorrido de usuario: entrar al panel. Se cierra con 1.10 |

Escenario del doc 01 §11 que esta tarea debe satisfacer:

```gherkin
Escenario: Alta de empleado y emisión de credencial
  Dado que RRHH da de alta al empleado "Youssef", sin dirección de correo
  Cuando se confirma el alta
  Entonces el alta se completa sin error
```

Etiqueta `->group('RF-GP-01')`. **El resto del escenario (emisión, PDF, panel de estado) es de 1.5 y 1.10.**

**Verificación.**

```bash
make quality && make test

php artisan test --filter=AuthorizationNegative   # 403 por rol no autorizado
php artisan test --filter=EmployeeWithoutEmail    # alta sin correo, sin error

# El código de empleado no es secuencial
psql -c "SELECT employee_code FROM employees LIMIT 10;"
```

Resultado esperado: alta sin correo funcionando; `employee_code` opaco y no correlativo; 403 registrado para cada rol no autorizado; y **cero rastro de 2FA activo** (el 2FA es de 2.1).

**Terminado cuando** (subconjunto de §10.3):

- [ ] Contrato OpenAPI actualizado y validado.
- [ ] Autorización probada, incluido el caso negativo por rol.
- [ ] Migración reversible y verificada.
- [ ] Textos externalizados en español e inglés.
- [ ] Nada específico de un cliente ha entrado en el código.
- [ ] PHPStan nivel 9 sin errores nuevos.

---

### Tarea 1.7 — Endpoints de fichaje, lote, padrón y latido, con rate limiting

| | |
|---|---|
| **Horas** | 6–8 |
| **Agente / Skill** | `backend-laravel` + skill **`/endpoint-api`** |
| **Requisitos** | **RS-02..04** (literal del doc 02 §11). Sirven además a `RF-AT-01..09` (expuestos) y `RF-KI-03..04` (los consume el quiosco) |
| **Precondiciones** | **1.4** (§11.3: `1.3→1.4 ──► 1.7`). Y los tokens de dispositivo de 1.5 |
| **Bloquea a** | **1.8** (§11.3: `1.7 ──► 1.8→1.9`) |

**Objetivo.** Los cuatro endpoints que el quiosco necesita existen, con sus ámbitos de token, su rate limiting por zona y las reglas especiales del camino de fichaje.

**Endpoints exactos** (Anexo B del doc 01, literal):

```
POST   /api/v1/scan                        Registrar escaneo (quiosco)  [scope: kiosk]
POST   /api/v1/scan/batch                  Sincronizar cola offline     [scope: kiosk]
GET    /api/v1/kiosk/roster                Padrón mínimo cacheable      [scope: kiosk]
POST   /api/v1/kiosk/heartbeat             Latido y telemetría          [scope: kiosk]
```

`POST /api/v1/scan/pin` está en el Anexo B pero corresponde a la **tarea 1.12**. `POST /api/v1/kiosk/pair` corresponde a la **tarea 5.6** (RF-PD-06).

**Reglas duras aplicables.**

- **8** (idempotencia por `scan_id`): `Idempotency-Key` en `/scan` y `/scan/batch`. **Vía el UNIQUE de `scan_events.scan_id`, no un `SELECT` previo** (skill `endpoint-api`, sección del camino de fichaje).
- **9** (dos marcas): `occurred_at` viaja en el cuerpo; `recorded_at` lo pone el servidor.
- **17** (rechazos genéricos de tiempo constante): *«Un atacante no puede distinguir "código inexistente" de "credencial revocada" de "firma inválida" midiendo la latencia.»*
- **18** (policy y prueba negativa por endpoint): los cuatro, sin excepciones.
- **19** (el quiosco nunca bloquea al empleado): `/scan/batch` responde **207 Multi-Status** con el resultado de cada elemento (§6); un elemento fallido no invalida el lote.
- **21**: `/kiosk/roster` devuelve **solo** hash del token, nombre de pila e inicial del apellido (§7.3). Nunca la plantilla completa.

**Pasos.** Skill **`/endpoint-api`**, **8 pasos** (doc 03 §5).

1. **Contrato primero.** `docs/api/openapi.yaml`, antes que el código: ruta bajo `/api/v1` (ADR-012), `date-time` en UTC con `Z`, `uuid` para `scan_id`, respuesta con solo los campos que el ámbito de quiosco debe ver, errores en `application/problem+json`, `security` con el ámbito, **`Idempotency-Key`** en las escrituras, y **ejemplos reales** en petición y respuesta.
2. **Ruta y protección.** En `routes/api_v1.php`, con el ámbito y el limitador:

   ```php
   Route::post('/scan', ScanController::class)
       ->middleware(['auth:sanctum', 'ability:scan:write', 'throttle:scan']);
   ```

   Zonas de rate limiting del §7.1, y el limitador **por `device_id` o por credencial, no solo por IP**: *«en un hotel todos los quioscos comparten salida»*.

   | Zona | Límite (§7.1, §7.2 de Nginx) |
   |---|---|
   | Fichaje (`/api/v1/scan*`) **desde `KIOSK_VLAN_CIDR`** | **600 r/m con ráfaga de 50** |
   | Fichaje (`/api/v1/scan*`) **desde cualquier otro origen** | **30 r/m por IP con ráfaga de 10** |
   | Autenticación (`/api/v1/auth/*`) | **5 r/m** |
   | Portal | **10 r/m** |
   | Resto | **120 r/m** |

   > **La zona de fichaje son dos y esto no es un detalle de afinado.** *«Los 30 r/m por IP son un control pensado para internet, y todos los quioscos de un hotel salen por la misma IP»* (§7.1). Con una sola zona a 30 r/m, esta tarea entregaría el fichaje limitado a 0,5 req/s, **cien veces por debajo de los 50 fichajes/segundo de RNF-P-06**, y el `k6 run scan-peak.js` de la verificación de más abajo mediría el techo de Nginx en lugar de la latencia de la aplicación. `KIOSK_VLAN_CIDR` viene del `.env.example` de la tarea 0.1.

   Además, throttling de aplicación **por `device_id`, por credencial y por empleado** (§7.1, capa Aplicación; RS-02). El límite interno se eleva, **no se elimina**: RS-02 exige limitar también por IP.
3. **Validación.** FormRequest estricto: **rechaza lo desconocido en lugar de ignorarlo**. Formato de fecha, rangos y longitudes. *«La validación no es autorización: no la confundas.»*
4. **Autorización, dos comprobaciones y no una** (§7.3):
   - **Ámbito del token**: `scan:write` para `/scan` y `/scan/batch`, `roster:read` para `/kiosk/roster`, `heartbeat:write` para `/kiosk/heartbeat`.
   - **Rol y alcance** (Policy): el dispositivo solo alcanza el centro al que está vinculado.
5. **Controlador delgado.** Valida, construye el comando, invoca el handler de 1.4, devuelve el Resource. Sin lógica de negocio.
6. **Instrumentación** del §8.2: `http_request_duration_seconds{route,method,status}`, `http_requests_total`, `scans_total{device,result}`, `scan_processing_duration_seconds`, `kiosk_last_seen_seconds{device}`, `kiosk_offline_queue_size{device}` y `sync_delay_seconds{device}` — los tres últimos alimentados por `/kiosk/heartbeat` y `/scan/batch`. Span de traza que **propaga el `trace_id` recibido** del navegador del quiosco (§8.1).
7. **Cliente TypeScript.** `npm run api:generate` en `frontend-kiosk` (y en los otros dos si consumen algo). *«Si el generador produce cambios de tipo que rompen el compilado, ese es exactamente el aviso que querías tener.»*
8. **Pruebas: las cuatro obligatorias** de la skill, más la de idempotencia concurrente por ser escritura de quiosco (ver abajo).

Detalles específicos de cada endpoint, del §6:

- **`/scan/batch`**: lotes de **50**; el servidor **procesa cada uno por su `scan_id`, en orden de `occurred_at`**, no por orden de llegada; responde **207 Multi-Status**; marca incidencia si el retraso supera el umbral (RN-15, Fase 2 — en Fase 1, aceptar y no perder).
- **`/kiosk/roster`**: el mínimo del §7.3, y el quiosco lo almacena **cifrado** (RL-12).
- **`/kiosk/heartbeat`**: `devices.last_seen_at`, `app_version`, `pending_queue_size` (doc 01 §5.5). Es lo que alimenta la alerta «Quiosco sin latido > 10 min» del doc 01 §9.3 — *la alerta es de la tarea 3.2; la métrica nace aquí*.

**Artefactos.**

- `docs/api/openapi.yaml` (actualizado **antes** del código)
- `backend/routes/api_v1.php`
- `backend/app/Modules/Attendance/Http/Controller/ScanController.php`, `ScanBatchController.php`
- `backend/app/Modules/Kiosk/Http/Controller/` — `RosterController`, `HeartbeatController` (§1.6: `Kiosk` depende de `Shared` y de `Attendance` **vía caso de uso**)
- Cliente TypeScript regenerado en `frontend-kiosk/src/shared/api/`
- Configuración de zonas de rate limiting en `infra/docker/nginx/`

**Pruebas exigidas** (§9.5). **Escritura del quiosco**: los cinco niveles más idempotencia concurrente.

| Nivel | ¿Aplica? | Detalle |
|---|:---:|---|
| Unitaria | ✅ | Ordenación del lote por `occurred_at` |
| Integración | ✅ | UNIQUE de `scan_id` bajo lote repetido |
| Feature + Contrato | ✅ | Los cuatro endpoints; Spectator valida cada respuesta |
| Autorización negativa | ✅ | **Por cada rol no autorizado.** Y el caso inverso: token de quiosco contra `/api/v1/employees` → 403 |
| E2E + idempotencia concurrente | ✅ | 10 peticiones paralelas con el mismo `scan_id` |

Escenarios ineludibles del §9.4:

- **Idempotencia bajo concurrencia** (RQ-03).
- **Lote desordenado**: entrada y salida encoladas, enviadas en orden inverso, **procesadas correctamente por `occurred_at`** (agente `qa-testing`).
- **Autorización negativa** por endpoint y rol, con su registro en auditoría.
- **Respuestas de tiempo constante** en el camino de fichaje.

Presupuesto de latencia que hay que **medir, no suponer** (skill, RNF-P-02): **p95 < 150 ms**, p99 < 400 ms.

**Verificación.**

```bash
make quality && make test

# Contrato
php artisan test --testsuite=Contract

# Rate limiting en Nginx, las dos zonas.
# (a) Desde FUERA de KIOSK_VLAN_CIDR: la ráfaga 11 debe recibir 429
for i in $(seq 1 45); do curl -s -o /dev/null -w "%{http_code} " \
  -X POST https://localhost/api/v1/scan -H "Authorization: Bearer $KIOSK_TOKEN"; done

# (b) Desde DENTRO de KIOSK_VLAN_CIDR: 60 peticiones seguidas deben pasar todas
for i in $(seq 1 60); do curl -s -o /dev/null -w "%{http_code} " \
  -X POST https://localhost/api/v1/scan -H "Authorization: Bearer $KIOSK_TOKEN" \
  -H "X-Forwarded-For: 10.0.20.15"; done

# Ámbito: token de quiosco contra gestión
curl -s -o /dev/null -w "%{http_code}\n" https://localhost/api/v1/employees \
  -H "Authorization: Bearer $KIOSK_TOKEN"     # se espera 403

# Latencia
k6 run load-tests/k6/scan-peak.js             # p95 < 150 ms (RNF-P-02, RNF-P-06)
```

Resultado esperado: los cuatro endpoints validando contra el contrato; **429 al superar 30 r/m con ráfaga de 10 desde fuera de `KIOSK_VLAN_CIDR`, y ningún 429 en 60 peticiones seguidas desde dentro** (el criterio distingue la zona: dar 429 a un quiosco de la VLAN es el fallo, no el acierto); 403 para el token de quiosco fuera de su ámbito; y p95 por debajo de 150 ms. *La prueba de carga completa es de la tarea 3.6; aquí se usa para medir el presupuesto del §9.2.*

**Terminado cuando** (subconjunto de §10.3, y lista de la skill):

- [ ] `openapi.yaml` actualizado **antes** que el código, con ejemplos.
- [ ] Versionado respetado; sin cambios incompatibles en `/v1`.
- [ ] Rate limiting configurado **por dispositivo o credencial**.
- [ ] Ámbito de token **y** policy, ambos comprobados.
- [ ] Métrica, traza y log; sin nombres de empleados.
- [ ] Cliente TypeScript regenerado en los frontends que consuman el endpoint.
- [ ] Las cuatro pruebas, incluida la negativa por rol, etiquetadas, con `qa:traceability --check` en verde.
- [ ] Tiempo constante, mensaje genérico e idempotencia verificada.

---

## Prompt de arranque del quiosco offline-first (doc 03 §6.4 — tareas 1.8, 1.9 y 1.12)

```
Implementa la PWA del quiosco con el protocolo offline completo.

Usa frontend-quiosco. El protocolo está en docs/02 §6: síguelo al detalle.

Alcance:
- Escaneo continuo con @zxing/browser, control explícito del MediaStream
- Cola offline en IndexedDB con Dexie, transaccional
- scan_id (UUID v7) generado al encolar, no al enviar
- Sincronización por lotes ordenados por occurred_at, con backoff exponencial
- Confirmación local en menos de 300 ms, sin esperar a la red
- Feedback visual y sonoro diferenciado para entrada, salida y error
- Indicador permanente de conexión y pendientes
- Fichaje de respaldo por PIN de 6 dígitos, con bloqueo por intentos
- i18n español e inglés, accesibilidad AA, wake lock, aviso de privacidad

Requisitos innegociables:
- El empleado NUNCA queda bloqueado por falta de red
- La cámara se libera correctamente: esto corre 8 horas seguidas
- El padrón cacheado va cifrado y contiene el mínimo imprescindible
- Bundle crítico ≤ 250 KB gzip

Criterio de terminado: E2E de Playwright con cámara simulada que cubra
el ciclo completo — fichar sin red, verificar la cola en IndexedDB,
reconectar, y comprobar que se consolida con el occurred_at original
y no con la hora de llegada.
```

---

### Tarea 1.8 — PWA quiosco: escaneo ZXing, feedback visual y sonoro, i18n, accesibilidad

| | |
|---|---|
| **Horas** | 12–16 |
| **Agente / Skill** | `frontend-quiosco` |
| **Requisitos** | **RF-KI-01..02, RF-KI-05..06, RF-KI-09** (literal del doc 02 §11) y **RL-09** (aviso de privacidad en capas, paso 11). Concuerda con el Anexo A del doc 01 (Fase 1: `RF-KI-01..06`, `RF-KI-09`, `RL-09`) |
| **Precondiciones** | **1.7** (§11.3: `1.7 ──► 1.8→1.9`). **Y 1.2 cerrada**, por el aviso del camino crítico |
| **Bloquea a** | **1.9** (§11.3) |

**Objetivo.** La tablet ejecuta una PWA instalable a pantalla completa que escanea de forma continua, confirma con nombre, acción, hora y total del día, en dos idiomas, accesible, y con el aviso de privacidad visible.

**Aviso del camino crítico, en cita literal del doc 02 §11:**

> **Camino crítico:** 1.1 y 1.2 bloquean todo lo demás y son las más fáciles de subestimar. **No empezar la interfaz del quiosco hasta que el dominio esté cerrado y sus pruebas en verde.** Un cambio en las reglas de cálculo con el frontend construido cuesta el triple.

**Reglas duras aplicables.**

- **19** (el quiosco nunca bloquea al empleado): es el principio rector. *«El empleado nunca espera a la red»* (agente `frontend-quiosco`).
- **10**: el quiosco **verifica el formato `FH1`** localmente antes de encolar (§6), pero **no verifica la firma**: eso exige la clave, que jamás sale del servidor.
- **21**: los errores del cliente se reportan al servidor **en el latido** y acaban en `error_events` sin datos personales (RF-PD-15, tarea 5.12; el canal se prepara aquí).
- **13**: la marca es configuración (RF-PD-08, tarea 5.8). Nada de logotipos de cliente.

**Pasos.** Sin skill asignada. Orden derivado de las restricciones técnicas del agente `frontend-quiosco`, del §3.3 y del Anexo A del doc 02.

1. **PWA instalable, a pantalla completa, con *wake lock*** (RF-KI-01): `vite-plugin-pwa` + Workbox, precacheo del *app shell*, y **Screen Wake Lock API con reintento al recuperar el foco**.
2. **Escaneo continuo por cámara sin interacción del usuario** (RF-KI-02) con `@zxing/browser`. **Control explícito del `MediaStream`**: resolución, enfoque continuo y linterna si el dispositivo la expone.
3. **Liberar los recursos de cámara.** Advertencia específica del agente, que el doc 03 §4.3 destaca: *«El bucle de decodificación corre durante turnos de 8 horas. Una fuga aquí tumba la tablet a media tarde y no aparece en pruebas de 5 minutos.»* Limpieza en **`onUnmounted`** y **ante cambios de visibilidad**.
4. **`Permissions-Policy: camera=(self)`** servida por Nginx (§7.2, tarea 0.1). Sin ella la PWA **no puede acceder al dispositivo de vídeo**, y el §7.2 avisa: *«Es un fallo de configuración que se diagnostica mal y cuesta horas.»* Verificarlo explícitamente, no suponerlo.
5. **Verificación local del formato `FH1`** antes de encolar (§6, primer paso del diagrama de secuencia).
6. **Confirmación en menos de 300 ms** (§6, RNF-P-03: decodificación < 300 ms; RNF-P-01: escaneo a confirmación p95 < 800 ms). La cola es de 1.9; aquí se construye la pantalla que confirma.
7. **Feedback doble, visual y sonoro, diferenciado** para entrada, salida y error (RF-AT-05, RF-KI-06): color, **texto ≥ 24 px** y **sonidos distintos e inconfundibles**. *«En una cocina ruidosa hay que ver; en una recepción a oscuras hay que oír.»*
8. **Pantalla de confirmación** con nombre, acción, hora y total acumulado del día (RF-AT-05). Del doc 01 §11: *«Buenos días, Lucía — Entrada 07:02»* y *«Hasta luego, Lucía — Salida 11:02 · Hoy: 6 h 0 min»*. **Horas y minutos, nunca decimales.**
9. **Multiidioma** (RF-KI-05): `vue-i18n` 10 con español e inglés mínimo, **selector persistente y detección automática**. Nada de literales en los componentes.
10. **Accesibilidad** (RF-KI-06, doc 01 §6.5): contraste **≥ 4.5:1** (AA), tipografía **≥ 24 px** en mensajes de confirmación, objetivos táctiles **≥ 48 px**, operable con una sola mano y con guantes, funcional bajo iluminación baja, y **mensajes también sonoros**.
11. **Aviso de privacidad visible** en la pantalla del quiosco (RF-KI-09, RL-09): información del art. 13 RGPD en capa 1, con enlace o QR a la política completa. **No es decorativo: es un requisito legal.**
12. **Presupuesto de rendimiento** (Anexo A del doc 02, RNF-P-07), verificado **en cada build**:

    | Recurso | Presupuesto |
    |---|---|
    | JS crítico (gzip) | **≤ 250 KB** |
    | CSS (gzip) | **≤ 40 KB** |
    | LCP en tablet de gama media | **≤ 2,0 s** |
    | Interacción a confirmación de escaneo | **≤ 800 ms (p95)** |
    | Memoria en marcha 12 h | **≤ 250 MB, sin crecimiento sostenido** |
    | Consumo de batería con pantalla activa | Documentar y validar en la prueba de campo |

13. Preparar el canal de reporte de errores del cliente **en el latido** (agente `frontend-quiosco`): código, versión, `device_id` y contexto técnico. Sin datos personales.

**Artefactos.** Rutas del §2:

- `frontend-kiosk/src/features/scan/` — cámara, decodificación, feedback
- `frontend-kiosk/src/shared/api/` — cliente generado del contrato
- `frontend-kiosk/src/shared/i18n/` — ES y EN
- `frontend-kiosk/src/sw/` — service worker (Workbox)
- `frontend-kiosk/tests/{unit,e2e}/`
- `frontend-kiosk/e2e/fixtures/qr-video.y4m` — vídeo con **un QR real de prueba** (§9.4), generado a partir de una credencial de 1.5

**Pruebas exigidas** (§9.5). La tarea *tiene recorrido de usuario en el quiosco*:

| Nivel | ¿Aplica? | Detalle |
|---|:---:|---|
| Unitaria (Vitest) | ✅ | Componentes y *composables*; ≥ 70 % (§9.2) |
| Integración | — | No aplica en el cliente |
| Feature + Contrato | — | Los endpoints se probaron en 1.7 |
| Autorización negativa | — | No aplica en el cliente |
| E2E | ✅ | Playwright con cámara simulada |

Escenarios ineludibles del §9.4:

- **Cámara simulada** — Chromium con `--use-fake-device-for-media-stream --use-file-for-fake-video-capture=e2e/fixtures/qr-video.y4m`, alimentando **un vídeo generado a partir de un QR real de prueba** (RQ-04).
- **QR degradado** — vídeo con el QR **parcialmente ocluido**, para verificar que el nivel de corrección de errores Q cumple lo prometido (RF-QR-05). Del doc 01 §11: *Tarjeta deteriorada*.
- **Accesibilidad** — `@axe-core/playwright`, **0 violaciones críticas o graves** (§9.2).

Etiquetas Playwright del §9.6: `{ tag: ['@RF-KI-01', '@RF-KI-02'] }`, `['@RF-KI-05']`, `['@RF-KI-06']`, `['@RF-KI-09']`, `['@RF-AT-05']`.

**Verificación.**

```bash
npm run type-check && npm run lint && npm run test:unit && npm run build
make e2e                       # Playwright con cámara simulada

# Presupuesto de bundle
npm run build -- --report      # JS crítico ≤ 250 KB gzip, CSS ≤ 40 KB gzip

# La cabecera sin la que nada de esto funciona
curl -sI https://localhost/ | grep -i "permissions-policy"
#   se espera: camera=(self)
```

Resultado esperado: los cuatro comandos en verde, el E2E con cámara simulada decodificando el QR real y también el ocluido, `axe` sin violaciones críticas o graves, y el bundle dentro de presupuesto.

**Riesgo que no cierra ningún comando.** El Anexo A del doc 02 exige, antes de dar por buena la Fase 1, **una prueba de resistencia de 12 h en el dispositivo real**: *«El escaneo continuo por cámara durante turnos de 8 h es un caso de uso poco habitual… Las fugas de memoria en el bucle de decodificación son un fallo típico y no aparecen en pruebas cortas.»* El doc 03 §7 lo confirma como algo que **sigue necesitando una persona**. Se planifica al cerrar 1.9.

**Terminado cuando** (subconjunto de §10.3):

- [ ] Convenciones del §3.5: guía de estilo de Vue 3, `<script setup lang="ts">`, sin `any`, carpeta por *feature*.
- [ ] Textos externalizados en español e inglés.
- [ ] **Accesibilidad verificada en las pantallas nuevas** (AA, 24 px, 48 px, contraste, sonido).
- [ ] Instrumentación añadida: reporte de errores del cliente en el latido.
- [ ] Nada específico de un cliente ha entrado en el código.
- [ ] Bundle dentro del presupuesto del Anexo A.

---

### Tarea 1.9 — Cola offline Dexie con sincronización, reintentos e indicador

| | |
|---|---|
| **Horas** | 10–12 |
| **Agente / Skill** | `frontend-quiosco` |
| **Requisitos** | **RF-KI-03..04** (literal del doc 02 §11), **RN-15** (el horario offline es el `occurred_at` del dispositivo) y **RL-12** (cifrado del padrón cacheado). Sirven a `RQ-05` (ciclo completo offline → reconexión → sincronización → consolidación) |
| **Precondiciones** | **1.8** (§11.3: `1.8→1.9`) |
| **Bloquea a** | **1.12** (§11.3: `1.8→1.9 └─► 1.12`) |

**Objetivo.** El quiosco funciona sin red: encola en IndexedDB, confirma localmente, sincroniza por lotes ordenados al recuperar conexión y muestra siempre cuántos elementos quedan pendientes.

**Reglas duras aplicables.**

- **19** (el quiosco nunca bloquea al empleado): *«Ni por falta de red, ni por desfase de reloj, ni porque el padrón cacheado no reconozca la tarjeta: encola siempre, confirma localmente y, si algo no cuadra, genera una incidencia para revisión humana»* (RF-AT-10, RN-15).
- **8** (idempotencia por `scan_id`): **UUID v7 generado en el cliente al encolar, no al enviar**. Ese mismo id viaja en todos los reintentos como `Idempotency-Key`.
- **9** (dos marcas): `occurred_at` se captura al encolar y **no se recalcula al enviar**. Es lo que hace que un fichaje de las 08:00 sincronizado a las 09:30 siga siendo de las 08:00.
- **3**: el `occurred_at` que viaja es un instante UTC.

**Pasos.** Sin skill asignada. Orden derivado del **protocolo del §6**, que el prompt §6.4 manda seguir *«al detalle»*.

1. **Cola en IndexedDB con Dexie 4, transaccional** (RF-KI-03). Nunca `localStorage`: *«es síncrono, sin transacciones y con 5 MB»*.
2. **Encolar antes de confirmar**, según el diagrama del §6: decodificar → verificar formato `FH1` → resolver nombre en el **padrón cacheado (cifrado)** → encolar `{scan_id: uuidv7, payload, occurred_at, device_id, intent}` → **confirmar en pantalla en < 300 ms**. *«El empleado nunca espera a la red.»*

   > **`intent` nace en el registro de la cola desde ahora (ADR-024).** Es `'auto' | 'break_start' | 'break_end'`, y en esta fase el quiosco escribe siempre `'auto'`, que es el valor que preserva el comportamiento de un cliente que no declara intención. Se persiste ya porque **cambiar el esquema de IndexedDB con la cola cargada en producción es caro**: obliga a migrar peticiones de fichaje pendientes, que son registro legal sin escribir, en tablets que pueden estar sin red. El campo homólogo del servidor, `scan_events.intent`, lo crea la tarea 1.3, y la tarea 3.5 le da uso real con RF-AT-12. La versión del esquema de Dexie se declara con ese campo desde la v1.
3. **Padrón cacheado cifrado** (RF-KI-03, RL-12, §7.1 capa Cliente): clave **derivada del token del dispositivo**, contenido mínimo — hash del token, nombre de pila e inicial del apellido (§7.3). **Purga al desvincular el dispositivo** (doc 01 §8.1, «Divulgación»).
4. **Con conexión**: `POST /api/v1/scan` con `Idempotency-Key: scan_id`; al recibir 200, marcar como sincronizado y **actualizar la pantalla con el total real del día** (§6).
5. **Sin conexión**: **backoff exponencial 1 s, 2 s, 4 s … máximo 5 min** (§6). Indicador visible: *«3 fichajes pendientes»*.
6. **Al recuperar red**: `POST /api/v1/scan/batch` en **lotes de 50**, **ordenados por `occurred_at`**, y procesar la respuesta **207 Multi-Status** elemento a elemento (§6).
7. **Borrar solo tras confirmación explícita del servidor** (§6, garantía «No se pierde nada»).
8. **Indicador de estado de conexión permanente** (RF-KI-04): en línea / sin conexión con N pendientes. *«La plantilla debe poder confiar en lo que ve.»*
9. **Degradación honesta**: si el padrón cacheado no reconoce el token, el quiosco **igualmente encola** y avisa *«fichaje registrado, pendiente de validar»* (§6). **Nunca se rechaza un fichaje por estar sin red.**
10. Verificar las **seis garantías del protocolo** (§6), una por una:

    | Garantía | Cómo (§6) |
    |---|---|
    | **Exactamente una vez** | `scan_id` (UUID v7, generado en el cliente) con UNIQUE en `scan_events`. Un reenvío devuelve la respuesta original, no un error |
    | **Hora real preservada** | `occurred_at` viaja desde el cliente; el servidor añade `recorded_at`. El registro legal usa `occurred_at`, y ambos quedan visibles en la auditoría |
    | **Orden correcto** | El lote se procesa ordenado por `occurred_at`, no por orden de llegada. Crítico: entrada y salida offline deben aplicarse en secuencia |
    | **Desfase controlado** | Si el retraso supera el umbral, o si el reloj del dispositivo diverge del servidor, se genera una incidencia para validación humana (RN-15) |
    | **No se pierde nada** | La cola persiste en IndexedDB con transacciones. Solo se borra el elemento tras confirmación explícita del servidor |
    | **Degradación honesta** | Si el padrón cacheado no reconoce el token, el quiosco **igualmente encola** y avisa «fichaje registrado, pendiente de validar». Nunca se rechaza un fichaje por estar sin red |

    > Sobre el UUID v7, del §6: *«se elige frente a v4 porque es ordenable temporalmente, lo que mantiene la localidad del índice en `scan_events` y evita la fragmentación de páginas»*.
    >
    > La incidencia de la cuarta garantía (`clock_skew`) es de la tarea **3.5** según el Anexo A (RF-AT-10). En la Fase 1 lo exigible es que el fichaje **se acepte y no se pierda**, y que el desfase quede persistido en `scan_events.clock_skew_seconds` (tarea 1.3): sin ese dato, la 3.5 no tendría con qué construir la incidencia de los fichajes que ya ocurrieron.

11. **Actualización diferida del service worker**, que **nunca ocurre durante un cambio de turno** (agente `frontend-quiosco`). *La ventana configurable es RF-KI-07, tarea 3.12.*
12. **Presupuesto de memoria**: ≤ 250 MB en 12 h **sin crecimiento sostenido** (Anexo A). La cola no puede ser la fuga.
13. Pensar el peor escenario que el agente exige plantearse: *«tablet con 40 fichajes encolados, batería al 8 %, WiFi intermitente, y una cola de gente esperando»*.

**Artefactos.**

- `frontend-kiosk/src/features/offline/` — cola Dexie, sincronización, reintentos (§2)
- `frontend-kiosk/src/sw/` (actualizado)
- `frontend-kiosk/tests/e2e/` — ciclo offline completo

**Pruebas exigidas** (§9.5). **Escritura del quiosco**, más el ciclo offline:

| Nivel | ¿Aplica? | Detalle |
|---|:---:|---|
| Unitaria (Vitest) | ✅ | Lógica de la cola, backoff, ordenación por `occurred_at`; ≥ 70 % |
| Integración | ✅ | El lado servidor ya en 1.4 y 1.7; aquí, la consolidación real |
| Feature + Contrato | ✅ | `/scan/batch` con lotes desordenados |
| Autorización negativa | ✅ | Token de dispositivo revocado durante la cola |
| E2E + idempotencia concurrente | ✅ | **Ciclo offline completo** |

Escenarios ineludibles del §9.4 y del agente `qa-testing`:

- **Ciclo offline completo** (RQ-05) — Playwright con red desconectada: fichar, **verificar la cola en IndexedDB**, reconectar, verificar consolidación **con el `occurred_at` original y no con el de llegada**. Es el criterio de terminado del prompt §6.4.
- **Lote desordenado** — entrada y salida encoladas, enviadas en orden inverso, procesadas correctamente por `occurred_at`.

Del doc 01 §11:

```gherkin
Escenario: Fichaje offline y sincronización posterior
  Dado un quiosco sin conexión a internet
  Cuando un empleado ficha a las 08:00
  Entonces el quiosco confirma el fichaje localmente
  Y encola el evento con su scan_id y occurred_at 08:00
  Cuando se recupera la conexión a las 09:30
  Entonces el evento se sincroniza con occurred_at 08:00 y recorded_at 09:30
  Y el tramo resultante refleja la entrada a las 08:00
```

Etiquetas: `{ tag: ['@RF-KI-03', '@RF-KI-04'] }` (ejemplo literal del §9.6), más `@RQ-05`.

**Verificación.**

```bash
npm run type-check && npm run lint && npm run test:unit && npm run build
make e2e                        # ciclo offline completo con cámara simulada

# Inspección de la cola durante el corte de red (en el E2E)
#   assert: el elemento existe en IndexedDB con su scan_id y occurred_at
#   assert: tras reconectar, el tramo en servidor tiene occurred_at 08:00

# Presupuesto de memoria
#   sesión de 12 h en dispositivo real: ≤ 250 MB, sin crecimiento sostenido
```

Resultado esperado: el E2E completo en verde; el tramo consolidado con el `occurred_at` original; la cola vacía solo tras confirmación del servidor; y el indicador de pendientes reflejando el estado real en todo momento.

**Cierre de la Fase 1 en el dispositivo real.** Con 1.9 terminada se ejecuta la **prueba de resistencia de 12 h en el dispositivo real** que el Anexo A del doc 02 exige *«antes de dar por buena la Fase 1»*: escaneo continuo, memoria ≤ 250 MB sin crecimiento sostenido, y consumo de batería con pantalla activa **documentado y validado en la prueba de campo**. El doc 03 §7 lo lista entre las cosas que el andamiaje **no** resuelve.

**Terminado cuando** (subconjunto de §10.3):

- [ ] Pruebas en todos los niveles aplicables; el E2E del ciclo offline en verde.
- [ ] Pruebas etiquetadas; `qa:traceability --check` en verde.
- [ ] Convenciones del §3.5 respetadas.
- [ ] Textos externalizados en español e inglés.
- [ ] Accesibilidad verificada en las pantallas nuevas (indicador de estado incluido).
- [ ] Instrumentación: tamaño de cola reportado en el latido (`kiosk_offline_queue_size`).
- [ ] **Prueba de resistencia de 12 h en dispositivo real ejecutada** (Anexo A del doc 02).

---

### Tarea 1.10 — Generación de tarjetas en PDF, impresión masiva, registro de entrega y panel de estado

| | |
|---|---|
| **Horas** | 6–8 |
| **Agente / Skill** | `backend-laravel` + `frontend-panel` |
| **Requisitos** | **RF-QR-04..06, RF-QR-08** (literal del doc 02 §11). Concuerda con el Anexo A del doc 01 |
| **Precondiciones** | **1.5** (§11.3: `1.5 ──► 1.10`). Y **1.6**, por la dependencia implícita del §11: sin login no hay panel |
| **Bloquea a** | No figura en el camino crítico del §11.3. Derivado: es lo que hace posible que alguien **pueda fichar el primer día** |

**Objetivo.** RRHH imprime tarjetas en formato tarjeta y en A4 múltiple, registra la entrega con fecha y responsable, y ve de un vistazo quién todavía no puede fichar.

> ### Lee esto antes de empezar: [ADR-034](../docs/adr/ADR-034-el-token-nace-al-imprimir-no-al-emitir.md)
>
> **Imprimir es el acto que acuña el QR.** La emisión (tarea 1.5) crea la credencial con `key_id`, `secret_hash` y `printed_at` **a NULL**: existe, cuenta en el panel como «pendiente de imprimir» y **no puede fichar**, porque no hay hash por el que resolverla. Esta tarea es la que la convierte en tarjeta.
>
> El orden de la impresión, y no es opcional:
>
> 1. Cargar la credencial (o las del lote) y **verificar que todas están pendientes de imprimir**. Si alguna ya lo está, `409` y no se imprime **ninguna**: el lote es todo o nada.
> 2. Resolver la clave **vigente** con `QrKeyProvider` (no la de la emisión: no hay).
> 3. Generar el token en memoria con `CredentialSecretFactory` y firmarlo con `QrSigningKey::sign()`.
> 4. **Renderizar el QR y el PDF. Sin transacción abierta**: Browsershot es un proceso externo y no debe sostener bloqueos de fila.
> 5. Abrir la transacción, llamar a `Credential::printedWith($key, $secret, $now)`, persistir con un `UPDATE ... WHERE printed_at IS NULL` cuyo recuento de filas se comprueba —si es 0, alguien imprimió en paralelo: `rollback` y `409`—, publicar `CredentialPrinted` y confirmar.
> 6. Devolver el PDF en la respuesta.
>
> **Reglas que esta tarea no puede saltarse:**
>
> - **No hay reimpresión, y no se añade un `--force`.** `Credential::printedWith()` lanza `CredentialAlreadyPrinted` sobre una credencial ya impresa. Reponer una tarjeta perdida o rota es **revocar → reemitir (`POST /credentials` con `reissue`) → imprimir la nueva**, que es lo que dice el runbook. El panel puede encadenar las dos llamadas en un botón, pero son dos actos y dos asientos de auditoría.
> - **`print-batch --pending` no lleva ningún flag que lo haga reimprimir.** Su idempotencia es que la segunda pasada no encuentra nada pendiente. Es lo que impide que dos ejecuciones del mismo lote produzcan dos juegos de tarjetas con QR distinto y solo el último válido.
> - **El PDF es un instrumento al portador.** No se guarda en `storage/`, no viaja en el payload de un job en cola, no se envía por correo y no se cachea (`Cache-Control: no-store`). El endpoint lo **transmite**; el comando de consola solo escribe donde el operador pida, y el runbook debe mandar borrarlo tras imprimir. El lote es **un solo documento** con N tarjetas, no N documentos: así una sola llamada a Browsershot cubre los 60 empleados de la semilla.
> - **Si la respuesta se pierde después de confirmar**, el token es irrecuperable y esa credencial queda impresa sin que nadie tenga la tarjeta. Es el riesgo residual aceptado: se resuelve revocando (motivo: impresión fallida) y reemitiendo, y **el runbook tiene que decirlo**. `delivered_at` es lo que distingue ese caso de «el empleado la perdió».
>
> **Estados del panel de RF-QR-08**, derivados y sin columna `status`:
>
> | Estado | Condición |
> |---|---|
> | Sin credencial | El empleado no tiene ninguna fila no revocada |
> | Pendiente de imprimir | `revoked_at IS NULL AND printed_at IS NULL` |
> | Pendiente de entregar | `revoked_at IS NULL AND printed_at IS NOT NULL AND delivered_at IS NULL` |
> | Entregada | `revoked_at IS NULL AND delivered_at IS NOT NULL` |
> | Revocada | `revoked_at IS NOT NULL` |
>
> `credentials_pending_print{site}` cuenta la segunda fila; `employees_without_delivered_credential{site}` cuenta la primera, la segunda y la tercera — todas las personas que están de alta y **todavía no pueden fichar con tarjeta**.
>
> **Lo que ya está hecho y no hay que rehacer:** el agregado `Credential` con `printedWith()`, `deliveredBy()`, `isPrinted()`, `isDelivered()` e `isScannable()`; sus excepciones (`CredentialAlreadyPrinted`, `CredentialNotPrintedYet`, `CredentialAlreadyDelivered`); los CHECK `credentials_chk_minted_at_print` y `credentials_chk_delivery_is_signed`; y sus pruebas unitarias y de integración. **Lo que falta y es tuyo:** los eventos `CredentialPrinted` —lleva `key_id`, la emisión ya no— y `CredentialDelivered`, sus asientos en `audit_log`, los casos de uso, el repositorio de pendientes por centro, los cuatro endpoints, las plantillas del PDF y el panel.

**Reglas duras aplicables.**

- **11** (la credencial es una tarjeta física impresa): esta tarea **es** ADR-014 hecho producto. Si algo sugiere enviar el QR por correo o mostrarlo en el móvil, **para y pregunta**.
- **6** (auditoría): el **registro de entrega queda auditado** (RF-QR-06). El §5.5 explica por qué no es burocracia: *«es lo que distingue "la tarjeta se perdió antes de dársela" de "el empleado la perdió", que son incidencias distintas»*.
- **10**: el PDF incrusta el payload firmado, con **corrección de errores nivel Q** (RF-QR-05).
- **13**: la marca del PDF es configuración (RF-PD-08, tarea 5.8). En la Fase 1, la plantilla debe estar preparada para recibirla, no llevarla incrustada.
- **18**: policy y prueba negativa en los seis endpoints de credenciales.

**Pasos.** Sin skill asignada. Orden derivado del **ciclo de vida del §5.5**, del prompt §6.3 y del Anexo C del doc 02.

1. **Generación del QR** con `endroid/qr-code` ^5.0 (§3.1), **corrección de errores nivel Q** y tamaño mínimo garantizado (RF-QR-05, `QR_ERROR_CORRECTION=Q` del Anexo B). El §5.1 justifica el margen: *«es lo que permite que una tarjeta sobreviva una temporada de uso diario en una cocina, con roces, grasa y dobleces»*.
2. **PDF con `spatie/laravel-pdf`** (Browsershot, §3.1) en los **dos formatos** de RF-QR-04 y del prompt §6.3:
   - **Formato tarjeta de crédito: 85,6 × 54 mm**
   - **Hoja A4 con varias tarjetas por página**

   Contenido: **nombre, departamento, centro y QR** (RF-QR-04).
3. **Emisión individual y masiva** (RF-QR-04). El §5.5 explica por qué la masiva no es un lujo: *«La hoja A4 con varias tarjetas por página es lo que hace viable dar de alta a 40 personas de temporada en una tarde.»*
4. **Registro de entrega** (RF-QR-06): `credentials.printed_at`, `delivered_at`, `delivered_by_user_id` (doc 01 §5.5). RRHH marca la tarjeta como entregada, **con fecha y responsable**, y queda auditado.

   > **La entrega es un solo acto presencial y arrastra tres cosas.** Junto con la tarjeta se entregan el **PIN** —generado y mostrado una sola vez en la tarea **1.13**, que también registra su entrega— y la **hoja de instrucciones de una cara** que produce la tarea **5.11b**. El runbook `alta-nuevo-empleado.md` de esta tarea tiene que describir el acto completo, no solo la parte de la tarjeta: partirlo en tres momentos garantiza que dos de los tres no ocurran.
5. **Panel de estado de credenciales** (RF-QR-08): emitida, **pendiente de imprimir**, pendiente de entregar, revocada. El §5.5 lo justifica: *«RF-QR-08 existe para que RRHH vea de un vistazo quién no puede fichar todavía. Sin él, el problema se descubre delante del quiosco a las 06:00.»*
6. **Comandos de consola**, los siete del Anexo C del doc 02:

   ```bash
   php artisan credentials:issue {employee}         # Emite y deja pendiente de imprimir
   php artisan credentials:print {employee}         # PDF en formato tarjeta
   php artisan credentials:print-batch --site= --pending   # Impresión masiva en A4
   php artisan credentials:deliver {credential}     # Registra la entrega
   php artisan credentials:revoke {credential} --reason=
   php artisan credentials:rotate-key               # Rotación con solape
   php artisan credentials:status --pending         # Quién no puede fichar todavía
   ```

   `credentials:issue` y `credentials:revoke` son de 1.5; `credentials:rotate-key` completa su flujo en 2.12.
7. **Endpoints** del Anexo B del doc 01 que esta tarea expone:

   ```
   POST   /api/v1/credentials/{uuid}/print    Generar PDF de tarjeta       [rol: rrhh]
   POST   /api/v1/credentials/print-batch     Impresión masiva en A4       [rol: rrhh]
   POST   /api/v1/credentials/{uuid}/deliver  Registrar entrega            [rol: rrhh]
   GET    /api/v1/credentials/status          Estado de credenciales       [rol: rrhh]
   ```

8. **Pantalla del panel** (`frontend-admin/src/features/credentials/`, §2) con el estado, el disparo de impresión y el registro de entrega. Principio del agente `frontend-panel`: *«las correcciones son actos serios»* — marcar una entrega es un acto con consecuencias y debe confirmarse mostrando qué se va a registrar.
9. **Instrumentación** del §8.2: `employees_without_delivered_credential{site}` y `credentials_pending_print{site}`. El §8.2 remata: *«`employees_without_delivered_credential` es la métrica operativa de la entrega: cuenta a quienes están de alta pero todavía no pueden fichar. Debe llegar a cero antes del primer día de cada incorporación.»*
10. **Runbooks** que este cambio hace necesarios (§12): `alta-nuevo-empleado.md` (*«Alta, emisión, impresión y entrega **con la antelación necesaria**»*) y `tarjeta-perdida-o-rota.md` (*«Revocación, reemisión y reimpresión en el día»*). El §5.5 sitúa la antelación como **requisito de proceso, no de software**, que va en el runbook.
11. **Imprimir una tarjeta de prueba y escanearla en el quiosco.** Es el criterio de terminado del prompt §6.3 y no lo cierra ningún comando.

**Artefactos.**

- `backend/app/Modules/Identity/Application/UseCase/` — impresión, lote, entrega
- `backend/app/Modules/Identity/Http/` — los cuatro endpoints
- `backend/resources/views/pdf/` — plantillas Blade de los PDF, la ubicación convencional de `spatie/laravel-pdf` (§3.1). El árbol del §2 no la lista por ser infraestructura de framework, igual que `seeders/` (nota de la tarea 1.4)
- `frontend-admin/src/features/credentials/` (§2)
- `docs/runbooks/alta-nuevo-empleado.md`, `docs/runbooks/tarjeta-perdida-o-rota.md` (§12)
- `docs/api/openapi.yaml` (actualizado)

**Pruebas exigidas** (§9.5). La tarea *expone endpoints*, *genera una exportación* (el PDF) y *tiene recorrido de usuario en el panel*:

| Nivel | ¿Aplica? | Detalle |
|---|:---:|---|
| Unitaria | ✅ del cálculo | Estados de la credencial y del panel de estado |
| Integración | ✅ con volumen | Impresión masiva de un centro completo: 60 empleados de la semilla (§10.2) |
| Feature + Contrato | ✅ | Los cuatro endpoints |
| Autorización negativa | ✅ | **Por cada rol no autorizado**: `responsable_departamento`, `auditor`, `empleado` y token de quiosco → 403 |
| E2E | ✅ | Recorrido de RRHH: emitir → imprimir → entregar → ver el estado |

Del doc 01 §11:

```gherkin
Escenario: Entrega registrada de la tarjeta
  Dada una credencial impresa y pendiente de entregar
  Cuando RRHH la marca como entregada al empleado
  Entonces se registra la fecha, el empleado y el responsable de la entrega
  Y la acción queda en el trail de auditoría
```

Y la parte de *Alta de empleado y emisión de credencial* que 1.6 dejó abierta: *«queda disponible para imprimir en PDF, en formato tarjeta y en hoja A4»* y *«el panel de estado la muestra como "pendiente de imprimir"»*. Etiquetas `->group('RF-QR-04')`, `->group('RF-QR-06')`, `->group('RF-QR-08')`.

**Verificación.**

```bash
make quality && make test

php artisan credentials:print {employee}                    # PDF 85,6 × 54 mm
php artisan credentials:print-batch --site=1 --pending      # A4 múltiple
php artisan credentials:deliver {credential}
php artisan credentials:status --pending                    # quién no puede fichar todavía

# Métricas
curl -s http://localhost/metrics | grep -E \
  "employees_without_delivered_credential|credentials_pending_print"
```

Resultado esperado: los dos PDF con las medidas correctas y el QR en nivel Q; la entrega registrada con fecha y responsable **y su entrada en auditoría**; `credentials:status --pending` devolviendo exactamente a quien falta; y las dos métricas expuestas.

**Prueba manual que ningún comando sustituye:** imprimir, plastificar y **escanear la tarjeta en el quiosco**. El doc 03 §7 lo lista como algo que sigue necesitando una persona: *«Verificar que una tarjeta plastificada aguanta una temporada en una cocina.»*

**Terminado cuando** (subconjunto de §10.3):

- [ ] Contrato OpenAPI actualizado y validado.
- [ ] Autorización probada, incluido el caso negativo por rol.
- [ ] Instrumentación añadida: las dos métricas de credenciales.
- [ ] **Eventos con relevancia legal escriben en `audit_log`** (impresión, entrega).
- [ ] Textos externalizados en español e inglés.
- [ ] Accesibilidad verificada en las pantallas nuevas.
- [ ] Nada específico de un cliente ha entrado en el código: la marca del PDF es configuración.
- [ ] **Runbook actualizado**: `alta-nuevo-empleado.md` y `tarjeta-perdida-o-rota.md`.

---

### Tarea 1.11 — Portal del empleado: acceso con código y PIN, mi registro, mi exportación

| | |
|---|---|
| **Horas** | 6–8 |
| **Agente / Skill** | `frontend-portal-empleado` + `backend-laravel` |
| **Requisitos** | **RF-ID-05..08, RL-05, RS-13** (literal del doc 02 §11). Concuerda con el Anexo A del doc 01. **RS-13** se cita desde el 29 de agosto de 2026: el rastro de autenticación de este canal lo entregó la rama SSDLC (ADR-039) sobre lo construido aquí |
| **Precondiciones** | **1.6** (§11.3: `1.6 ──► 1.11`) |
| **Bloquea a** | No figura en el camino crítico del §11.3 |

**Objetivo.** Cada empleado entra con su código y su PIN, ve sus jornadas, sus tramos y sus totales, y descarga su histórico. **Existe porque la ley lo exige** (RL-05, art. 34.9 ET).

**Su razón de ser, no negociable.** El agente `frontend-portal-empleado` lo enuncia así: *«**Esta aplicación existe por obligación legal.** El art. 34.9 del Estatuto de los Trabajadores exige que la persona trabajadora pueda acceder a su propio registro de jornada (RL-05). No es una funcionalidad opcional ni un extra de producto: si el portal no funciona, el cliente incumple.»* Y RL-03: los registros permanecen a disposición de la persona trabajadora **con capacidad de entrega inmediata**.

**Reglas duras aplicables.**

- **12** (el producto no depende del correo del empleado): acceso con **código de empleado y PIN**, ADR-015. **Nada de «recupera tu contraseña por email»**: la recuperación la hace RRHH restableciendo el PIN.
- **11** (la credencial es una tarjeta física): **el portal no muestra la credencial**. Si una tarea pide añadir un QR aquí, contradice ADR-014: **para y pregunta**.
- **3** (todo instante en UTC): los datos llegan en UTC y se presentan en **la zona del centro del empleado, no la del navegador**.
- **15** (la caducidad de la licencia jamás bloquea el acceso al registro legal): el portal es registro legal. ADR-019.
- **18**: `RF-ID-07` es una policy: la sesión tiene ámbito `self:read` y **ninguna URL manipulada** llega a datos de un tercero.
- **21**: los logs de intento fallido no llevan nombres.

**No es una PWA.** El §3.3 y ADR-015 son explícitos: sin service worker, sin caché offline, sin instalación. *«Esta decisión ahorra trabajo real y elimina toda una categoría de modos de fallo.»* Si alguien lo propone, el agente tiene instrucción de **pedir el motivo concreto**.

**Pasos.** Sin skill asignada. Orden derivado de los principios del agente `frontend-portal-empleado`, del §7.5 y del Anexo B del doc 01.

1. **Tres pantallas y ninguna más**: acceso, mi registro, descarga de mi histórico.
2. **Endpoints** del Anexo B del doc 01:

   ```
   POST   /api/v1/me/login                    Acceso con código y PIN      [público, con throttle]
   GET    /api/v1/me/workdays                 Mi propio registro           [scope: self:read]
   GET    /api/v1/me/export                   Descarga de mi histórico     [scope: self:read]
   ```

   El §7.1 fija el rate limiting del portal en **10 r/m**.
3. **Ámbito `self:read`** (§7.3, RF-ID-07): sesión corta, **solo lectura**, **solo los datos del propio empleado**. Nunca datos de terceros, nunca escritura sobre el registro. *«Es lo que hace tolerable un PIN de 6 dígitos.»*
4. **Protección del PIN** (§7.5, RS-12): bloqueo temporal **creciente tras 3, 5 y 10 intentos fallidos, por empleado y por origen**; rate limiting independiente por IP; y **mensajes que no distinguen «código inexistente» de «PIN incorrecto»**. Valores del Anexo B: `PIN_MAX_ATTEMPTS=3`, `PIN_LOCKOUT_SECONDS=300`.
5. **Restringido a red interna por defecto** (RF-ID-08, `PORTAL_INTERNAL_CIDR`, aplicado por Nginx con `geo`+403 sobre `/api/v1/me/*`, igual patrón que `KIOSK_VLAN_CIDR`/`METRICS_ALLOW_CIDR`). Exponerlo a internet es **decisión explícita del cliente** (`PORTAL_INTERNAL_CIDR=0.0.0.0/0`) y activa requisitos adicionales de contraseña; la interfaz de configuración debe advertirlo (agente). **Nota de cierre de Fase 1**: la restricción de red y su documentación están implementadas (`devops-observabilidad`); los requisitos adicionales de contraseña al exponer a internet son lógica de aplicación y siguen pendientes — no bloquean el cierre porque el valor por defecto es restringido.
6. **Presentación de los números** (principio del agente, *«los números tienen consecuencias»*): **horas y minutos, nunca decimales ambiguos**; la suma de los tramos cuadra exactamente con el total de la jornada; y si un dato está pendiente de corrección o tiene una incidencia abierta, **decirlo en pantalla** en lugar de mostrar un número que cambiará mañana.
7. **Zona horaria del centro**, no del navegador (RN-04, principio del agente).
8. **Exportación de su histórico** (RF-ID-05, RL-05). El formato es **CSV en esta tarea y PDF en la 2.9**, seleccionable por `?format=` (registrado en las notas de contrato del Anexo B del doc 01). CSV cubre la portabilidad del RGPD y no arrastra Browsershot al camino crítico de la Fase 1; el PDF —que es lo que una persona presenta ante un tercero— llega cuando la tarea 2.9 monta la maquinaria de exportación. **Sin XLSX**: RF-IN-04 lo exige para los informes de gestión, donde alguien va a seguir calculando sobre la hoja, pero no aporta nada sobre CSV para el histórico personal de una persona.
9. **Responsive con prioridad al móvil**: *«la mayoría entrará desde su teléfono personal, en su tiempo libre»*. Bundle pequeño: se abre desde datos móviles propios.
10. **WCAG 2.2 AA** (doc 01 §6.5): navegación por teclado, foco visible, etiquetas en formularios, tablas con encabezados asociados, objetivos táctiles ≥ 48 px, tipografía generosa.
11. **i18n español e inglés** (§6.6).
12. **Sin telemetría de uso** (regla de conducta del agente): *«Es la pantalla donde alguien consulta sus propias horas de trabajo; el listón de minimización es alto.»*

**Artefactos.**

- `frontend-portal/src/features/{login,my-records,my-export}/` (§2)
- `backend/app/Modules/Reporting/Application/Query/` — sirve `GET /api/v1/me/workdays`. Decidido por el §1.6, que asigna a `Reporting` las *«proyecciones y consultas de lectura»*: es una consulta de lectura sobre datos de otro módulo (`Attendance`), no una regla de negocio nueva, y por eso no vive en `Attendance/Application/Query/`. La misma consulta la reutiliza **1.16** (detalle de jornada para el responsable) con el ámbito de autorización cambiado
- `backend/app/Modules/Identity/Http/` — `POST /api/v1/me/login`
- `docs/api/openapi.yaml` (actualizado)

**Pruebas exigidas** (§9.5). La tarea *expone endpoints*, *tiene recorrido de usuario en el portal* y *genera una exportación*:

| Nivel | ¿Aplica? | Detalle |
|---|:---:|---|
| Unitaria | ✅ del cálculo | Agregación de tramos y totales que se muestran |
| Integración | ✅ | Consulta con volumen: 90 días de la semilla (§10.2) |
| Feature + Contrato | ✅ | Los tres endpoints |
| Autorización negativa | ✅ | **Un empleado autenticado NO puede obtener datos de otro** manipulando la URL o el identificador. Y ningún rol de gestión entra por `/me/*` con ámbito equivocado |
| E2E | ✅ | Entrar con código y PIN, ver el registro, descargar |

Escenarios ineludibles del §9.4:

- **Bloqueo del PIN** — intentos fallidos consecutivos activan el bloqueo creciente, **por empleado y por IP** (RS-12).
- **Autorización negativa** — 403 y su registro en auditoría.

Del doc 01 §11:

```gherkin
Escenario: El empleado consulta su propio registro
  Dado un empleado con su código y su PIN
  Cuando accede al portal personal desde la red interna
  Entonces ve sus jornadas, sus tramos y sus totales
  Y puede descargar su histórico
  Y no puede acceder a datos de ningún otro empleado
```

Etiquetas: `->group('RF-ID-05', 'RL-05')`, `->group('RF-ID-06')`, `->group('RF-ID-07')`, `->group('RS-12')`.

**Verificación.**

```bash
# Frontend
npm run type-check && npm run lint && npm run test:unit && npm run build

# Backend
make quality && make test
php artisan test --filter=SelfScopeIsolation    # ninguna URL llega a datos de terceros
php artisan test --filter=PinLockout            # bloqueo creciente por empleado y por IP

# Comprobación de que no es una PWA
grep -r "vite-plugin-pwa\|workbox\|serviceWorker" frontend-portal/   # sin resultados

# La suma cuadra
#   assert en E2E: suma de los tramos mostrados == total de la jornada mostrado
```

Resultado esperado: el aislamiento de ámbito demostrado con pruebas negativas; el bloqueo del PIN funcionando en los tres escalones; cero rastro de service worker; y la suma de tramos coincidiendo **exactamente** con el total.

**Terminado cuando** (subconjunto de §10.3):

- [ ] Contrato OpenAPI actualizado y validado.
- [ ] **Autorización probada, incluido el caso negativo por rol** — y la prueba de que un empleado no alcanza datos de otro.
- [ ] Textos externalizados en español e inglés.
- [ ] **Accesibilidad verificada en las pantallas nuevas** (WCAG 2.2 AA).
- [ ] Nada específico de un cliente ha entrado en el código.
- [ ] Instrumentación añadida — sin telemetría de uso del empleado.
- [ ] PHPStan nivel 9 sin errores nuevos; `vue-tsc` sin errores.

---

### Tarea 1.12 — PIN de respaldo de 6 dígitos en el quiosco, con bloqueo por intentos

| | |
|---|---|
| **Horas** | 4–5 |
| **Agente / Skill** | `backend-laravel` + `frontend-quiosco` |
| **Requisitos** | **RF-AT-11, RS-12, RS-13** (literal del doc 02 §11). Concuerda con el Anexo A del doc 01. **RS-13** se cita desde el 29 de agosto de 2026: el rastro de autenticación de este canal lo entregó la rama SSDLC (ADR-039) sobre lo construido aquí |
| **Precondiciones** | **1.9** (§11.3: `1.8→1.9 └─► 1.12`). Y el `pin_hash` de 1.6 |
| **Bloquea a** | No figura en el camino crítico del §11.3 |

**Objetivo.** Un empleado sin su tarjeta ficha con su PIN de 6 dígitos en el quiosco, con la misma traza, marcado como origen PIN y señalado para revisión del responsable.

**Por qué no es un extra.** El doc 01 §3.1 lo dice literalmente: *«**RF-AT-11 no es un extra.** Es lo que impide que una tarjeta olvidada se convierta en una jornada sin registro y en una corrección manual.»* Y el §11.2 cuantifica el recorte: *«Un empleado sin su tarjeta no puede fichar y su jornada acaba registrada a mano. Recorte de 4 h que genera correcciones manuales a diario.»*

**Reglas duras aplicables.**

- **19** (el quiosco nunca bloquea al empleado): el PIN es la segunda vía. Un bloqueo por intentos no puede dejar a nadie sin poder fichar de ninguna forma — y por eso el fichaje por PIN **se marca para revisión**, no se rechaza.
- **17** (rechazos genéricos): los mensajes no distinguen «código inexistente» de «PIN incorrecto» (agente `frontend-portal-empleado`, mismo criterio aplicado aquí).
- **8**, **9**: el fichaje por PIN es un escaneo más: `scan_id` UUID v7, `occurred_at` / `recorded_at`, idempotencia. Encolable offline.
- **12**: el PIN es el mismo que el del portal (RF-ID-06), lo que elimina una credencial que gestionar.
- **6**: el fichaje por PIN queda auditado, con su origen.

**Pasos.** Sin skill asignada. Orden derivado del §7.5, del prompt §6.4 y del Anexo B.

1. **Endpoint** del Anexo B del doc 01:

   ```
   POST   /api/v1/scan/pin                    Fichaje por PIN de respaldo  [scope: kiosk]
   ```

   Mismas reglas del camino de fichaje que `/scan` (skill `endpoint-api`): tiempo constante, mensaje genérico, idempotencia por UNIQUE.
2. **PIN de 6 dígitos** contra `employees.pin_hash` (doc 01 §5.5). Nunca el PIN en claro, ni en base de datos, ni en logs, ni en la cola offline.
3. **Protección del §7.5**, con los valores del Anexo B:
   - `IDENTITY_PIN_MAX_ATTEMPTS=3`
   - `IDENTITY_PIN_LOCKOUT_SECONDS=300`
   - **Bloqueo temporal creciente tras 3, 5 y 10 intentos fallidos, por empleado y por origen** (§7.5).
   - **Rate limiting independiente por IP** (§7.5, RS-12).

   > **Los tres escalones, ya parametrizados** en el Anexo B del doc 02. El §7.5 fija la **forma** —bloqueo creciente tras 3, 5 y 10 intentos, por empleado y por origen— y estos son los números:
   >
   > | Fallos acumulados | Bloqueo | Variable |
   > |---|---|---|
   > | 3 | 5 min | `IDENTITY_PIN_MAX_ATTEMPTS` · `IDENTITY_PIN_LOCKOUT_SECONDS=300` |
   > | 5 | 15 min | `IDENTITY_PIN_LOCKOUT_TIER2_ATTEMPTS` · `IDENTITY_PIN_LOCKOUT_TIER2_SECONDS=900` |
   > | 10 | 60 min | `IDENTITY_PIN_LOCKOUT_TIER3_ATTEMPTS` · `IDENTITY_PIN_LOCKOUT_TIER3_SECONDS=3600` |
   > | — | El contador se reinicia sin fallos en 24 h | `IDENTITY_PIN_LOCKOUT_RESET_HOURS=24` |
   >
   > **El prefijo `IDENTITY_PIN_` es el bueno, y esto se decidió al ejecutar esta tarea.** El Anexo B las escribía como `PIN_*` a secas y la tarea 1.13 ya había construido el bloqueo sobre `IDENTITY_PIN_MAX_ATTEMPTS` / `IDENTITY_PIN_LOCKOUT_SECONDS`, con valores distintos (5 fallos / 15 min). Es decir: dos juegos de variables para el mismo control, con números distintos y **solo uno leído por la aplicación**. Se unificó en el que ya funcionaba —el que comparte prefijo con `IDENTITY_PIN_FORBIDDEN` y con la clave de configuración `identity.pin.*`— y se bajaron sus valores de serie a los 3/300 que este Anexo pedía desde el principio. El bloque `PIN_*` huérfano se borró de `.env.example`.
   >
   > Escalado geométrico: cada escalón triplica aproximadamente el anterior, lo que hace inviable el barrido de un espacio de 10⁶ sin castigar a quien se equivoca una vez. ✅ **Minutos confirmados como decisión de producto** (13 de agosto de 2026): son un equilibrio entre seguridad y no dejar a un empleado sin fichar delante del quiosco, no una medición ni un requisito legal, y quedan fijados con esa salvedad — ajustables si la operación real de un cliente aconseja otro punto de equilibrio. Lo que no es negociable es que sean configuración y no constantes.
4. **`ScanOrigin = PIN_KIOSK`** (doc 01 §5.3) y `scan_events.origin` con ese valor. **Misma traza que el QR** (RF-AT-11).
5. **Marcado para revisión del responsable** (RF-AT-11, §7.5): *«En el quiosco, el fichaje por PIN queda marcado para revisión del responsable, lo que hace visible cualquier uso anómalo.»* La bandeja donde se **trabaja** esa marca es la tarea 2.5 (RF-PA-05, `resto de la bandeja de incidencias tras ADR-032`), que sigue en la Fase 2. En la Fase 1 lo exigible es que el fichaje por PIN persista `flagged_for_review = true` en `scan_events` desde el primer día: sin ese campo, la 2.5 no tendría marcas históricas que mostrar el día que se construya la bandeja, igual que `clock_skew_seconds` para la 3.5.
6. **Pantalla del quiosco** en `frontend-kiosk/src/features/pin/` (§2): teclado numérico con objetivos táctiles ≥ 48 px, texto ≥ 24 px, operable con una mano y con guantes (doc 01 §6.5). **Encolable offline** como cualquier otro fichaje (regla dura 19).
7. **Instrumentación** del §8.2: `pin_fallback_scans_total{site}`. El §8.2 explica su valor: *«Una subida de `pin_fallback_scans_total` indica un problema con la emisión, el estado de las tarjetas o la disciplina de la plantilla. Es un termómetro barato.»*
8. **Textos en i18n**, ES y EN.

**Artefactos.**

- `backend/app/Modules/Attendance/Http/Controller/PinScanController.php`
- `backend/app/Modules/Identity/` — verificación del PIN y bloqueo por intentos
- `frontend-kiosk/src/features/pin/` (§2)
- `docs/api/openapi.yaml` (actualizado)

**Pruebas exigidas** (§9.5). **Escritura del quiosco**: los cinco niveles más idempotencia concurrente.

| Nivel | ¿Aplica? | Detalle |
|---|:---:|---|
| Unitaria | ✅ | Escalones del bloqueo creciente, con los números explícitos (3, 5, 10) |
| Integración | ✅ | `pin_hash`, contador de intentos, UNIQUE de `scan_id` |
| Feature + Contrato | ✅ | `POST /api/v1/scan/pin` |
| Autorización negativa | ✅ | **Por cada rol no autorizado**; sin ámbito de quiosco → 403 |
| E2E + idempotencia concurrente | ✅ | Fichar por PIN en el quiosco, incluido sin red |

Escenarios ineludibles del §9.4:

- **Bloqueo del PIN** — intentos fallidos consecutivos activan el bloqueo creciente, **por empleado y por IP**. Valores límite explícitos (§3.5): intento 2, 3, 4 alrededor de `PIN_MAX_ATTEMPTS=3`.
- **Respuestas de tiempo constante** y mensaje genérico.

Del doc 01 §11:

```gherkin
Escenario: Tarjeta no disponible
  Dado un empleado que llega al centro sin su tarjeta
  Cuando introduce su PIN de 6 dígitos en el quiosco
  Entonces se registra el fichaje con origen "PIN"
  Y queda marcado para revisión del responsable
```

Etiquetas: `->group('RF-AT-11')`, `->group('RS-12')`.

**Verificación.**

```bash
make quality && make test
php artisan test --filter=PinLockoutEscalation    # 3, 5 y 10 intentos
php artisan test --filter=PinScanOrigin           # scan_events.origin = PIN_KIOSK

npm run type-check && npm run lint && npm run test:unit && npm run build
make e2e                                          # fichar por PIN, con y sin red

curl -s http://localhost/metrics | grep pin_fallback_scans_total

# El PIN nunca en claro
grep -rn "pin" storage/logs/                      # ningún PIN en claro
```

Resultado esperado: los tres escalones de bloqueo activándose con los números exactos; `scan_events.origin = PIN_KIOSK` y la marca de revisión presente; el fichaje por PIN encolándose sin red igual que el QR; y **cero PIN en claro** en logs ni en IndexedDB.

**Terminado cuando** (subconjunto de §10.3):

- [ ] Contrato OpenAPI actualizado y validado.
- [ ] Autorización probada, incluido el caso negativo por rol.
- [ ] Instrumentación añadida: `pin_fallback_scans_total`.
- [ ] Eventos con relevancia legal escriben en `audit_log`.
- [ ] Textos externalizados en español e inglés.
- [ ] Accesibilidad verificada en la pantalla nueva (48 px, 24 px, contraste).
- [ ] Pruebas en los cinco niveles, etiquetadas, con `qa:traceability --check` en verde.

---

### Tarea 1.13 — Provisión, entrega y restablecimiento del PIN

| | |
|---|---|
| **Horas** | 4–5 |
| **Agente / Skill** | `backend-laravel` + `frontend-panel` |
| **Requisitos** | **RF-ID-09** (Anexo A del doc 01, Fase 1: `RF-ID-04..09`) |
| **Precondiciones** | **1.6**, que crea el módulo `Workforce` y la columna `employees.pin_hash` |
| **Bloquea a** | **1.11** (el portal hace login con código y PIN) y **1.12** (el fichaje de respaldo verifica ese mismo PIN) |

**Objetivo.** Al dar de alta a un empleado se genera un PIN aleatorio de 6 dígitos, se muestra **una sola vez** para su entrega, se guarda únicamente como `pin_hash`, RRHH puede restablecerlo, y la emisión, la entrega y el restablecimiento quedan en `audit_log`.

> **Por qué esta tarea existe y por qué está aquí.** La tarea 1.6 crea la columna `pin_hash`, la 1.11 hace login con ella y la 1.12 ficha con ella — y **nadie la rellenaba nunca**. Sin esto, el E2E de la 1.11 («entrar con código y PIN») no es ejecutable, y el fichaje de respaldo del quiosco no tiene ningún PIN válido contra el que probarse. El Anexo B del doc 01 ya lo admite por escrito al añadir los dos endpoints: *«RF-ID-09. El PIN sostiene RF-AT-11 y el acceso al portal (RL-05), y ninguna tarea lo proveía.»*

**Endpoints exactos** (Anexo B del doc 01, ya en el contrato desde la tarea 0.6):

```
POST   /api/v1/employees/{uuid}/pin/reset      Restablecer el PIN            [rol: RRHH]
POST   /api/v1/employees/{uuid}/pin/deliver    Registrar la entrega del PIN  [rol: RRHH]
```

**Reglas duras aplicables.**

- **6** (`audit_log`): las **tres** acciones —emisión en el alta, restablecimiento y registro de entrega— tienen relevancia legal, porque el PIN es lo que da acceso al registro horario personal (RL-05). Se auditan con actor, momento y empleado sujeto. Resuelto por [ADR-032](../docs/adr/ADR-032-la-fase-1-entrega-un-sistema-legalmente-defendible.md): la tabla encadenada es de la tarea **1.14**, que en el orden real de ejecución precede a esta tarea aunque su número sea mayor (igual que la propia 1.13 ya precede a 1.11 y 1.12 pese a numerarlas después).
- **12** (el producto no depende del correo electrónico): **el PIN es precisamente la vía que hace eso posible** (ADR-015). Por eso la entrega es un acto presencial y registrado, no un correo. No hay «enviar PIN por email», ni enlace de recuperación, ni invitación.
- **11** (la credencial es una tarjeta física): el PIN **no es una credencial alternativa**, es el respaldo de la tarjeta y la llave del portal. No se imprime en la tarjeta: si se imprimiera, perder la tarjeta sería perder las dos cosas a la vez.
- **17** (rechazos genéricos): esta tarea no verifica PIN —eso es de la 1.12— pero **`/pin/reset` no revela si el `uuid` existe**: responde igual para un empleado inexistente que para uno sin permiso de alcance.
- **21** (nada de PII en logs): el PIN en claro **no aparece nunca** en logs, en `audit_log`, en la respuesta de una consulta posterior ni en el histórico del navegador. El `audit_log` registra que hubo emisión, no qué se emitió.

**Pasos.** Sin skill asignada; se apoya en `/endpoint-api` para los dos endpoints.

1. **Contrato primero.** Los dos endpoints ya están en `docs/api/openapi.yaml`; aquí se completan con la respuesta real. `/pin/reset` devuelve el PIN en claro **una sola vez y solo en esa respuesta**; `/pin/deliver` no devuelve PIN alguno, solo el acuse con momento y usuario que entregó.
2. **Generación.** 6 dígitos con **generador criptográficamente seguro** (`random_int`, nunca `rand` ni `mt_rand`). Se rechazan los patrones triviales —`000000`, `123456`, `111111` y las seis secuencias ascendentes o descendentes—, porque un espacio de 10⁶ con los tres primeros intentos evidentes no es un espacio de 10⁶. **Los patrones excluidos son una lista en configuración**, no constantes en el código (regla dura 13).
3. **Almacenamiento.** Solo `employees.pin_hash`, con el mismo algoritmo de hash de contraseñas del proyecto (`bcrypt`/`argon2id` según el `.env`). **Nunca el PIN en claro en base de datos**, ni siquiera cifrado y ni siquiera temporalmente.
4. **Emisión en el alta.** El caso de uso de alta de empleado de la tarea 1.6 emite el PIN **en la misma transacción**: un empleado sin PIN es un empleado que no puede fichar por respaldo ni entrar al portal, y ese estado no debe poder existir. La importación masiva de plantilla (tarea 5.5) genera uno por fila igual.
5. **Visualización de una sola vez.** El panel muestra el PIN en un diálogo, con acción explícita de «ya lo he anotado / entregado». **No se puede volver a consultar:** si se pierde, se restablece. Es lo que hace que el hash sea la única copia. La vista no lo escribe en `sessionStorage` ni en el estado persistido de Pinia.
6. **Restablecimiento.** `POST /pin/reset` genera uno nuevo, invalida el anterior por sustitución del hash y **reinicia el contador de bloqueo por intentos de la tarea 1.12** — un empleado bloqueado que pide PIN nuevo tiene que poder usarlo inmediatamente. Policy de rol RRHH, con alcance por centro.
7. **Registro de entrega.** `POST /pin/deliver` anota quién entregó, cuándo y a quién, igual que la entrega de tarjetas de la tarea 1.10. **La hoja de instrucciones del empleado (tarea 5.11b) se entrega en el mismo acto**, junto con la tarjeta: es un único momento presencial, no tres.
8. **Auditoría de las tres acciones** en `audit_log`, con `action` distinguible: `pin.issued`, `pin.reset`, `pin.delivered`.
9. **Panel** en `frontend-admin/`: columna de estado del PIN en la ficha del empleado —emitido / entregado / pendiente de entrega—, botón de restablecer con confirmación, y el diálogo de visualización única. **Nunca una columna «PIN» en el listado**: un listado se imprime, se comparte por pantalla y acaba en una captura.
10. **Instrumentación** (§8.2): `pin_resets_total{site}`. Una subida sostenida indica un problema de entrega o de formación, igual que `pin_fallback_scans_total` en la tarea 1.12.
11. **Textos en i18n**, ES y EN, incluido el aviso de que el PIN no se podrá volver a ver.

**Artefactos.**

- `backend/app/Modules/Workforce/Application/UseCase/` — `IssueEmployeePin`, `ResetEmployeePin`, `RecordPinDelivery`
- `backend/app/Modules/Workforce/Http/Controller/EmployeePinController.php` — los dos endpoints, con su Policy
- `frontend-admin/src/features/employees/` — diálogo de visualización única y estado del PIN en la ficha
- `docs/api/openapi.yaml` (actualizado **antes** del código)

**Pruebas exigidas** (§9.5). Es un **endpoint** con efecto sobre el acceso al registro legal:

| Nivel | ¿Aplica? | Detalle |
|---|:---:|---|
| Unitaria | ✅ | El generador: 6 dígitos, aleatorio seguro, patrones triviales rechazados |
| Integración | ✅ | `pin_hash` escrito y **PIN en claro ausente** de toda columna; entrada en `audit_log` |
| Feature + Contrato | ✅ | Los dos endpoints, validados por Spectator |
| Autorización negativa | ✅ | **Por cada rol no autorizado.** Un empleado no puede restablecer el PIN de otro; un token de quiosco recibe 403 |
| E2E | ✅ | Alta → PIN mostrado una vez → entrada en el portal con ese PIN (cierra el E2E de la tarea 1.11, que hoy no es ejecutable) |

Escenarios ineludibles:

- **El PIN se muestra una sola vez**: repetir la consulta del recurso del empleado **no** devuelve el PIN.
- **Cero PIN en claro** en `audit_log`, en logs, en la respuesta de `GET /employees/{uuid}` y en el almacenamiento del navegador.
- **Restablecer desbloquea**: un empleado con el bloqueo de la tarea 1.12 activo puede entrar inmediatamente con el PIN nuevo.
- **Alta sin PIN imposible**: dar de alta un empleado y comprobar que `pin_hash` nunca queda nulo.

Etiquetas: `->group('RF-ID-09')`, `->group('RL-05')`.

**Verificación.**

```bash
make quality && make test
php artisan test --filter=PinIssuance        # 6 dígitos, patrones triviales rechazados
php artisan test --filter=PinNeverInClear    # ninguna columna ni log con el PIN

npm run type-check && npm run lint && npm run build
make e2e                                     # alta → PIN → login en el portal

# El PIN nunca en claro, en ningún sitio
psql -c "SELECT pin_hash FROM employees LIMIT 5;"   # hashes, nunca 6 dígitos
grep -rn "pin" storage/logs/                        # ningún PIN en claro
psql -c "SELECT action, payload FROM audit_log WHERE action LIKE 'pin.%';"
```

Resultado esperado: el alta genera PIN, el panel lo muestra una vez y nunca más, `/pin/reset` genera uno nuevo y desbloquea, las tres acciones aparecen en `audit_log` **sin el valor**, y el E2E de la tarea 1.11 pasa de extremo a extremo por primera vez.

**Terminado cuando** (subconjunto de §10.3):

- [ ] Contrato OpenAPI actualizado **antes** que el código, con ejemplos.
- [ ] Autorización probada, incluido el caso negativo por rol.
- [ ] Las tres acciones escriben en `audit_log`, **sin el PIN en el payload**.
- [ ] Instrumentación añadida: `pin_resets_total`.
- [ ] Textos externalizados en español e inglés, incluido el aviso de visualización única.
- [ ] Pruebas en los niveles aplicables, etiquetadas con `RF-ID-09`, y `qa:traceability --check` en verde.
- [ ] Ningún camino del producto envía el PIN por correo electrónico (regla dura 12, ADR-015).

---

## Tareas 1.14–1.18 ([ADR-032](../docs/adr/ADR-032-la-fase-1-entrega-un-sistema-legalmente-defendible.md))

Numeradas al final para no romper las referencias cruzadas de 1.1–1.13. Su **orden de ejecución
real** está en el índice del principio de este documento y en el diagrama de camino crítico que
sigue a estas cinco tareas: `1.14` entre 1.3 y 1.4, `1.15`–`1.17` tras lo que corrigen y exportan,
`1.18` en paralelo desde el principio.

### Tarea 1.14 — `audit_log` encadenado, comando de verificación y permisos

| | |
|---|---|
| **Horas** | 8–10 |
| **Agente / Skill** | `backend-laravel` + `/revision-cumplimiento` |
| **Requisitos** | **RS-07** (doc 01 Anexo A, ADR-032). Sostiene **RL-01** (la 1.4 escribe en esta tabla) y **RL-15** (capacidad técnica de determinar el alcance de una brecha a partir de los logs de auditoría) |
| **Precondiciones** | **1.3** (esquema). No necesita el RBAC completo de 2.1: el actor que encadena en esta fase es el dispositivo (tokens de **1.5**) o la autenticación mínima de **1.6** |
| **Bloquea a** | **1.4**, **1.5**, **1.13**, que ya escriben en esta tabla, y **1.15** |

**Objetivo.** Existe un `audit_log` solo-append encadenado por hash, verificable a diario por comando, con alerta crítica de seguridad ante cualquier rotura y una métrica que debe permanecer siempre en cero. A partir de aquí se puede afirmar ante una inspección que el registro es **detectablemente inalterable** desde el primer fichaje (doc 02 §7.4).

**Reglas duras aplicables.**

- **6** — toda acción con relevancia legal escribe en `audit_log`, que es solo-append y encadenado por hash, y **el usuario de base de datos de la aplicación no tiene `UPDATE` ni `DELETE`** sobre esa tabla. Es el corazón de la tarea.
- **5** — la auditoría es el soporte de «nada se borra ni se sobrescribe»; la 1.15 se apoya en esto.
- **21** — el `payload` de auditoría no lleva nombres en claro donde baste `employee_uuid`.
- **3** — `occurred_at` en `TIMESTAMPTZ` UTC; la cadena se calcula sobre el valor UTC, no sobre una representación local.
- **16** — `audit_log` tiene 4 años de retención (doc 02 §8.2.1) y viaja a Inspección, no al fabricante.

**Fórmula de la cadena (literal, doc 02 §7.4).**

```
hash_n = SHA256( prev_hash || occurred_at || actor || action || subject || canonical_json(payload) )
```

> La entrada génesis usa `prev_hash = SHA256("FICHAJE-HOTEL-GENESIS")`. Un comando `compliance:verify-audit-chain` recorre la cadena a diario; cualquier rotura dispara alerta crítica de seguridad. Es lo que permite afirmar ante una inspección que el registro **es detectablemente inalterable** (RS-07), no solo que "confiamos en que nadie lo tocó".

**Pasos.** Con skill `/revision-cumplimiento` como filtro de cierre (6 bloques: A registro horario, B privacidad, C seguridad, C bis producto licenciado, D trazabilidad de auditoría, E cambios retroactivos). La implementación sigue el método de `backend-laravel`.

1. Migración de `audit_log` con el esquema literal del doc 01 §5.5 —`id`, `occurred_at` (TIMESTAMPTZ), `actor_type`, `actor_id`, `action`, `subject_type`, `subject_id`, `payload` (JSONB), `prev_hash`, `hash`, `ip` (INET), `user_agent`— **creada ya particionada por año** y acompañada de `audit_chain_anchors` ([ADR-027](../docs/adr/ADR-027-audit-log-particionado.md)):

   ```sql
   CREATE TABLE audit_log ( … , PRIMARY KEY (id, occurred_at) )
       PARTITION BY RANGE (occurred_at);

   CREATE TABLE audit_log_2026 PARTITION OF audit_log
       FOR VALUES FROM ('2026-01-01Z') TO ('2027-01-01Z');

   CREATE TABLE audit_chain_anchors (
       id BIGSERIAL PRIMARY KEY,
       partition_year INT NOT NULL UNIQUE,
       first_hash TEXT NOT NULL, last_hash TEXT NOT NULL,
       row_count BIGINT NOT NULL,
       sealed_at TIMESTAMPTZ NOT NULL, sealed_by TEXT NOT NULL
   );
   ```

   > **Por qué particionada desde el minuto cero, y no plana.** La retención de RL-02 (tarea 2.10) tiene que purgar esta tabla a los 4 años, y la regla dura 6 no da `DELETE` al usuario de aplicación sobre ella. Aunque se borrase con otro rol, **borrar filas rompe el eslabón**: la primera fila superviviente apuntaría con su `prev_hash` a una fila inexistente y `compliance:verify-audit-chain` denunciaría rotura **todos los días de forma permanente**. La purga tiene que ser `DROP PARTITION`, y **convertir después en particionada una tabla solo-append con millones de filas no es una migración trivial.**

2. Otorgar al usuario de aplicación **solo `INSERT` y `SELECT`** sobre la tabla **y sobre cada una de sus particiones** (doc 01 §5.5, «Permisos»; doc 02 Anexo B: `DB_USERNAME=fichaje_app # Sin DDL. Sin UPDATE/DELETE sobre audit_log`). Se provisiona además un **segundo rol, el de mantenimiento**, único con permiso de `DROP PARTITION`, que ejecutará la purga en la tarea **2.10**. No aparece en el `.env` de la aplicación.

   Y con la tabla particionada aparece una obligación operativa: una **tarea programada de creación de particiones**, que crea la del año `N+1` en noviembre y **alerta si falta la del año en curso**. Un `INSERT` sin partición de destino falla, y un fallo de escritura en `audit_log` bloquea la acción auditada: no puede quedarse en silencio, pero tampoco puede llegar a ocurrir.
3. Implementar la **serialización canónica y determinista** del `payload` (`/revision-cumplimiento`, bloque C). Orden de claves estable, sin espacios variables, UTF-8.
4. Implementar el cálculo de `hash_n` con la fórmula literal del §7.4 y la entrada génesis `prev_hash = SHA256("FICHAJE-HOTEL-GENESIS")`. Sin facades en `Domain/`/`Application/` (doc 02 §3.5).
5. Escritor de auditoría como puerto del módulo `Compliance`, consumido por los demás módulos vía caso de uso o evento de dominio (doc 02 §1.6).
6. Cablear el bloque D de `/revision-cumplimiento` — la lista de qué **debe** escribir en `audit_log`: crear, modificar, anular o cerrar un fichaje; emitir, imprimir, entregar, revocar o reemitir una credencial; provisionar, emparejar o revocar un dispositivo; acceder a datos personales de terceros; generar una exportación legal; cambiar roles, permisos o configuración con efecto en el cálculo de horas. *Ante la duda, sí.*
7. Comando `php artisan compliance:verify-audit-chain` (doc 02 Anexo C) y su registro en el Scheduler con ejecución **diaria** (RS-07). **El verificador entiende las anclas desde el primer día** (ADR-027): cuando encuentra una fila cuyo `prev_hash` no existe en la tabla, lo busca como `last_hash` en `audit_chain_anchors`; si encaja, informa de una purga sellada; **si no encaja, es manipulación** y salta la alerta.
8. Instrumentar `audit_chain_verification_failures_total` (counter, doc 02 §8.2) y dejarla en cero.
9. Regla de alerta y runbook. Del catálogo del doc 01 §9.3: **«Rotura de la cadena de hash de auditoría | cualquiera | Crítica (seguridad)»**. Runbook `docs/runbooks/rotura-cadena-auditoria.md` (doc 02 §12).
10. Pasar `/revision-cumplimiento` completo y adjuntar el informe.

**Artefactos.**

- `backend/app/Modules/Compliance/Domain/` — cálculo del hash y objeto de valor de la entrada de auditoría (dominio puro).
- `backend/app/Modules/Compliance/Application/Port/` — puerto del escritor de auditoría.
- `backend/app/Modules/Compliance/Infrastructure/Persistence/` — modelo solo-append y repositorio.
- `backend/database/migrations/` — `create_audit_log_table` **particionada por año**, `create_audit_chain_anchors_table`, la partición del año en curso, y los `GRANT`/`REVOKE` de los **dos** roles.
- `backend/app/Modules/Compliance/…` — tarea programada de creación de partición, comando `compliance:verify-audit-chain`.
- `infra/observability/` — regla de alerta de rotura de cadena.
- `docs/runbooks/rotura-cadena-auditoria.md`.

**Pruebas exigidas.** Tabla del §9.5: toca **esquema y restricción** → **Integración**. No expone endpoint del Anexo B en esta tarea.

- Integración: la fila génesis usa `SHA256("FICHAJE-HOTEL-GENESIS")` como `prev_hash` → `->group('RS-07')`.
- Integración: el usuario de aplicación recibe error al intentar `UPDATE` y `DELETE` sobre `audit_log` y sobre una partición directamente → `->group('RS-07')`.
- **Escenario ineludible del §9.4 «Cadena de auditoría»:** modificar una fila por SQL directo, verificar que `verify-audit-chain` la detecta → `->group('RS-07')`. Comprobar que la métrica se incrementa.
- Integración: `canonical_json` produce el mismo hash con las claves del `payload` en distinto orden de inserción → `->group('RS-07')`.
- Unitaria del cálculo de hash con vectores fijos, sin base de datos → `->group('RS-07')`.
- Integración: insertar con `occurred_at` de un año sin partición **falla de forma visible** y la acción auditada no se confirma → `->group('RS-07')`.

**Verificación.**

```bash
php artisan migrate:fresh
php artisan compliance:verify-audit-chain          # Cadena íntegra: salida OK, código 0
psql -U fichaje_app -d fichaje -c "UPDATE audit_log SET action='x' WHERE id=1;"   # debe fallar por permisos
php artisan test --group=RS-07
curl -s http://localhost/metrics | grep audit_chain_verification_failures_total    # valor 0
```

**Terminado cuando** (§10.3): Deptrac en verde · pruebas de integración y unitarias en verde con el escenario del §9.4 cubierto · trazabilidad en verde · PHPStan 9 limpio · instrumentación añadida · migración reversible y verificada con volumen realista · runbook `rotura-cadena-auditoria.md` escrito · informe de `/revision-cumplimiento` sin bloqueantes.

---

### Tarea 1.15 — Correcciones trazadas: versionado, catálogo de motivos, anulación

| | |
|---|---|
| **Horas** | 10–12 |
| **Agente / Skill** | `arquitecto-dominio` → `backend-laravel` (en ese orden) |
| **Requisitos** | **RN-13, RL-04, RF-PA-04** (doc 01 Anexo A, ADR-032). Aplica también RN-06 (el total se recalcula, nunca se incrementa) |
| **Precondiciones** | **1.14** (necesita `audit_log`) y **1.4** (necesita `shift_entries` y el caso de uso que los crea) |
| **Bloquea a** | **1.16** y **1.17** |

**Objetivo.** Una persona autorizada puede crear, modificar, cerrar o anular un tramo indicando un motivo del catálogo, y el sistema conserva la versión anterior con su autor, momento, valor previo y motivo, recalcula el total del día y deja la traza en `audit_log`. El registro original permanece consultable.

> ### Lee esto antes de empezar: [ADR-035](../docs/adr/ADR-035-la-correccion-estrena-identificador-y-no-cambia-de-jornada.md)
>
> `arquitecto-dominio` ya ha diseñado y dejado en verde la capa de dominio de esta tarea (pasos 1-5 de este documento): `WorkDay::addEntry()`, `WorkDay::correctEntry()`, `WorkDay::voidEntry()`, el objeto de valor `CorrectionReason`/`CorrectionReasonCode` (9 códigos, con `ALTA_RETROACTIVA` incluido — el Anexo C del doc 01 es la lista completa, no la de este documento si difieren), el evento `ShiftCorrected`, los puertos `WorkDayRepository::findWorkDayOfShiftEntry()` y `ShiftCorrectionLedger`, y las tres firmas de caso de uso (`CorrectShiftHandler`, `VoidShiftHandler`, `AddShiftEntryHandler`). **Lo que queda es exclusivamente infraestructura** (pasos 6-11): no rediseñes el dominio, impleméntalo.
>
> **Dos decisiones de ADR-035 que cambian la forma del contrato, no lo des por hecho:**
>
> 1. **La versión corregida estrena `uuid` propio.** `PATCH /shift-entries/{uuid}` responde con un `uuid` **distinto** del que recibió — cada versión es una fila y `shift_entries.uuid` es `UNIQUE`. La respuesta lleva los dos: el vigente (`shift_entry_uuid`) y el sustituido (`superseded_shift_entry_uuid`, nulo en alta o anulación). Un `PATCH` sobre una versión ya sustituida es `409`, no `404`.
> 2. **Corregir la entrada que abre la jornada cruzando la medianoche local se rechaza con `422`** (`CorrectionWouldChangeWorkDate`): mover horas de un día a otro son dos actos separados y auditados —anular en origen, dar de alta en destino—, no un efecto lateral de un `PATCH`.
>
> **Urgente al empezar, antes que cualquier otra cosa de esta tarea:** `EloquentWorkDayRepository` todavía no implementa `findWorkDayOfShiftEntry()`. Mientras falte, la clase incumple la interfaz del puerto y **la aplicación entera no arranca** (PHPStan y `make test` fallan con un error fatal de PHP, no con un aviso). Es el primer paso, no uno más de la lista.
>
> El resto de la firma exacta de cada puerto y caso de uso, con el detalle columna-a-columna de `ShiftCorrected` para `shift_corrections` y `audit_log`, está en los docblocks de `backend/app/Modules/Attendance/Application/Port/WorkDayRepository.php` y `ShiftCorrectionLedger.php` — son la referencia, no este resumen.

**Reglas duras aplicables.**

- **5** — nada se borra ni se sobrescribe: las correcciones crean una versión nueva y conservan la anterior con autor, momento y motivo (RN-13, RL-04). Es la razón de ser de la tarea.
- **7** — `daily_totals` se **recalcula** en la misma transacción, nunca se incrementa (RN-06, ADR-007). Una corrección que incrementase el acumulado produciría horas falsas.
- **6** — cada corrección y cada anulación escriben en `audit_log`.
- **4** — corregir la hora de un turno 22:00→06:00 no puede partirlo ni cambiar su `work_date` salvo que cambie la hora de inicio (RN-05, ADR-006).
- **2** — el momento de la corrección llega por el puerto `Clock`.
- **9** — `occurred_at` y `recorded_at` se conservan ambos.
- **1** — la regla de versionado vive en `Domain/`, no en el controlador ni en el modelo Eloquent.
- **18** — `PATCH` y `void` llevan policy y prueba negativa por rol.

**Catálogo de motivos — Anexo C del doc 01, literal.**

`OLVIDO_FICHAJE_ENTRADA`, `OLVIDO_FICHAJE_SALIDA`, `FALLO_TECNICO_QUIOSCO`, `TARJETA_NO_DISPONIBLE`, `CREDENCIAL_NO_ENTREGADA`, `ERROR_DE_ESCANEO_DUPLICADO`, `AJUSTE_ACORDADO_CON_RRHH`, `ALTA_RETROACTIVA`, `OTROS` (**obliga a texto libre de al menos 20 caracteres**).

**Pasos.** Relevo `arquitecto-dominio` → `backend-laravel`. El método de `arquitecto-dominio` (doc 03 §4.3) es: módulo → capa → invariantes con su `RN-*` → objetos de valor → puertos → firmas y casos de prueba → implementación.

1. `arquitecto-dominio`: situar la corrección en el módulo `Attendance` y en la capa de dominio, con `WorkDay` como frontera transaccional (doc 01 §5.2). Nadie toca `ShiftEntry` por fuera del agregado.
2. `arquitecto-dominio`: enunciar las invariantes con su regla — RN-13 (versión nueva, nunca sobrescritura), RN-03, RN-02, RN-01, RN-06.
3. `arquitecto-dominio`: objeto de valor `CorrectionReason` (doc 01 §5.3) que hace **inconstruible** el estado inválido: `OTROS` sin texto de ≥ 20 caracteres no compila un objeto válido.
4. `arquitecto-dominio`: puertos necesarios (`Clock`, repositorio de `WorkDay`, publicador de eventos, escritor de auditoría) y firma del caso de uso `CorrectShiftHandler`. Evento de dominio `ShiftCorrected`.
5. `qa-testing`/`backend-laravel`: pruebas unitarias que fallan antes de implementar, con los límites explícitos.
6. `backend-laravel`: actualizar `docs/api/openapi.yaml` con los endpoints del Anexo B: `POST /api/v1/shift-entries` (alta manual, rol manager+), **`PATCH /api/v1/shift-entries/{uuid}`** (corrección, rol manager+) y **`POST /api/v1/shift-entries/{uuid}/void`** (anulación, rol **rrhh+**).
7. `backend-laravel`: migración de `shift_corrections` con el esquema del doc 01 §5.5 — `id`, `shift_entry_id`, `performed_by_user_id`, `action`, `before` (JSONB), `after` (JSONB), `reason_code`, `reason_text`, `created_at` — y uso de `version` y `superseded_by_id` en `shift_entries`.
8. `backend-laravel`: una transacción por caso de uso con el **recálculo de `daily_totals` dentro** (ADR-007). Los estados **`voided` y `superseded`** están fuera del índice parcial y de la restricción de exclusión (`WHERE status NOT IN ('voided','superseded')`, ADR-026), así que anular libera el hueco y corregir no lo ocupa dos veces.

   > **Los dos estados no vigentes y por qué son dos** ([ADR-026](../docs/adr/ADR-026-la-correccion-supersede.md)). `voided` significa «este tramo no ocurrió»; `superseded` significa «ocurrió, se conserva y otra versión lo sustituye». Sin `superseded`, la regla dura 5 y la 7 chocan de frente. El enum y los predicados ya nacen así en la tarea 1.3.
9. `backend-laravel`: policies por endpoint, con `admin`/`rrhh` en esta fase (RF-ID-03 completo es de 2.1), y escritura en `audit_log` de la corrección y la anulación.
10. Instrumentar `manual_corrections_total{reason_code}` (counter, doc 02 §8.2).
11. Textos del catálogo de motivos en `i18n`, ES y EN.

**Artefactos.**

- `backend/app/Modules/Attendance/Domain/Model/` (`WorkDay`, `ShiftEntry`), `Domain/ValueObject/CorrectionReason.php`, `Domain/Event/ShiftCorrected.php`.
- `backend/app/Modules/Attendance/Application/UseCase/CorrectShiftHandler.php`.
- `backend/app/Modules/Attendance/Infrastructure/Persistence/`, `Infrastructure/Projection/`.
- `backend/app/Modules/Attendance/Http/` — controladores, FormRequests, Resources, Policies.
- `backend/database/migrations/` — `create_shift_corrections_table`.
- `docs/api/openapi.yaml`.
- `backend/database/seeders/` — correcciones con su motivo y tramos en estado `superseded` (§10.2).

**Pruebas exigidas.** §9.5: introduce/modifica **regla de negocio** → **Unitaria**; toca **esquema** → **Integración**; expone **endpoints** → **Feature + Contrato** y **autorización negativa por cada rol**.

- Unitaria: corregir no muta la versión anterior; `version` incrementa y `superseded_by_id` apunta correctamente → `->group('RN-13', 'RF-PA-04')`.
- Unitaria: `OTROS` con 19 caracteres es inválido, con 20 es válido → `->group('RF-PA-04')`.
- Unitaria: tras corregir la hora de salida, el total del día se **recalcula** → `->group('RN-06', 'RN-13')`.
- Unitaria: corregir un turno 22:00→06:00 no genera tramo artificial → `->group('RN-05', 'RF-AT-08')`.
- Integración: anular deja el hueco libre y no viola `shift_entries_no_overlap` → `->group('RN-02')`.
- **Integración (ADR-026):** corregir un tramo cerrado no viola `shift_entries_no_overlap` y el recálculo de `daily_totals` no duplica minutos → `->group('RN-02', 'RN-06', 'RN-13')`.
- Integración: la fila anterior **sigue en la tabla**, y el agregado `WorkDay` reconstruido **no la incluye** → `->group('RN-13', 'RL-04')`.
- Integración: la corrección deja fila en `shift_corrections` y entrada en `audit_log` → `->group('RL-04', 'RN-13')`.
- Feature + Contrato de `PATCH /shift-entries/{uuid}` y `POST /shift-entries/{uuid}/void` → `->group('RF-PA-04')`.
- Autorización negativa: `empleado` no puede corregir ni anular nada → `->group('RF-ID-03', 'RF-PA-04')`.
- Gherkin del doc 01 §11 «Corrección manual trazada» al pie de la letra → `->group('RN-13', 'RN-06', 'RF-PA-04')`.

**Verificación.**

```bash
make test-unit                       # Dominio en verde, dentro del presupuesto de duración
make mutate                          # MSI del dominio ≥ 80 %
php artisan test --group=RN-13
php artisan test tests/Integration/Attendance tests/Feature/Attendance tests/Contract
php artisan qa:traceability --check
```

**Terminado cuando** (§10.3): Deptrac en verde · pruebas unitarias, de integración, feature, contrato y autorización negativa · MSI dentro de umbral · trazabilidad en verde · PHPStan 9 limpio · contrato OpenAPI actualizado y validado · autorización probada en negativo por rol · instrumentación (`manual_corrections_total`) · `audit_log` escrito · migración reversible · textos en ES y EN.

---

### Tarea 1.16 — Panel: detalle de jornada

| | |
|---|---|
| **Horas** | 6–8 |
| **Agente / Skill** | `frontend-panel` |
| **Requisitos** | **RF-PA-03** (doc 01 Anexo A, ADR-032) |
| **Precondiciones** | **1.7** (endpoints de fichaje) y **1.15** (correcciones, que el detalle debe mostrar) |
| **Bloquea a** | Nada dentro de la Fase 1. La **2.5** (bandeja de incidencias) reutiliza el mismo patrón de consulta |

**Objetivo.** Un `admin`/`rrhh` ve el detalle de una jornada de cualquier empleado: todos sus tramos, totales, y el historial de correcciones con autor, momento, valor anterior y motivo. Es la primera pantalla desde la que alguien con responsabilidad de gestión puede ver el registro de otra persona — hasta esta tarea, solo el propio empleado podía verlo, desde el portal de la 1.11.

**Reglas duras aplicables.**

- **5** — la interfaz muestra el historial de versiones y el original sigue visible; nunca presenta la corrección como si fuera el dato primigenio.
- **9** — se muestran **ambas** marcas: `occurred_at` y `recorded_at`, y se explica la diferencia cuando el fichaje llegó de la cola offline.
- **3** — zonas horarias mostradas, no adivinadas.
- **4** — un turno nocturno se muestra como un solo tramo, atribuido a su jornada de inicio.
- **18** — la interfaz refleja permisos, pero la autorización real está en el servidor.

**Los principios de `frontend-panel` (doc 03 §4.3) que aquí aplican.**

1. **El dato tiene consecuencias:** nunca redondear de forma que las partes no sumen el total.
2. **Las zonas horarias se muestran y no se adivinan.**
3. **La autorización se refleja en la interfaz pero no se confía en ella.**

**Pasos.**

1. Confirmar en `docs/api/openapi.yaml` `GET /api/v1/employees/{uuid}/workdays` (Anexo B, rol manager+ | self), ya definido para el portal en la 1.11: esta tarea reutiliza el mismo endpoint con el ámbito de un `admin`/`rrhh` en vez del propio empleado.
2. Backend, si falta: la consulta ya existe desde 1.11 (módulo `Reporting`, `Application/Query/`); aquí solo cambia quién la invoca y con qué ámbito de autorización.
3. Vista de detalle de jornada: tramos con hora de entrada y salida en la zona del centro, duración por tramo, total del día que **cuadra con la suma**, y bloque de historial de versiones con autor, momento y motivo de cada corrección de la 1.15.
4. Diálogo de corrección: selector del catálogo del Anexo C del doc 01, texto libre obligatorio de ≥ 20 caracteres para `OTROS`, y **resumen "de → a" antes de confirmar**.
5. i18n ES/EN y accesibilidad AA (doc 01 §6.5).

**Artefactos.**

- `frontend-admin/src/features/workdays/`.
- `docs/api/openapi.yaml` — confirmación del endpoint compartido con 1.11.

**Pruebas exigidas.** §9.5: **recorrido de usuario** en el panel → **E2E**; consume **endpoint** → **Feature + Contrato** y **autorización negativa por rol**.

- Feature + Contrato de `GET /employees/{uuid}/workdays` con ámbito de gestión → `->group('RF-PA-03')`.
- Autorización negativa: `empleado` no puede ver el detalle de otro empleado → `->group('RF-ID-03', 'RS-04')`.
- E2E: abrir el detalle de una jornada con una corrección de la 1.15 y ver el resumen "de → a" en el historial → `tag: ['@RF-PA-03']`.
- Vitest del componente de suma de tramos, con el caso de que las partes sumen el total → `->group('RF-PA-03')`.
- Accesibilidad con `@axe-core/playwright`: 0 violaciones críticas o graves (§9.2).

**Verificación.**

```bash
make e2e -- --grep "@RF-PA-03"
npm --prefix frontend-admin run test:unit
npx vue-tsc --noEmit -p frontend-admin
```

**Terminado cuando** (§10.3): pruebas Feature, Contrato, autorización negativa y E2E en verde · convenciones del §3.5 verificadas · contrato OpenAPI actualizado · autorización probada en negativo · textos en ES y EN · accesibilidad verificada.

---

### Tarea 1.17 — Exportación legal para Inspección

| | |
|---|---|
| **Horas** | 5–6 |
| **Agente / Skill** | `backend-laravel` + `/informe-nuevo` |
| **Requisitos** | **RL-03, RL-06, RF-IN-05** (doc 01 Anexo A, ADR-032) |
| **Precondiciones** | **1.15** — la exportación legal debe incluir las correcciones con su autor y motivo |
| **Bloquea a** | Nada dentro de la Fase 1. La **2.9** (exportaciones ofimáticas de conveniencia) es hermana, no dependiente |

**Objetivo.** El sistema genera la **exportación normalizada para Inspección de Trabajo**: registro diario por trabajador y periodo, en formato tabular legible y tratable, **con las correcciones y sus motivos**. Existe el comando de consola y el runbook para generarla en menos de una hora sin depender del panel.

**Reglas duras aplicables.**

- **5** y **6** — la exportación legal muestra las correcciones con autor, momento y motivo. Un informe que las oculte no cumple.
- **9** — se exportan ambas marcas donde corresponda; el registro legal usa `occurred_at`.
- **4** — un turno nocturno aparece como un tramo, en su jornada de inicio.
- **18** — `GET /api/v1/reports/legal-export` es de rol `auditor|rrhh` (Anexo B, `rrhh` en esta fase: `auditor` es rol de 2.1) y lleva prueba negativa por cada otro rol.
- **6** — «Genera una exportación legal» está en la lista del bloque D de `/revision-cumplimiento`: se audita siempre.
- **21** — el fichero exportado contiene datos personales por su finalidad legal; el **log** de la generación no.

**Pasos.** Pasada acotada de `/informe-nuevo` (8 pasos), centrada en el formato legal y no en los ofimáticos de conveniencia (esos son de la 2.9):

1. **Pregunta exacta**: registro diario por trabajador y periodo para un requerimiento de Inspección (RF-IN-05, RL-06). Criterios de inclusión visibles en el propio fichero.
2. **Fuente**: `shift_entries` para el detalle de tramos y `shift_corrections` + `audit_log` (de la 1.14/1.15) para la trazabilidad de correcciones.
3. **Consulta** con agrupación por `work_date` y `AT TIME ZONE` de la zona del centro.
4. **Formato tabular legible y tratable, no propietario** (RL-06): CSV con streaming (`spatie/simple-excel`, doc 02 §3.1), UTF-8 con BOM, horas como texto `HH:MM`, nunca decimal.
5. **Autorización y auditoría**: ámbito en la consulta y registro en `audit_log` de quién exportó, qué periodo y qué empleados.
6. Actualizar `docs/api/openapi.yaml` con `GET /api/v1/reports/legal-export` (Anexo B, rol `auditor|rrhh`).
7. Comando `php artisan compliance:legal-export --from= --to= --employee=` (doc 02 Anexo C), para atender un requerimiento desde la consola sin depender del panel.
8. Escribir el runbook `docs/runbooks/requerimiento-inspeccion.md` — *«cómo generar la exportación legal en menos de 1 hora»*.

**Artefactos.**

- `backend/app/Modules/Compliance/…` — exportación legal (doc 01 §5.1 sitúa `LegalExport` en `Compliance`).
- Comando de consola `compliance:legal-export`.
- `docs/api/openapi.yaml`.
- `docs/runbooks/requerimiento-inspeccion.md`.
- `frontend-admin/src/features/reports/` — descarga desde el panel.

**Pruebas exigidas.** §9.5, fila «Genera un informe o exportación»: **Unitaria** + **Integración con volumen** + **Feature + Contrato** + **Autorización negativa**.

- Integración: la exportación incluye, por trabajador y día, hora de inicio y fin de cada tramo, total y **las correcciones con su autor, fecha y motivo** → `->group('RF-IN-05', 'RL-06', 'RL-04')`.
- Unitaria: formato de horas `HH:MM`, nunca decimal → `->group('RF-IN-05')`.
- Feature + Contrato de `GET /api/v1/reports/legal-export` → `->group('RF-IN-05')`.
- Autorización negativa: `empleado` no accede a la exportación legal completa → `->group('RF-ID-03', 'RL-06')`.
- Integración: la generación queda en `audit_log` con periodo y empleados → `->group('RS-05', 'RL-04')`.
- Apertura correcta en Excel y LibreOffice, con acentos → verificación manual documentada.

**Verificación.**

```bash
php artisan compliance:legal-export --from=2026-01-01 --to=2026-01-31 --employee=<uuid>
php artisan test --group=RF-IN-05
php artisan qa:traceability --check
```

**Terminado cuando** (§10.3): Deptrac en verde · unitarias, integración con volumen, feature, contrato y autorización negativa · trazabilidad en verde · PHPStan 9 limpio · contrato OpenAPI actualizado · autorización probada en negativo · generación de exportación legal auditada · textos en ES y EN · runbook `requerimiento-inspeccion.md` escrito.

---

### Tarea 1.18 — Copias cifradas, verificadas, con prueba de restauración

| | |
|---|---|
| **Horas** | 4–6 |
| **Agente / Skill** | `devops-observabilidad` |
| **Requisitos** | **RF-PR-04, RNF-D-05, RNF-D-02, RQ-09** (doc 01 Anexo A, ADR-032). RQ-09 y RNF-D-05 describen la misma prueba de restauración trimestral con dos numeraciones distintas del catálogo; RNF-D-02 es el RPO ≤ 15 min que entrega el WAL archiving del paso 1. RTO ≤ 4 h es un objetivo del doc 01 §6.2 sin identificador propio en el catálogo: se mide y se documenta en el runbook, no bloquea `qa:traceability`. Cifrado en reposo (RL-12), datos en la UE en la infraestructura del cliente (RL-14) |
| **Precondiciones** | **0.1** (entorno de Compose). No depende de ninguna otra tarea de esta fase: avanza en paralelo desde el principio |
| **Bloquea a** | Nada dentro de la Fase 1. RF-PD-10 (actualizador, tarea 5.7) exige «copia previa automática y verificada — si la copia falla, la actualización no continúa», así que se apoya en esta tarea |

**Objetivo.** Existe una copia diaria cifrada, **verificada automáticamente**, con archivado de WAL que sostiene el RPO de 15 minutos, y un simulacro de restauración automatizado que levanta la última copia en un contenedor limpio y valida integridad referencial y conteos. Existe la alerta de copia fallida con su runbook.

**Principio que gobierna la tarea** (doc 03 §4.3, `devops-observabilidad`): **una copia no verificada no es una copia.**

**Reglas duras aplicables.**

- **16** — la copia se queda en la infraestructura del cliente; el fabricante no la recibe ni la custodia.
- **21** — la salida de los scripts no imprime datos personales.
- **13** — rutas, destinos y retención son configuración (`BACKUP_PATH`, `BACKUP_ENCRYPTION_KEY`), no código.
- **6** — la restauración de una copia en producción queda registrada en el informe y en el runbook.

**Convenciones obligatorias de los scripts** (doc 02 §3.5): `set -euo pipefail` e `IFS=$'\n\t'`; formato con `shfmt -i 2`; **idempotencia**; **fallo seguro**; mensajes de error que dicen **qué hacer**; **ningún secreto en el script ni en su salida**.

**Pasos.**

1. Configurar **WAL archiving** en PostgreSQL 17 (RPO ≤ 15 min, RNF-D-02) en `infra/docker/postgres/` y en el Compose de producción.
2. Implementar `infra/scripts/backup.sh`: volcado completo, **cifrado** con `BACKUP_ENCRYPTION_KEY`, destino `BACKUP_PATH`.
3. Implementar `infra/scripts/restore.sh`: restauración con verificación previa de precondiciones.
4. Comandos `php artisan backup:run && php artisan backup:verify` que envuelvan los scripts.
5. **Simulacro de restauración automatizado**: script que restaura la última copia en un **contenedor limpio** y valida integridad referencial y conteos. Ejecución **trimestral** (RNF-D-05).
6. Instrumentar el resultado de la copia y de su verificación (métrica de resultado de copia, sección de respaldo del §8.2).
7. Alerta: **«Copia de seguridad fallida o no verificada | cualquiera | Crítica»**, con enlace a `docs/runbooks/restaurar-backup.md`.
8. Escribir `docs/runbooks/restaurar-backup.md` con el procedimiento de recuperación dentro del **RTO de 4 h**.

**Artefactos.**

- `infra/scripts/backup.sh`, `infra/scripts/restore.sh`.
- `infra/docker/postgres/` — configuración de WAL archiving.
- `infra/observability/` — reglas de alerta de copia fallida y de disco.
- `docs/runbooks/restaurar-backup.md`.
- Script del simulacro de restauración y su ejecución programada.

**Pruebas exigidas.** §9.5 no tiene fila para infraestructura: aplican los umbrales del §9.2 y el escenario del §9.4.

- **Escenario ineludible del §9.4 «Restauración de copia»:** script automatizado que restaura la última copia en un contenedor limpio y valida integridad referencial y conteos → `->group('RF-PR-04', 'RNF-D-05')`.
- **ShellCheck + `shfmt -i 2 -d`: 0 hallazgos** sobre `infra/scripts/`.
- Verificación de que la copia está **cifrada**: el fichero no es legible sin la clave → `->group('RL-12')`.
- Idempotencia: ejecutar `backup.sh` dos veces no corrompe la copia anterior.
- Fallo seguro: simular disco lleno y comprobar que el sistema queda como estaba.

**Verificación.**

```bash
shellcheck infra/scripts/*.sh
shfmt -i 2 -d infra/scripts/
php artisan backup:run && php artisan backup:verify
bash infra/scripts/restore.sh --dry-run
```

**Terminado cuando** (§10.3, subconjunto aplicable): scripts conformes al §3.5 y verificados por ShellCheck y shfmt · instrumentación añadida · alerta con runbook · simulacro de restauración automatizado y ejecutable · ningún secreto en el repositorio, en las imágenes ni en los logs del pipeline.

---

## Las dos ramas que avanzan en paralelo (doc 02 §11.3, literal)

> **Dos ramas que deben avanzar en paralelo desde el principio:** el quiosco (1.8, 1.9) y la emisión de credenciales (1.5, 1.10). Un quiosco perfecto sin tarjetas que escanear no sirve de nada, y es un error de planificación fácil de cometer porque el quiosco es la parte visible.

Camino crítico completo de la fase (§11.3, ya con [ADR-032](../docs/adr/ADR-032-la-fase-1-entrega-un-sistema-legalmente-defendible.md)):

```
0.1→0.2→0.3 ──► 1.1→1.2 (dominio; bloquea todo lo demás)
                  ├─► 1.3→1.14 (audit_log) ──► 1.4 ──► 1.7 ──► 1.8→1.9 (quiosco)
                  │                             │                └─► 1.12 (fichaje por PIN)
                  │                             └─► 1.15 (correcciones) ──► 1.16 (detalle de jornada)
                  │                                                    └─► 1.17 (exportación legal)
                  ├─► 1.5 (credenciales) ──► 1.10 (tarjetas y entrega)
                  ├─► 1.6 ──► 1.13 (provisión del PIN) ──► 1.11 (portal)
                  │                                   └─► 1.12
                  └─► 1.18 (copias verificadas) — solo necesita 0.1
```

> **La 1.13 se intercala entre la 1.6 y las dos que consumen el PIN.** Es una arista nueva respecto al §11.3 del doc 02: sin ella, el E2E del portal (1.11) y el fichaje de respaldo (1.12) se construyen contra una columna `pin_hash` que nadie rellena.
>
> **1.14–1.18 son la incorporación de ADR-032.** `1.14` no espera a 2.1: el actor que encadena en la Fase 1 es el dispositivo o la autenticación mínima de 1.6, no el RBAC completo. `1.18` no depende de nada de esta fase salvo el entorno de 0.1, y puede avanzar en paralelo desde el principio.

---

## Cierre de fase (doc 03 §6.6)

```
Cierra la Fase 1 del plan.

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

**Requisitos que `qa:traceability --check` debe encontrar cubiertos** (doc 01, Anexo A, ya con [ADR-032](../docs/adr/ADR-032-la-fase-1-entrega-un-sistema-legalmente-defendible.md)):

`RF-AT-01..09`, `RF-AT-11`, `RF-QR-01..06`, `RF-QR-08`, `RF-ID-01..02` (básicos), `RF-ID-04..09`, `RF-KI-01..06`, `RF-KI-09`, `RF-GP-01`, `RF-GP-03`, `RF-PA-03..04`, `RF-IN-05`, `RF-PR-04`, `RN-01..09`, `RN-13`, `RN-15`, `RL-01`, `RL-03..06`, `RL-09`, `RL-12`, `RS-01..04`, `RS-07`, `RS-12`, `RS-13`, `RNF-D-02`, `RNF-D-05`, `RQ-09`

> Quince de estos —`RN-13`, `RN-15`, `RS-07`, `RF-PA-03`, `RF-PA-04`, `RL-03`, `RL-04`, `RL-06`, `RF-IN-05`, `RF-PR-04`, `RNF-D-02`, `RNF-D-05`, `RQ-09`, `RL-09`, `RL-12`— llegan con las tareas 1.14–1.18. Antes de ADR-032 no eran exigibles hasta el cierre de la Fase 2.

**Umbrales que deben estar en verde** (§9.2, RNF-M-01):

- Cobertura de dominio **≥ 90 %**; global backend **≥ 75 %**; frontend **≥ 70 %**
- **MSI ≥ 80 %** sobre `Modules/*/Domain`
- Suite unitaria completa dentro del presupuesto de duración que verifica `make test-unit` (doc 02 §9.2; 2,6-2,7 s medidos en el contenedor al cierre, presupuesto 4 s)
- `vue-tsc` y PHPStan 9 con **0 errores**
- Deptrac y Pest Arch con **0 violaciones**
- `axe` con **0 violaciones críticas o graves**
- Latencia del endpoint de fichaje **p95 < 150 ms** (RNF-P-02)
- Bundle crítico del quiosco **≤ 250 KB gzip** (RNF-P-07, Anexo A)

**Lo que no cierra ninguna herramienta, y sin lo cual la fase no está terminada:**

1. **Prueba de resistencia de 12 h en el dispositivo real** (Anexo A del doc 02): *«se exige una prueba de resistencia de 12 h en el dispositivo real antes de dar por buena la Fase 1»*.
2. **Una tarjeta impresa y plastificada, escaneada en el quiosco** (prompt §6.3, doc 03 §7).
3. **Recalibración de la estimación.** El §11.0 lo pide expresamente: la Fase 1 *«es la primera oportunidad de contrastar estimación contra realidad (R16 del documento 01)»*.

**Estado de venta al cerrar la fase** (§11.1, ya con [ADR-032](../docs/adr/ADR-032-la-fase-1-entrega-un-sistema-legalmente-defendible.md)): `0 + 1` = **166–214 h** → ✅ **Instalable y legalmente defendible**. La auditoría encadenada por hash (1.14), las correcciones trazadas (1.15) y la exportación legal para Inspección (1.17) ya están construidas: el registro satisface el art. 34.9 ET desde el cierre de esta fase, no desde el cierre de la Fase 2. Sigue sin ser «producto vendible a escala» —eso es la Fase 5— y la Fase 2 sigue aportando lo que hace la operación diaria cómoda: 2FA obligatorio, presencia en vivo, detección automática de incidencias y purga por retención automatizada.

---

← Anterior: [Fase 0 — Cimientos](02-fase-0-cimientos.md) · Siguiente: [Fase 2 — Gestión y cumplimiento](04-fase-2-gestion-y-cumplimiento.md) · [Índice](README.md)
