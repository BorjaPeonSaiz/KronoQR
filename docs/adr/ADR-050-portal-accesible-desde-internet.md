# ADR-050 — Portal accesible desde internet: PIN de 6 u 8 cifras, bloqueo por origen, aviso del instalador y segundo factor del responsable

| Campo | Valor |
|---|---|
| **Estado** | Aceptada. Revisión de `seguridad-cumplimiento`: aprobada con cambios (M1-M3, B1-B2), incorporados. **Implementada**, y la implementación revisada por `revisor-codigo` y `seguridad-cumplimiento` el 06-10-2026 |
| **Fecha** | 6 de octubre de 2026 |
| **Decide** | `arquitecto-dominio` (Bloque 12 de la 2.2.0, hallazgos PP-09, PP-10 y R4-QA-04) · `seguridad-cumplimiento` (revisión) |
| **Afecta a** | Precisa [ADR-015](ADR-015-portal-con-codigo-y-pin.md) (la longitud del PIN y los «requisitos adicionales» de exponer el portal) y [ADR-039](ADR-039-que-hechos-de-autenticacion-dejan-asiento.md) (un hecho nuevo, el bloqueo por origen) · Respeta [ADR-014](ADR-014-la-credencial-es-una-tarjeta-fisica.md), [ADR-017](ADR-017-toda-diferencia-entre-clientes-es-configuracion.md), [ADR-038](ADR-038-limite-de-tasa-por-dispositivo-y-por-ip-no-por-credencial.md) y [ADR-043](ADR-043-el-pin-rechazado-conserva-a-quien-correspondia-el-codigo.md) · `docs/01` RF-ID-01, RF-ID-06, RF-ID-08, RF-ID-09, RS-06, RS-12 · `docs/api/openapi.yaml` · `docs/02` §7.3 y §7.5 |
| **Requisitos** | RF-ID-01, RF-ID-06, RF-ID-08, RF-ID-09, RS-03, RS-06, RS-12, RS-13, RL-05, reglas duras 11, 12, 13, 17, 19 y 21 |
| **Hallazgos** | PP-09, PP-10 (`2.1.0-incidencias-produccion.md`), R4-QA-04 (`2.2.0-reverificacion-tanda-4.md`), segundo WARN del DAST (CORS) |

## Contexto

ADR-015 dejó escrito que exponer el portal a internet «es una decisión explícita del cliente que activa requisitos
adicionales», y RF-ID-08 lo repetía como «requisitos adicionales de contraseña». **Ese mecanismo no existe** (PP-09):
`PORTAL_INTERNAL_CIDR=0.0.0.0/0` solo cambia el `geo` de nginx, y el acceso sigue siendo código y PIN de 6 cifras con
10 peticiones por minuto por IP y por código, más el bloqueo por empleado de RS-12 (3/5/10 fallos → 5/15/60 min,
cuenta a cero tras 24 h sin fallar).

La re-verificación hizo la cuenta (PP-09, tanda 4): con direcciones repartidas, unos 35 intentos por empleado y día.
Con 100 empleados son ~3 500 intentos diarios contra un espacio de 10⁶, **del orden de un PIN acertado cada pocos
meses**. El acierto da `self:read` sobre una persona (RF-ID-07): su registro, no el de nadie más. El propietario ha
abierto el portal a propósito en su instalación de referencia, así que el riesgo no es teórico.

Además, el portal y el panel comparten host y puerto (PP-10): con el portal abierto, `/admin/` y `/api/v1/auth/*`
también lo están, y RS-06 solo obliga a segundo factor a `admin`, `rrhh` y `auditor`. El `responsable_departamento`
lleva `attendance:correct`: **escribe el registro horario legal de su departamento con la contraseña sola** (R4-QA-04).

Restricciones que no se negocian aquí: la credencial es la tarjeta (regla dura 11, ADR-014), el portal se abre con
código y PIN sin correo ni TOTP para la plantilla (regla dura 12, ADR-015), los rechazos son genéricos (regla dura 17)
y nada específico de un cliente vive en el código (regla dura 13, ADR-017).

## Decisión

### 1. La longitud del PIN es un ajuste de la instalación: 6 u 8 cifras

