# ADR-040 — Un centro de trabajo por instalación y por licencia

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 29 de agosto de 2026 |
| **Decide** | Usuario (decisión de producto) · `arquitecto-dominio` (forma) |
| **Afecta a** | `Workforce/Domain/Model/Site` y `Workforce/Application/Port/SiteRepository` · `Workforce/Http` (empleados, departamentos, centro) · `Identity/Application/UseCase/CredentialStatusBoard` y `PrintCredentialBatch` · `docs/api/openapi.yaml` · `frontend-admin` · `docs/01`, `docs/02`, `docs/05` y el plan de las Fases 2, 4 y 5 |
| **Requisitos** | RF-GP-01, RF-PA-02, RF-IN-02, RF-ID-03, RF-PD-03, RF-PD-04, RN-04, RN-05, RS-04 |
| **Matiza** | [ADR-012](ADR-012-api-versionada-en-la-ruta.md), [ADR-017](ADR-017-toda-diferencia-entre-clientes-es-configuracion.md), [ADR-018](ADR-018-licencia-firmada-con-verificacion-local.md), [ADR-028](ADR-028-limites-del-plan-no-bloquean.md) |

## Contexto

El doc 01 §1 vendía «multi-centro desde el día 1» y el doc 05 §11 prometía como evolución la
«consolidación entre varios centros de una cadena». Con esa premisa, la Fase 1 construyó `sites` como
un catálogo con CRUD, `site_id` en cada alta y en cada filtro del contrato, un selector de centro en el
alta de empleado y en dos tableros del panel, y una licencia (RF-PD-04, ADR-018) con `max_sites`.

El producto no se vende así. **Se vende una licencia por hotel, y cada licencia es una instalación
completa en el servidor de ese hotel** (`CLAUDE.md`: «no hay SaaS ni multi-tenencia»). Una cadena con
tres hoteles compra tres licencias y opera tres instalaciones. Mantener el modelo multi-centro dentro de
una instalación tenía coste real y valor nulo:

- **Un dato que siempre vale lo mismo en cada petición.** `site_id` viajaba en cada alta, en cada filtro
  y en cada fila del tablero de credenciales para señalar el único centro que existe. Un selector con
  una sola opción no es una funcionalidad: es una pregunta que el usuario no puede responder mal, pero
  que tiene que leer.
- **Un ámbito de autorización sin contenido.** RF-ID-03 hablaba de «departamento y centro»; el segundo
  eje no separa a nadie de nadie.
- **Un límite de licencia que no limita.** `max_sites` (ADR-018, ADR-028) protegía contra un exceso que
  no puede producirse.
- **Pruebas que probaban una frontera que no existe:** «el quiosco no sirve el padrón de otro hotel de
  la cadena», «un departamento de otro centro es un dato imposible». Ambas cuestan mantenimiento y
  documentan un producto que no se vende.

Había dos formas de hacerlo, y la diferencia no es de tamaño sino de qué se conserva:

- **Eliminar el concepto** —quitar `sites` y cada `site_id`, mover zona horaria y perfil de convenio a
  la instalación—. Toca las siete migraciones base, `shift_entries` (el registro legal), todo
  `Workforce`, y borra algo que **sí existe aunque haya uno solo**: el centro de trabajo tiene zona
  horaria (RN-04/05), convenio (RF-PD-07) y es la unidad por la que sanciona Inspección (doc 01 §7).
- **Un centro por instalación** —el centro sigue siendo una entidad, pero hay exactamente uno—.

## Decisión

**Cada instalación tiene exactamente un centro de trabajo. El centro existe como entidad —tiene
nombre, zona horaria y perfil de convenio—, pero no es un dato que el cliente elija en ninguna
operación: el servidor lo resuelve.**

En concreto:

1. **La base de datos impide un segundo centro.** Un índice único sobre una expresión constante en
   `sites` (`sites_single_row_uidx`) hace que el segundo `INSERT` falle. Es una restricción
   declarativa, como las demás invariantes del esquema (doc 02 §3.2), y no un `if` en un caso de uso.
   `site_id` **se conserva en todas las tablas** (`departments`, `employees`, `devices`,
   `shift_entries`): apunta siempre al mismo centro, pero el registro
   legal y la cadena de auditoría no se tocan, y `SiteCalendar`, `CompliancePolicyProvider` y
   `OperationalSettingsProvider` siguen resolviendo por él.

   > **Enmienda 31-08-2026 (tarea 5.1).** La lista original incluía
   > `installation_settings.scope_id`. Ya no: la tarea 5.1 lo retiró junto con `scope`, su `CHECK`, su
   > clave ajena a `sites` y los dos índices parciales (ver la enmienda del punto 6). El resto de las
   > tablas conserva `site_id`, que es lo que esta decisión protege: el registro legal no se toca.