- **Dónde vive.** Clave nueva del catálogo de ajustes (`Product\Domain\ValueObject\SettingKey`),
  **`IDENTITY_PIN_LENGTH`**, `choice` con dos valores, `"6"` (de serie) y `"8"`. Se cambia por
  `PATCH /api/v1/settings` (`admin`, `settings:*`) y deja su `installation_setting.changed` como cualquier ajuste.
  Impacto nuevo **`access_control`** («cambia cómo se autentica una persona»): no es `presentation`, que según su
  propia definición es «lo que de verdad solo se ve», y en el asiento tiene que poder separarse de un logotipo.
- **Cómo lo lee el núcleo.** Objeto de valor `Shared\Domain\ValueObject\PinLength`, enum respaldado por entero con
  dos casos (`SIX = 6`, `EIGHT = 8`): una longitud 7 no se puede construir. Puerto
  `Shared\Application\Port\PinLengthProvider::current(): PinLength`, implementado en
  `Product\Infrastructure\Adapter\DbPinLengthProvider` con la misma cascada que los demás `Db*Provider`. Lo consume
  `Workforce\Application\Pin\PinGenerator` (alta, restablecimiento y, con el bloque 12b, entrega), que deja de usar
  `PinPolicy::LENGTH`. Workforce no importa Product: el puerto está en Shared.
- **Transición.** La comprobación no depende de la longitud: se compara el hash de lo tecleado. **Un PIN de 6 cifras
  sigue valiendo con el ajuste en 8 hasta que se restablezca**, y uno de 8 sigue valiendo si se vuelve a 6. No hay
  restablecimiento masivo: la entrega es presencial (regla dura 12) y uno masivo dejaría a la plantilla sin PIN.
  Para saber cuántos faltan, `employees` gana **`pin_length SMALLINT NULL`** (`CHECK pin_length IN (6, 8)`; nula si y
  solo si `pin_hash` es nulo), escrita en la misma sentencia que `pin_hash` (ADR-046) y rellenada con `6` para los
  PIN existentes en una migración de expansión. **No sale por la API**: decir a un responsable qué compañeros tienen
  el PIN corto es decirle a quién atacar. Lo cuenta `product:doctor` (punto 3).
- **PIN excluidos.** `IDENTITY_PIN_FORBIDDEN` admite entradas de 6 y de 8 cifras; el generador descarta las de su
  longitud. La lista de serie suma las triviales de 8: los diez repetidos y las secuencias `01234567`, `12345678`,
  `23456789`, `34567890`, `76543210`, `87654321`, `98765432` y `09876543`.
- **Qué se acepta al teclear: de 6 a 8 cifras**, sea cual sea el ajuste. Lo exige la transición y evita que la forma
  admitida dependa de la configuración. Un PIN de 7 cifras es un PIN incorrecto: `401` y un fallo en el contador.
  - Contrato: `PortalLoginRequest.pin` pasa de `^[0-9]{6}$` a **`^[0-9]{6,8}$`**; `IssuedPin.pin` a
    `^(?:[0-9]{6}|[0-9]{8})$`, porque solo se emite una de las dos longitudes; `pin_sealed` no cambia de forma
    (el sobre de 6 a 8 bytes son 54 a 56 bytes, 72 o 76 caracteres, dentro de los 64-160 actuales).
  - Quiosco: el teclado admite de 6 a 8 cifras y **envía con la tecla «Aceptar»**, activa desde la sexta. No hay
    envío automático al llegar a 6, porque con 8 configurado se cortaría el PIN. El quiosco no recibe la longitud.
  - Portal: `inputmode="numeric"`, `minlength=6`, `maxlength=8`.

### 2. Bloqueo por origen en `POST /api/v1/me/login`

Además del bloqueo por empleado, que se queda como está, el acceso al portal cuenta los fallos **por origen**.

- **Qué cuenta.** Cada uno de los cinco rechazos genéricos (código inexistente, PIN incorrecto, PIN no emitido,
  persona que no está en alta, bloqueo por empleado). No cuentan los `400` de forma ni los `429` del limitador.
  **Un acceso correcto no pone el contador a cero**: si lo hiciera, quien conoce su propio PIN lo intercalaría entre
  intentos contra otros.