2. **El centro se crea una sola vez.** `CreateSiteHandler` se niega a crear un segundo
   (`SiteAlreadyConfigured`, `409`). No hay endpoint de alta: lo crea el asistente de puesta en marcha
   (RF-PD-03, tarea 5.5), y hasta entonces la semilla o la consola. `SiteRepository::installationSite()`
   lo entrega a quien lo necesita; si no existe, `NoSiteConfigured` (`503`, la instalación no está
   lista).
3. **El contrato deja de hablar de `site_id`.** `/api/v1/sites` y `/api/v1/sites/{id}` desaparecen y en
   su lugar hay un recurso singular: `GET /api/v1/site` y `PATCH /api/v1/site` (nombre y zona
   horaria; cambiar la zona sigue auditado porque afecta a RN-05). Ningún cuerpo de alta ni ningún
   filtro acepta `site_id`; ninguna respuesta lo devuelve. El tablero de credenciales devuelve un único
   `summary` en vez de una lista por centro. El asiento de divulgación (ADR-037) deja de anotar
   `site_id` como filtro, porque ya no es un filtro.
4. **El panel no pregunta por el centro.** Ni en el alta de empleado, ni como filtro de plantilla o de
   credenciales, ni como columna. La zona horaria con la que presenta instantes sale de `GET /site`.
5. **La licencia no tiene `max_sites`.** RF-PD-04 y ADR-018 codifican cliente, plan, `max_employees`,
   `max_devices`, `features` y vigencia. ADR-028 aplica a las dos cifras que quedan. El asistente de
   puesta en marcha crea **el** centro (nombre y zona), no una lista.
6. **`installation_settings.scope = site` queda sin uso.** La cascada de RF-PD-01 (tarea 5.1) pasa a
   `installation → valor de serie`. La columna y su `CHECK` se conservan: retirarlos es una migración
   de contracción que decide la tarea 5.1 cuando implemente la cascada, no esta rama.

   > **Enmienda 31-08-2026 (tarea 5.1): la contracción ya se ejecutó.** La migración
   > `2026_09_05_100000_contract_installation_settings_scope` retira `scope`, `scope_id`, el `CHECK`
   > `installation_settings_chk_scope`, la clave ajena a `sites` y los dos índices parciales
   > (`one_installation_setting_per_key`, `one_site_setting_per_key`), y crea el único que queda,
   > `one_setting_per_key`, sobre `key`.
   >
   > **La cascada real es de dos escalones: fila de instalación → valor por defecto del catálogo en
   > código** (`Product/Domain/ValueObject/SettingKey`). La variable de entorno no es un escalón: es
   > el valor de arranque con el que el instalador (tarea 5.4) siembra la primera fila. Si lo fuera,
   > un cambio guardado desde el panel no surtiría efecto mientras el `.env` dijera otra cosa, y el
   > cliente vería el valor nuevo guardado y el viejo aplicándose.
   >
   > La migración **se niega a ejecutarse si encuentra filas de ámbito `site`** en lugar de borrarlas
   > (regla dura 5). No puede haberlas —ningún camino del producto las escribió nunca—, pero una
   > edición a mano sí. `down()` reconstruye el esquema exacto de la tarea 1.3 y está probado en
   > `tests/Integration/Product/ContractInstallationSettingsScopeMigrationTest.php`.
7. **Lo que se le dice al cliente cambia.** Doc 05 deja de prometer «multi-centro» y «consolidación
   entre centros»: una cadena compra una licencia por hotel. El punto 4.e del plan de la Fase 4 se
   retira.

### Sobre ADR-012