- **Política.** `Identity\Domain\Policy\OriginLockoutPolicy`, pura, con los umbrales ya resueltos en el constructor:
  **20 fallos en una ventana deslizante de 15 minutos bloquean el origen 60 minutos**. Las peticiones durante el
  bloqueo no se evalúan, no cuentan y no lo alargan. Son parámetros de seguridad y no umbrales legales: viven en
  `config/identity.php` junto a los escalones del PIN (`IDENTITY_PORTAL_ORIGIN_MAX_FAILURES=20`,
  `IDENTITY_PORTAL_ORIGIN_WINDOW_SECONDS=900`, `IDENTITY_PORTAL_ORIGIN_LOCKOUT_SECONDS=3600`), y el proveedor de
  servicios los inyecta en el caso de uso (reglas 13 y 14: el caso de uso no consulta `config()`).
- **Qué es un origen.** Objeto de valor `Identity\Domain\ValueObject\RequestOrigin`: una IPv4 es su `/32`; una IPv6 es
  su **`/64`**, porque cualquier conexión doméstica recibe un `/64` entero y contar por dirección no frenaría a nadie;
  una IPv4 mapeada en IPv6 se normaliza a IPv4. Se construye desde la dirección que nginx entrega a PHP-FPM
  (`REMOTE_ADDR`, ya corregida por `real_ip` cuando `TRUSTED_PROXY_CIDR` está definida, PP-03). La aplicación
  **nunca** lee `X-Forwarded-For`.
- **Dónde se cuenta.** Puerto `Identity\Application\Port\PortalOriginAttempts`; adaptador
  `Identity\Infrastructure\Adapter\CachePortalOriginAttempts` sobre la caché **`resilient`**, la misma que el contador
  del PIN (`config/cache.php`): sin Redis sigue contando en el disco de la única máquina que atiende peticiones.
  **Sin Redis el acceso al portal ya está cerrado**, porque el limitador de la zona `portal` falla cerrado a propósito
  (`cache.limiter`); este ADR no lo cambia. La regla dura 19 protege el fichaje, no el portal, y el fichaje no pasa
  por aquí.
- **Cómo se cuenta.** La cuenta de fallos por origen se actualiza con un **candado por origen** sobre la caché
  `resilient` (Redis o disco), de modo que fallos simultáneos desde el mismo origen cuentan todos; un fallo que llega
  con el bloqueo ya abierto no lo altera. Sin candado, leer-calcular-escribir perdía ~90 % de los incrementos con 25
  intentos en paralelo (lo midió qa-testing, `PortalOriginConcurrencyTest`), y `Cache::increment` no vale en disco
  porque `FileStore` tampoco es atómico. Si el candado no se consigue, se cuenta sin él: nunca un error.
- **Qué se responde.** **`429`** con `Retry-After` (segundos que faltan) y el problema fijo
  **`urn:kronoqr:problem:portal-origin-locked`**. Se decide **antes** de leer el código de empleado o de llamar al
  verificador: el desenlace no depende del código ni del PIN y no toca el contador por empleado. No contradice RS-03,
  que protege la existencia y el estado de una credencial; esto habla de la red de quien pregunta, y ocultarlo solo
  serviría para que una persona legítima tras la misma IP creyera que su PIN está mal y siguiera fallando.
- **Qué se anota** (precisa ADR-039). La **apertura** del bloqueo deja el asiento **`auth.origin_locked`** en
  `audit_log`: actor `system`, **sin sujeto**, `payload` cerrado `{channel, failures, seconds, ip_hash}`; la IP en
  claro va solo en la columna `ip`, como en el resto de asientos `auth.*` (ADR-039). Se escribe **después de
  responder** con `DeferredAuditEntry`, por el mismo motivo que `auth.lockout_started`, y bajo el techo por hora del
  punto siguiente. En el log técnico, un apunte `warning` con `{origin_hash, failures, lock_seconds, audited}` (este
  último dice si hubo asiento o lo impidió el techo), y `kronoqr_auth_attempts_total{channel="portal",
  outcome="origin_locked"}` una vez por apertura (`AuthOutcome::ORIGIN_LOCKED`). Cada petición rechazada durante el
  bloqueo cuenta como `outcome="failure"` con el motivo nuevo `AuthFailureReason::ORIGIN_LOCKED`; aquí la respuesta
  sí lo distingue, así que el log puede distinguirlo (ADR-039, «un solo motivo de fallo donde la respuesta es una sola»).