ADR-012 dice que dentro de `/api/v1` solo caben cambios aditivos, y este cambio quita campos y
endpoints. Se aplica igualmente en v1, y la razón está en el propio ADR-012: la regla existe porque
«hay quioscos con la cola offline llena que llevan días sin conectar». **La superficie del quiosco
—`/scan`, `/scan/pin`, `/kiosk/roster`, `/kiosk/heartbeat`— no cambia en nada.** Lo que cambia es la
API de gestión, cuyo único cliente es el panel que se despliega con la misma versión, y **no existe
todavía ninguna instalación en casa de un cliente** (la Fase 5, que produce el instalador, no ha
empezado). Abrir `/api/v2` para un producto sin instalar sería cumplir la letra de ADR-012 contra su
motivo. El commit va marcado como cambio incompatible (`feat!:`), que es lo que el `CHANGELOG` y
SemVer piden.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Eliminar `sites` y todos los `site_id`** | Toca el esquema del registro legal y las migraciones base para borrar una entidad que sigue teniendo sentido con un solo ejemplar: la zona horaria y el convenio son del centro, y la sanción de Inspección es por centro de trabajo. Si algún día se quisiera, esta decisión es su primer paso, no un obstáculo |
| **Dejar el modelo multi-centro y fijar `max_sites = 1` en la licencia** | El cliente seguiría viendo selectores con una opción, los documentos seguirían vendiendo multi-centro y el código seguiría probando una frontera que no existe. Es lo que se quería cambiar |
| **Conservar `site_id` opcional en el contrato «por compatibilidad»** | Compatibilidad con nadie: no hay instalación desplegada. Un parámetro que se acepta y se ignora es peor que uno que no existe (`RejectsUnknownInput`: quien lo envía cree haber acotado algo) |
| **Abrir `/api/v2`** | Ver «Sobre ADR-012». Duplicaría el contrato y las pruebas de un producto sin instalar para proteger a quioscos cuya API no cambia |
| **Mantener la consolidación entre centros como promesa futura en doc 05** | Prometería una funcionalidad que ningún requisito respalda (`CLAUDE.md`, orden de autoridad, punto 5). Si una cadena la pide, será un producto distinto —una consola sobre varias instalaciones— y tendrá su propio requisito |

## Consecuencias

- **El alta de empleado y la de departamento no llevan `site_id`**: el caso de uso lo resuelve con
  `SiteRepository::installationSite()`. `DepartmentNotInSite` desaparece con la comprobación que lo
  lanzaba: un departamento no puede ser de otro centro porque no hay otro.
- **`GET /employees`, `GET /departments`, `GET /credentials/status` y `POST /credentials/print-batch`
  pierden el filtro `site_id`**, y `credentials:print-batch` y `credentials:status` pierden `--site`.
- **El tablero de credenciales devuelve `summary` como objeto** (`employees`, `pending_print`,
  `without_delivered_credential`), no como lista por centro; con `employee_uuid`, cuenta solo la fila
  devuelta, como antes. Las métricas `employees_without_delivered_credential` y
  `credentials_pending_print` conservan sus etiquetas `site` y `site_name` (doc 02 §8.2): describen el
  centro de la instalación y no cambian de forma para quien ya las consume.
- **La semilla de desarrollo pasa a un solo centro** (`Hotel Marina`, `Europe/Madrid`). El caso de
  «empleado en un centro con otra zona horaria que el quiosco» deja de ser posible por diseño.
- **RF-ID-03 queda en «departamento»**; RF-PA-02 y RF-IN-02 pierden «por centro»; RF-PD-03 crea el
  centro; RF-PD-04 codifica dos límites. El Anexo B del doc 01 nombra `/site` en singular.
- **Las pruebas que afirmaban una frontera entre centros se retiran** (padrón del otro hotel,
  departamento de otro centro, `employee_uuid` combinado con `site_id`) y entran las que afirman la
  nueva invariante: un segundo centro es imposible en la base de datos y en el caso de uso, y el alta
  sin `site_id` queda adscrita al centro de la instalación.
- **La versión que incluya este cambio es incompatible** con el contrato anterior de la API de gestión.
  Como no hay instalación desplegada, no hay migración de datos: el índice único se crea sobre una
  tabla con una fila.

## Verificación

- **Integración:** insertar un segundo centro falla por `sites_single_row_uidx`; la migración es
  reversible en los dos sentidos.
- **Unitaria:** `CreateSiteHandler` lanza `SiteAlreadyConfigured` cuando ya existe uno y crea el
  primero cuando no.
- **Feature:** `POST /employees` y `POST /departments` sin `site_id` responden `201` y la fila queda
  en el centro de la instalación; con `site_id` responden `422` (`RejectsUnknownInput`).
- **Feature:** `GET /site` y `PATCH /site` con sus autorizaciones negativas por rol; `PATCH` de zona
  deja asiento.
- **Contrato:** ninguna operación de `/api/v1` acepta ni devuelve `site_id`; `/api/v1/sites` no existe.
- **Búsqueda en el árbol:** cero `site_id` en `frontend-admin/src` fuera de `schema.d.ts` generado.