- **Techo de asientos** (B1 de la revisión). Cada asiento pasa por el candado global de la cadena (ADR-010), el mismo
  que toma cada fichaje, y un atacante con muchas direcciones podría abrir un bloqueo por IP cada 75 minutos.
  Por eso hay un **techo global por hora** de asientos `auth.origin_locked`, configurable
  (`IDENTITY_ORIGIN_LOCK_AUDIT_CEILING_PER_HOUR`, **60 de serie**). Por encima del techo el bloqueo se aplica igual y
  deja su apunte de log y su métrica, pero no su asiento; el primer bloqueo que no deja asiento en cada hora lo dice
  en el log (`auth.origin_lock_audit_ceiling_reached`).
- **Desbloqueo manual** (M3 de la revisión). Comando `identity:origin-unlock <ip>`, que levanta el bloqueo de ese
  origen (su `/32`, o su `/64` en IPv6) y deja el asiento **`auth.origin_unlocked`**, **síncrono**: actor `system`,
  sin sujeto, `payload` `{channel, ip_hash, had_state}` (`had_state` dice si había algo que levantar). No hay endpoint: desbloquear es una operación de quien administra el
  servidor, como `identity:2fa-reset`. La alerta sobre `kronoqr_auth_attempts_total{outcome="origin_locked"}` avisa al
  IT del cliente de que hay un origen bloqueado.
- **Solo el portal.** No se aplica a `/api/v1/scan/pin`: el quiosco nunca bloquea al empleado (regla dura 19,
  ADR-038). La autenticación de gestión ya tiene su propio bloqueo por cuenta y por origen (RF-ID-01).
- **nginx, coherente con lo anterior.** `POST /api/v1/me/login` sale de la `location ^~ /api/v1/me/` a zona propia
  **`portal_login`** (10 r/m por IP, `burst=5 nodelay`), que aplican **dos** `location =` con el mismo cuerpo,
  `/api/v1/me/login` y `/api/v1/me/login/`: con `^~ /api/v1/me/` nginx no evalúa expresiones regulares, y la variante
  con barra final caía en `zone=portal` (hallazgo de `revisor-codigo`). La zona
  `portal` (10 r/m, `burst=10`) se queda para la lectura del registro. Hoy comparten cubo, y tras una misma IP de
  salida consultar las jornadas propias consume los intentos de acceso de los compañeros, y al revés. El borde solo
  limita volumen; los fallos los cuenta la aplicación, que es quien sabe que lo son.

### 3. Aviso cuando `PORTAL_INTERNAL_CIDR` no es una red privada

Lo implementó `devops-observabilidad` en esta misma rama (commit `549f3325`), y este punto fija lo que hace:

- **Privada** es un rango contenido entero en `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `127.0.0.0/8`,
  `169.254.0.0/16` o el CGNAT `100.64.0.0/10` (habitual en las VPN de malla). Cualquier otro, incluido `0.0.0.0/0`, es
  **público**. Solo IPv4: el borde solo escucha en IPv4, así que no hay rangos IPv6 (ULA) que clasificar.
- **Severidad: aviso, no fallo.** Abrir el portal es una decisión legítima del cliente y de su DPO (doc 07 §4,
  «exponer el portal fuera de la red interna»); el instalador no puede impedirla, solo asegurarse de que se toma
  sabiendo lo que implica. Ni `install.sh` ni `update.sh` se detienen ni cambian su código de salida por esto.
- **`infra/scripts/lib/checks.sh`** (`check_network_cidrs`, que comparten `install.sh`, `update.sh` y `doctor.sh`)
  escribe el aviso y recomienda el **PIN de 8 cifras**, que se configura en los ajustes del producto: es el ajuste
  **`IDENTITY_PIN_LENGTH`** del punto 1. Recomienda además `ADMIN_INTERNAL_CIDR` (punto 4) y `TRUSTED_PROXY_CIDR` si
  hay un proxy delante (sin él, toda la plantilla comparte un origen y el punto 2 la bloquea entera).
- **`product:doctor`** (sonda `EdgeNetworksProbe`) aplica la misma clasificación sobre `security.network.portal_internal`
  y da `warn` cuando el portal es público, con el hallazgo `network.admin` cuando además `ADMIN_INTERNAL_CIDR` está
  vacía. La sonda **`AccessHardeningProbe`** completa el cuadro: `access.pin_length` (portal público con
  `IDENTITY_PIN_LENGTH` en 6), `access.short_pins` (**recuento de personas en alta que conservan un PIN de 6 cifras**
  con el ajuste en 8), `access.two_factor_roles` (un rol de los cuatro fuera de `IDENTITY_2FA_REQUIRED_ROLES`) y
  `access.two_factor_pending` (cuentas de roles obligados sin segundo factor activo, la ventana de M1). Solo números,
  nunca quiénes (regla dura 21).
- **Constancia.** En la **actualización**, el asiento `system.updated` lleva la clave opcional **`portal_exposed: true`**
  cuando el portal no está en una red privada (la calcula `kq_portal_exposed` en `lib/checks.sh`; `SystemEventPayload`
  la admite; ausente significa red privada). Solo el booleano: **sin los CIDR**, porque el asiento viaja en el paquete
  de diagnóstico (ADR-020) y la topología de la red del hotel no tiene por qué ir con él. En la **instalación** no hay
  asiento —`install.sh` no escribe ninguno: la cadena empieza con el alta del primer administrador—, y la constancia es
  el aviso en el informe de instalación. Exponer el portal cambia quién puede intentar leer datos personales: es un
  hecho con relevancia legal (regla dura 6), y por eso queda en la cadena en la primera actualización.

### 4. PP-10: `ADMIN_INTERNAL_CIDR`, opcional y vacía de serie

Variable opcional del `.env` que cierra **`/admin/`** (los ficheros del panel), **`/api/v1/auth/*`** y
**`/api/v1/setup/*`** —las dos puertas por las que se obtiene una sesión de gestión: el acceso y, mientras no exista
ninguna cuenta, el alta del primer administrador (`POST /api/v1/setup/administrator`)— a un rango IPv4: fuera de él,
`403` `problem+json` en nginx, antes de
PHP-FPM, con cuerpo bilingüe y sin IP ni CIDR. **Vacía, no filtra, y es el valor de serie por decisión del propietario.** No cubre el resto de `/api/v1/*`,
que sin sesión de gestión no sirve nada, ni el portal, ni los quioscos, ni `/metrics`. Lo implementó `devops-observabilidad` en esta rama (commit `21313850`:
`07-kronoqr-admin-net.envsh`, `geo $kronoqr_admin_allowed`, `spa.conf`) y lo documentan `.env.example`,
`docs/cliente/endurecimiento.md` §1 y `configuracion.md`, con sus versiones en inglés. `/api/v1/setup/*` entra en el filtro con la revisión (M2) y lo añade `devops-observabilidad`.

### 5. Segundo factor obligatorio también para `responsable_departamento`

**Obligatorio y sin condición.** RS-06 pasa a `admin`, `rrhh`, `auditor` y `responsable_departamento`; RF-ID-01 deja
de justificarlo por el alcance de lectura. El criterio que faltaba es **quién escribe el registro legal**: el
responsable corrige jornadas (`attendance:correct`) y, con una contraseña robada, se rehace la nómina de un
departamento. El alcance acotado (RF-ID-03) limita **cuánto** se lee, no **qué** se puede alterar.

- **Por qué sin condición y no «cuando el panel es accesible desde internet».** El código no tiene forma fiable de
  saberlo: `ADMIN_INTERNAL_CIDR` vacía no significa expuesto (puede haber un cortafuegos delante), y con valor no
  significa interno (puede ser un rango público). Una regla que depende de una suposición sobre la red se desactiva sin
  que nadie lo note. El coste es pequeño: son pocas cuentas por hotel y el alta del TOTP ya existe.
- **Transición.** Tras actualizar, un responsable sin TOTP recibe en `POST /api/v1/auth/login` el `202` con
  `enrolment_required: true` y pasa por `/auth/2fa/enrol` y `/auth/2fa/confirm`. Nadie queda fuera.
- **La ventana de auto-alta** (M1 de la revisión). Hasta que cada responsable entra por primera vez tras actualizar,
  **quien tenga solo su contraseña puede dar de alta el segundo factor él mismo** y quedárselo: es la ventana que el
  doc 07 §6 (fila 1) ya acepta para las cuentas nuevas de `admin`, `rrhh` y `auditor`, y que ahora se abre a la vez
  para todos los responsables existentes. No se cierra sola para quien no vuelve a entrar. Controles: el asiento
  `auth.two_factor_enabled` (actor, momento e IP) hace la usurpación detectable; `product:doctor` da el **recuento de
  cuentas de los roles obligados sin segundo factor activo** (solo el número) y las notas de la versión piden que
  cada responsable entre en cuanto se actualice. Lo implementan `backend-laravel` y `producto-licencia`.
- **Sigue siendo configuración** (regla dura 13): `IDENTITY_2FA_REQUIRED_ROLES` pasa a valer de serie
  `admin,rrhh,auditor,responsable_departamento`. Un cliente puede acortar la lista; `product:doctor` avisa (`warn`)
  de cada rol de los cuatro que falte.
- No toca a la plantilla: el empleado no tiene TOTP ni lo tendrá (reglas duras 11 y 12, ADR-015).

### 6. CORS restringido al origen de `APP_URL`

`allowed_origins` de `/api/v1/*` deja de ser `*` y pasa a ser **el origen de `APP_URL`**. Ningún llamador legítimo es
de otro origen: las tres SPA se sirven desde el mismo host. Lo aprobó el propietario el 29-09-2026 y lo implementa
`backend-laravel`.

## Alternativas descartadas

| Alternativa | Motivo |
|---|---|
| PIN de 8 cifras para todas las instalaciones | Obliga a restablecer y entregar en mano el PIN de toda la plantilla de todos los clientes, incluidos los que tienen el portal en la red interna, donde 6 cifras con bloqueo bastan (ADR-015) |
| Longitud libre (4 a 12) o PIN alfanumérico | El teclado del quiosco es numérico y la longitud corta reabre PP-09. 6 u 8 cubren los dos casos reales; el enum impide el resto |
| Longitud en `config/identity.php` (`.env`) | La cambia quien administra el servidor y no deja asiento. Es una decisión del responsable del tratamiento, y su sitio es el ajuste auditado del panel |
| Invalidar los PIN de 6 al pasar a 8 | Deja sin acceso al portal ni respaldo de fichaje a quien no haya recogido el nuevo: rompe RL-05 y la regla dura 19 el mismo día |
| Responder `401` también al origen bloqueado | No oculta nada que importe (la IP es de quien pregunta) y confunde a la persona legítima tras la misma IP, que seguiría tecleando |
| Bloqueo por origen solo en nginx (`limit_req` más estricto) | nginx no distingue un acceso correcto de uno fallido: o deja pasar la fuerza bruta o corta a toda la plantilla tras una misma IP |
| Captcha en el portal | Un servicio externo ([ADR-016](ADR-016-producto-licenciado-on-premise.md): el producto funciona sin internet) o uno propio que no frena a un atacante con medios |
| 2FA del responsable solo con el panel «expuesto» | El código no sabe si lo está (punto 5); una regla que depende de esa suposición se apaga sola |
| TOTP o correo para la plantilla al abrir el portal | Contradice las reglas duras 11 y 12 y ADR-015 |
| `ADMIN_INTERNAL_CIDR` cerrada de serie | Decisión del propietario: la quiere abierta. Queda documentada como riesgo (§ Residuos) y con aviso en `product:doctor` |

## Residuos que se aceptan (doc 07 §6)

Anotados en el doc 07 §6 tras la revisión de `seguridad-cumplimiento` (filas A-19 a A-24, y la fila 1 ampliada):

1. **Ataque repartido contra PIN de 6 cifras.** El bloqueo por origen no frena a quien rota direcciones; lo que lo
   frena es el PIN de 8 (10⁸: la misma cuenta de PP-09 pasa de meses a decenas de años). Si el cliente abre el portal
   y no sube a 8, o mientras queden PIN de 6 sin restablecer, el residuo de PP-09 sigue ahí, avisado por
   `product:doctor` y limitado a `self:read` de una persona.
2. **Origen compartido.** Tras un NAT del hotel, un CGNAT, un *hairpin NAT* o un proxy sin `TRUSTED_PROXY_CIDR`, toda la
   plantilla comparte un origen, y un tercero con 20 fallos cada hora puede **dejar el portal cerrado indefinidamente**
   a todos. Controles: la alerta sobre `outcome="origin_locked"`, el desbloqueo auditado `identity:origin-unlock <ip>`
   (`auth.origin_unlocked`), el aviso de `TRUSTED_PROXY_CIDR` del instalador y, mientras dure, **RL-05 se cumple por la
   vía alternativa**: RRHH exporta el registro de la persona que lo pida.
3. **Panel abierto de serie** (PP-10). Con `ADMIN_INTERNAL_CIDR` vacía, `/admin/`, `/api/v1/auth/*` y `/api/v1/setup/*` quedan a la vista
   de internet si el portal lo está. Quedan contraseña, segundo factor obligatorio para los cuatro roles, 5 r/m y el
   bloqueo de cuenta.
4. **Constancia diferida.** Una instalación nueva con el portal abierto no deja asiento hasta su primera
   actualización (solo el aviso del informe), y quien abre el portal editando el `.env` y reiniciando sin `update.sh`
   tampoco: `portal_exposed` llega con el siguiente `system.updated`. `doctor.sh` y `product:doctor`
   sí lo avisan desde el primer momento.
5. **IPv6, hoy inaplicable.** El borde solo escucha en IPv4, así que no llega ninguna dirección IPv6. Cuando se abra
   IPv6, la zona `portal_login` de nginx contaría por dirección completa y no por `/64` (el `/64` solo lo aplicaría la
   aplicación), y la clasificación de redes privadas del punto 3 tendría que sumar `fc00::/7`: revisar las dos cosas
   en ese momento.
6. **Ventana de auto-alta del segundo factor de los responsables** (M1, punto 5): se suma a la fila 1 del doc 07 §6.
7. **Sin Redis el portal no deja entrar** (ya era así, `cache.limiter` falla cerrado). El fichaje no se ve afectado.
8. **Ráfaga antes del bloqueo.** El intento se reserva **antes** de comparar el PIN, con el candado por `employee_uuid`
   y puerta (`CacheMutex` sobre la caché `resilient`; el señuelo de códigos inexistentes usa un candado por HMAC del
   código tecleado, para que una ráfaga cueste lo mismo exista o no el código: RS-03). Por ráfaga solo llegan al PIN
   real los intentos que caben antes del escalón (3 de 25 medidos; antes 4-22). La cota diaria vuelve a ser por intento
   e independiente del paralelismo: ≈32 por puerta y día (3, luego 1 cada 5, 15 y 60 min). Lo que queda: sin candado
   (100×5 ms agotados o almacén que lanza) o si `failover` cambia de almacén en mitad de una ráfaga, una marca de más o
   de menos o un asiento doble; nunca un `500` ni un escalón en silencio.

## Consecuencias

- **Contrato** (antes que el código): `PortalLoginRequest.pin` `{6,8}`, `IssuedPin.pin` 6 u 8, `SettingKey` gana
  `IDENTITY_PIN_LENGTH`, `SettingImpact` gana `access_control`, `/me/login` documenta el bloqueo por origen y su `429`
  con `urn:kronoqr:problem:portal-origin-locked`, y RS-06 en la descripción de `Identity` y de `/auth/login`.
  Todo aditivo en la v1 (ADR-012).
- **Esquema:** `employees.pin_length` (expansión, reversible). `AuditAction` gana `auth.origin_locked` y `auth.origin_unlocked`.
  `SystemEventPayload` ya admite `portal_exposed` en `system.updated` (commit `549f3325`).
- **Doc 01:** RF-ID-01, RF-ID-06, RF-ID-08, RF-ID-09, RS-06, RS-12 y la fila de STRIDE del PIN; glosario,
  «bloqueo por origen». **Doc 02:** §7.3 (fila y nota 1 del responsable) y §7.5. **Doc 05:** «PIN de 6 dígitos» pasa a
  «de 6 u 8 cifras».
- **Notas de la versión:** los responsables darán de alta su TOTP en el primer acceso tras actualizar; quien abra el
  portal debería pasar a PIN de 8 y restablecer los PIN de la plantilla a medida que los entregue.
- `PinPolicy::LENGTH` desaparece; `config/identity.php` deja de afirmar que la longitud no es configurable.

## Verificación

| Punto | Prueba que lo demuestra |
|---|---|
| 1 | `Unit/Shared/Domain/PinLengthTest` (dos casos, 7 imposible); `Unit/Workforce/PinGeneratorTest` (8 cifras con el ajuste en 8; lista de excluidos por longitud); `Feature/Workforce/PinLengthTransitionTest`: con el ajuste en 8, `pin/reset` emite 8 y escribe `pin_length = 8`, un PIN de 6 anterior sigue abriendo el portal y fichando por `/scan/pin` (caso del quiosco, con el sobre sellado), 7 cifras es `401` y cuenta, 9 es `400`; `Feature/Product/SettingsTest`: `"7"` es `422`; contrato de `/me/login`, `IssuedPin` y ajustes; `frontend-kiosk` (teclado de 6 a 8 con «Aceptar») y E2E del portal con un PIN de 8 |
| 2 | `Unit/Identity/Domain/OriginLockoutPolicyTest` (umbral, ventana deslizante, el bloqueo no se alarga) y `RequestOriginTest` (`/32`, `/64`, IPv4 mapeada); `Feature/Identity/PortalOriginLockoutTest`: el fallo 20 abre el bloqueo, el 21 recibe `429` con `Retry-After` **también con el PIN correcto**, el contador por empleado no se mueve durante el bloqueo, otro origen no se ve afectado, un acierto no reinicia la cuenta, un solo `auth.origin_locked` por apertura y la métrica, sin asiento por encima del techo por hora, y `identity:origin-unlock` levanta el bloqueo y deja `auth.origin_unlocked`; `Integration/Identity/CachePortalOriginAttemptsTest` con Redis caído (sigue contando en `file`); `RouteRateLimitZonesTest` y `QualityGatesTest` para `portal_login`, también con la barra final (`/api/v1/me/login/`); `PortalOriginConcurrencyTest` (un solo asiento por apertura con peticiones simultáneas); `PinLockoutConcurrencyTest` (≤ 3 comparaciones del PIN y un solo `auth.lockout_started` por ráfaga); `CacheMutexTest`; la prueba del oráculo del señuelo con contienda (una ráfaga cuesta lo mismo exista o no el código) |
| 3 | Pruebas de `lib/checks.sh` (privado, público, `0.0.0.0/0`, `169.254.0.0/16`, `100.64.0.0/10`): aviso sin cambiar el código de salida; `Unit/Product/EdgeNetworksProbeTest` (con los tres hallazgos pendientes); `system.updated` con `portal_exposed: true` solo cuando el rango es público y nunca con un CIDR en el `payload`; etapa de instalación limpia de la CI con `PORTAL_INTERNAL_CIDR=0.0.0.0/0` |
| 4 | Prueba de la plantilla de nginx: con `ADMIN_INTERNAL_CIDR` definida, `403` en `/admin/`, `/api/v1/auth/login` y `/api/v1/setup/administrator` desde fuera y `200`/`401` desde dentro; vacía, sin filtro; `/api/v1/me/*` y `/api/v1/scan*` nunca afectados |
| 5 | `TwoFactorAuthenticationTest` (hoy `:309-329` fija lo contrario): un responsable sin TOTP recibe `202` con `enrolment_required: true`; `product:doctor` avisa si falta un rol de los cuatro y da el recuento de cuentas obligadas sin segundo factor |
| 6 | `Feature/Http/CorsSameOriginTest`: preflight desde el origen de `APP_URL` admitido, desde otro sin `Access-Control-Allow-Origin` |
