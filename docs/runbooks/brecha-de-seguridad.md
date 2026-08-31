# Runbook — brecha de seguridad de datos personales (72 h)

**Esto no es una alerta: es un reloj.** El art. 33.1 del RGPD da **72 horas**
desde que el responsable del tratamiento *tiene conocimiento* de la brecha para
notificar a la autoridad de control. El reloj empieza cuando alguien del hotel se
entera, no cuando el incidente se termina de investigar. Se puede notificar de
forma escalonada (art. 33.4) con lo que se sepa, y completar después.

**Requisito que cubre:** RL-15 (capacidad de acotar el alcance de un acceso
indebido a datos personales). Lo aplica el **responsable del tratamiento**, que
es el hotel. El fabricante no accede a los datos del cliente (ADR-020, regla dura
16) y **no puede notificar por nadie**.

**Impacto en el fichaje: ninguno, por sí mismo.** Nadie se queda sin fichar
porque haya una brecha; el quiosco no bloquea al empleado en ningún caso (regla
dura 19). Si además hay fichaje afectado, es por otra causa —partición de
auditoría ausente, base caída— y esa se atiende primero con su propio runbook.

> Este documento es **procedimiento técnico**, no asesoramiento jurídico. Quién
> notifica, si procede notificar y con qué redacción **lo valida el DPO del
> cliente**. Aquí se explica cómo obtener los hechos con los que esa decisión se
> toma, y cómo no destruirlos por el camino.

---

## 0. Qué es una brecha aquí, y qué no

El art. 4.12 RGPD define brecha como toda violación de la seguridad que ocasione
**destrucción, pérdida, alteración, comunicación o acceso no autorizados** a
datos personales. Las tres formas caben, y en este producto tienen aspecto muy
distinto:

| Forma | Cómo se ve en KronoQR | Por dónde se empieza |
| --- | --- | --- |
| **Acceso o comunicación no autorizados** | Una cuenta consultó, listó o exportó datos de personas que no le corresponden | [§2](#2-detección-qué-lo-levanta) y [§4](#4-acotar-el-alcance-desde-audit_log) |
| **Alteración** | La cadena de hash de `audit_log` no verifica, o el registro horario no cuadra con los tramos | [`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md) y [`divergencia-proyeccion.md`](divergencia-proyeccion.md), **y luego vuelve aquí** |
| **Pérdida o destrucción** | Copia de seguridad perdida o irrecuperable, servidor cifrado por un tercero, exportación legal extraviada | [`restaurar-backup.md`](restaurar-backup.md), **y luego vuelve aquí** |

**Qué NO es una brecha, y conviene decirlo antes de que alguien active el
procedimiento a las 03:00:**

- Un **rechazo de escaneo**. Los rechazos son genéricos y de tiempo constante por
  diseño (RS-03, regla dura 17): una tarjeta rechazada no ha divulgado nada.
- Un **bloqueo por intentos** (`auth.lockout_started`, RS-12). Eso es el control
  funcionando. Es *señal*, no brecha: mira [§2](#2-detección-qué-lo-levanta).
- Un **`access.denied`** suelto. Es un intento de salirse del alcance por
  departamento que **el sistema frenó** (RF-ID-03): no hubo divulgación. Un
  patrón de `access.denied` sí es señal.
- Una **purga sellada** por retención. Es RL-02 haciendo su trabajo (ADR-027), no
  una destrucción no autorizada.
- El **préstamo de tarjeta** (*buddy punching*). Es fraude de fichaje aceptado por
  diseño (ADR-014, doc 07 §6), no una brecha de datos personales.

**El caso frontera que sí lo es:** una **exportación legal** o un **informe de
periodo** descargado por quien no debía. Ahí los datos **salieron del servidor**,
y eso es comunicación no autorizada aunque la cuenta estuviera autenticada.

---

## 1. Los datos que este producto puede perder

Sirve para dos cosas: para decidir si la brecha entraña riesgo (art. 33/34) y
para llenar el apartado «categorías de datos» de la notificación sin inventar.

| Categoría | Contenido | Dónde vive |
| --- | --- | --- |
| Identificación de plantilla | Nombre, apellidos, código de empleado, centro, departamento, alta y baja; correo **opcional** | `employees` |
| Registro horario | Marcas de entrada y salida, tramos, totales diarios, correcciones con motivo | `shift_entries`, `daily_totals`, `shift_corrections` |
| Condiciones de contrato | Horas semanales y anuales pactadas, tipo de jornada, vigencia | `employment_contracts` |
| Trazas de acceso | Quién hizo qué y cuándo, con IP y `User-Agent` | `audit_log` |
| Credenciales | Hash del PIN, secreto de la tarjeta; **nunca en claro** | `employees`, `credentials` |

**Lo que este producto no puede perder, porque no lo trata:** nada biométrico
(ADR-009, regla dura 20), ninguna geolocalización por persona, ningún dato de
categoría especial del art. 9 RGPD. Decirlo en la notificación **baja el riesgo
evaluado** y es cierto: compruébalo, no lo copies.

**El log técnico y `error_events` no llevan nombres** (regla dura 21):
identifican por `employee_uuid`. Un volcado de logs filtrado no es, por sí solo,
una brecha de identidad — aunque el UUID sea reidentificable dentro del hotel.

---

## 2. Detección: qué lo levanta

Casi nunca lo levanta una alerta: lo levanta una persona. Aun así, hay cuatro
señales técnicas y conviene saber qué significa cada una.

| Señal | Dónde | Qué significa |
| --- | --- | --- |
| `personal_data.accessed` | `audit_log` | **Alguien leyó datos de terceros.** Es el asiento normal de la operación diaria: solo es señal por su **volumen**, su **hora** o su **actor** |
| `access.denied` | `audit_log` | Alguien autenticado fue a por datos fuera de su alcance y se le negó (RF-ID-03). Uno es un error de interfaz; una serie es tanteo |
| `auth.lockout_started` | `audit_log` | Se abrió un bloqueo por intentos (RS-12, ADR-039) |
| `legal_export.generated` | `audit_log` | Se generó una exportación legal. **Los datos salieron del servidor** |
| `KronoqrAuthFailureBurst` · `KronoqrAuthLockouts` · `KronoqrAuthFailureSpike` | Alertmanager, [`auth.yml`](../../infra/observability/prometheus/rules/auth.yml) | Ataque a credenciales (MITRE ATT&CK T1110 / T1110.004). Su procedimiento es [`ataque-a-credenciales.md`](ataque-a-credenciales.md): **vuelve aquí solo si alguna cuenta llegó a entrar** |

Las tres consultas que dicen en un minuto si hay algo raro. Lecturas con el rol
de la aplicación, que tiene `SELECT` sobre `audit_log` y nada más:

```bash
# a) Accesos a datos personales de las últimas 48 h, por cuenta y conjunto.
docker compose --env-file .env -f infra/compose.prod.yaml exec -T postgres \
  psql -U fichaje_app -d fichaje -c "
  SELECT actor_type, actor_id,
         payload->>'dataset'                       AS conjunto,
         count(*)                                  AS lecturas,
         sum((payload->>'record_count')::int)      AS registros,
         min(occurred_at) AS desde, max(occurred_at) AS hasta
    FROM audit_log
   WHERE action = 'personal_data.accessed'
     AND occurred_at > now() - interval '48 hours'
   GROUP BY 1,2,3 ORDER BY registros DESC;"

# b) Denegaciones por alcance: quién tanteó, sobre qué y cuántas veces.
#    `repeated_since_last_entry` cuenta las agrupadas por ventana (ADR-037):
#    súmalo o subestimarás el intento.
docker compose --env-file .env -f infra/compose.prod.yaml exec -T postgres \
  psql -U fichaje_app -d fichaje -c "
  SELECT actor_type, actor_id, payload->>'dataset' AS conjunto,
         count(*) + COALESCE(sum((payload->>'repeated_since_last_entry')::int), 0) AS intentos,
         min(occurred_at) AS desde, max(occurred_at) AS hasta
    FROM audit_log
   WHERE action = 'access.denied'
     AND occurred_at > now() - interval '7 days'
   GROUP BY 1,2,3 ORDER BY intentos DESC;"

# c) Bloqueos y entradas de las últimas 72 h, en orden. Lo que importa no es el
#    bloqueo: es si DESPUÉS del bloqueo esa misma cuenta entró.
docker compose --env-file .env -f infra/compose.prod.yaml exec -T postgres \
  psql -U fichaje_app -d fichaje -c "
  SELECT occurred_at, action, actor_type, actor_id, ip
    FROM audit_log
   WHERE action IN ('auth.lockout_started','auth.login_succeeded',
                    'auth.two_factor_enabled','auth.two_factor_reset')
     AND occurred_at > now() - interval '72 hours'
   ORDER BY occurred_at;"
```

**Cómo se lee la (a).** Un `dataset` de `kiosk_roster` con actor `device` cada
pocas horas es el padrón del quiosco refrescándose: normal. Un
`employee_directory` o un `period_report` con `record_count` de la plantilla
entera, a las 04:00, desde una cuenta que nunca saca informes, **no**.

**Un detalle que evita una conclusión errónea:** `live_presence` **agrupa** sus
asientos por ventana de 15 minutos (ADR-037, `backend/config/compliance.php`). El
número de filas de ese conjunto **no** es el número de lecturas: hay que sumar
`repeated_since_last_entry`. Ningún otro conjunto agrupa.

---

## 3. Antes de tocar nada: preservar la evidencia

En este orden y **antes** de reiniciar, restaurar, migrar o rotar nada. Lo que se
pierde aquí no vuelve, y sin ello la notificación del art. 33 no puede describir
el alcance.

```bash
# 0. Marca temporal del incidente. Nombra todo lo demás con ella.
INC="brecha-$(date -u +%Y%m%dT%H%M%SZ)"; echo "$INC"
mkdir -p "/var/backups/fichaje/evidencia/$INC"

# 1. Copia física inmediata. NO esperes a la copia nocturna: una restauración
#    o un mantenimiento posterior se lleva la evidencia.
docker compose --env-file .env -f infra/compose.prod.yaml exec -T app \
  php artisan backup:run --mode=dump

# 2. La cadena de auditoría, verificada AHORA. Si ya estaba rota, el alcance
#    que calcules a partir de audit_log no es fiable: ve primero a
#    rotura-cadena-auditoria.md.
docker compose --env-file .env -f infra/compose.prod.yaml exec -T app \
  php artisan compliance:verify-audit-chain \
  | tee "/var/backups/fichaje/evidencia/$INC/verificacion-cadena.txt"

# 3. Extracto de los asientos relevantes, en CSV, para trabajar fuera.
#    Ajusta la ventana a la que sospeches; es preferible pasarse.
docker compose --env-file .env -f infra/compose.prod.yaml exec -T postgres \
  psql -U fichaje_app -d fichaje -c "\copy (
      SELECT id, occurred_at, actor_type, actor_id, action,
             subject_type, subject_id, payload, ip, user_agent
        FROM audit_log
       WHERE occurred_at > now() - interval '30 days'
         AND action IN ('personal_data.accessed','access.denied','legal_export.generated',
                        'auth.login_succeeded','auth.logout','auth.lockout_started',
                        'auth.two_factor_enabled','auth.two_factor_reset',
                        'role_assignment.changed','permission.changed',
                        'credential.revoked','credential.reissued','device.revoked')
       ORDER BY id
  ) TO STDOUT WITH CSV HEADER" > "/var/backups/fichaje/evidencia/$INC/audit.csv"

# 4. Sesiones abiertas contra la base y desde dónde, ahora mismo.
docker compose --env-file .env -f infra/compose.prod.yaml exec -T postgres \
  psql -U fichaje_app -d fichaje -c \
  "SELECT usename, application_name, client_addr, backend_start, state
     FROM pg_stat_activity WHERE datname = current_database();"

# 5. Log técnico y del borde de la ventana sospechosa. No llevan nombres
#    (regla dura 21): se pueden mover sin más precaución que la habitual.
docker compose --env-file .env -f infra/compose.prod.yaml logs --no-color --since 168h app nginx \
  > "/var/backups/fichaje/evidencia/$INC/aplicacion-y-borde.log"
```

**Tres cosas que NO se hacen todavía:**

1. **No ejecutes `compliance:apply-retention`.** Una purga en curso durante una
   investigación se lleva justo lo que hay que acotar. Si la propuesta semanal
   está aprobada, **espera**: el vencimiento aguanta unos días, el plazo de 72 h
   no.
2. **No borres ni reescribas nada de `audit_log`.** No se puede —el rol de la
   aplicación no tiene `UPDATE` ni `DELETE` (ADR-033)— y ese es el motivo por el
   que la tabla sirve de prueba.
3. **No revoques credenciales todavía si aún no sabes el alcance**, salvo que el
   acceso siga vivo. Revocar es correcto y a veces urgente
   ([§6](#6-contener-y-cerrar-la-vía)), pero hazlo **después** de sacar el
   extracto del paso 3, y déjalo escrito.

Copia el directorio `evidencia/$INC` **fuera del servidor** antes de seguir.

---

## 4. Acotar el alcance desde `audit_log`

Esta es la sección por la que existe RL-15 y la que responde a lo que la AEPD
pregunta primero. Dos preguntas, y las dos se contestan con la tabla.

### 4.1 «¿Qué cuentas accedieron a los datos de esta persona, y cuándo?»

Los conjuntos que se leen **por persona** llevan su `employee_uuid` en el
payload; los que se leen **en bloque** no, y por eso hay dos mitades. La primera
es exacta; la segunda dice *qué lecturas la contenían con seguridad*.

```bash
UUID='<employee_uuid de la persona>'   # se ve en su ficha del panel

# a) Accesos NOMINALES: alguien fue a por los datos de ESA persona.
#    Cubre employee_workdays (su registro), incident_digest (el resumen que sale
#    por correo), la exportación legal por empleado y las denegaciones sobre su
#    ficha.
docker compose --env-file .env -f infra/compose.prod.yaml exec -T postgres \
  psql -U fichaje_app -d fichaje -c "
  SELECT occurred_at, action, actor_type, actor_id,
         payload->>'dataset' AS conjunto, ip, payload
    FROM audit_log
   WHERE action IN ('personal_data.accessed','access.denied','legal_export.generated')
     AND (payload->>'employee_uuid' = '$UUID'
          OR payload->>'employee_uuids' LIKE '%$UUID%')
   ORDER BY occurred_at;"

# b) Accesos EN BLOQUE que la contenían. No nombran a nadie a propósito —el
#    padrón o el directorio en audit_log serían una segunda copia del padrón—,
#    así que aquí el alcance se acota por CONJUNTO y por VENTANA, no por persona.
docker compose --env-file .env -f infra/compose.prod.yaml exec -T postgres \
  psql -U fichaje_app -d fichaje -c "
  SELECT occurred_at, actor_type, actor_id,
         payload->>'dataset'       AS conjunto,
         payload->>'record_count'  AS registros,
         payload->>'scope'         AS alcance,
         payload->>'department_id' AS departamento, ip
    FROM audit_log
   WHERE action = 'personal_data.accessed'
     AND payload->>'dataset' IN ('employee_directory','kiosk_roster','credential_status',
                                 'incident_board','period_report','live_presence')
     AND occurred_at BETWEEN '<inicio de la ventana>' AND '<fin de la ventana>'
   ORDER BY occurred_at;"
```

**Cómo se interpreta la (b), que es donde se equivoca todo el mundo.** Una fila
de `employee_directory` con `scope = all` incluye a esa persona si estaba de alta
ese día; una con `scope = departments` solo si su departamento entra en el
alcance de esa cuenta —consúltalo en el panel, en la ficha del usuario—. El
`department_id` del payload acota más. `kiosk_roster` es la plantilla activa del
centro: si el padrón se descargó, esa persona estaba dentro.

**Los conjuntos son estos y no hay más** (vocabulario estable de
`PersonalDataAccessLog`):

| `dataset` | Qué se divulgó | Nominal en el payload |
| --- | --- | --- |
| `employee_directory` | Listado de plantilla del panel | No (recuento y alcance) |
| `kiosk_roster` | Padrón que descarga una tablet | No (recuento y dispositivo) |
| `credential_status` | Bandeja de credenciales | No (recuento y alcance) |
| `incident_board` | Bandeja de incidencias | No (recuento y alcance) |
| `incident_digest` | **Resumen que sale por correo** (RF-PR-01) | **Sí**: `employee_uuids` |
| `employee_workdays` | Registro horario de una persona | **Sí**: `employee_uuid` |
| `period_report` | Informe de periodo, en pantalla o descargado | No (recuento, `format`, alcance) |
| `live_presence` | Presencia en vivo del panel | No. **Agrupado por ventana de 15 min** |
| `incident` | Una incidencia concreta al resolverla | Según el asiento |

### 4.2 «¿Qué se llevó esta cuenta, y cuándo?»

La pregunta simétrica, cuando ya se sospecha de una cuenta o de un dispositivo:

```bash
ACTOR_ID='<id de la cuenta>'   # SELECT id, email FROM users WHERE email = '...'

docker compose --env-file .env -f infra/compose.prod.yaml exec -T postgres \
  psql -U fichaje_app -d fichaje -c "
  SELECT occurred_at, action,
         payload->>'dataset'            AS conjunto,
         payload->>'record_count'       AS registros,
         payload->>'format'             AS formato,
         payload->>'employees_exported' AS exportados,
         ip, user_agent
    FROM audit_log
   WHERE actor_type = 'user' AND actor_id = $ACTOR_ID
     AND action IN ('personal_data.accessed','legal_export.generated','access.denied')
   ORDER BY occurred_at;"
```

**Lo que hay que buscar en esa salida, por orden de gravedad:**

1. `legal_export.generated` — **los datos salieron en un fichero.** El payload
   lleva `period_from`, `period_to`, `scope`, `employees_exported` y los recuentos
   de filas: eso es, literalmente, el alcance de la brecha. El fichero está en
   `storage/app/legal-exports/` y **no lo limpia ningún cron**: compruébalo.
2. `personal_data.accessed` con `dataset = period_report` y un `format` de
   descarga — se llevaron un fichero con horas de plantilla, no una pantalla.
3. `dataset = incident_digest` — **salió por correo**, a otra máquina. Los
   `employee_uuids` afectados están en el asiento.
4. `dataset = employee_directory` o `kiosk_roster` con `record_count` alto —
   alguien tuvo la plantilla entera delante.

**Y el otro lado del mismo hilo:** qué autoridad tenía esa cuenta y quién se la
dio.

```bash
docker compose --env-file .env -f infra/compose.prod.yaml exec -T postgres \
  psql -U fichaje_app -d fichaje -c "
  SELECT occurred_at, action, actor_type, actor_id, subject_type, subject_id, payload
    FROM audit_log
   WHERE action IN ('role_assignment.changed','permission.changed',
                    'auth.two_factor_reset','auth.two_factor_enabled')
     AND occurred_at > now() - interval '90 days'
   ORDER BY occurred_at;"
```

Un `role_assignment.changed` inmediatamente anterior a la ráfaga de accesos
cambia por completo la naturaleza del incidente: deja de ser «una cuenta miró de
más» y pasa a ser «alguien le dio permisos para que mirara».

### 4.3 El número que hay que poder decir

Al cerrar esta sección tienes que poder rellenar, con datos y no con
estimaciones:

- **Cuántas personas** afectadas (`employee_uuid` distintos, o el censo del
  conjunto si fue una lectura en bloque).
- **Qué categorías** de datos, de la tabla del
  [§1](#1-los-datos-que-este-producto-puede-perder).
- **Ventana temporal**: primer y último asiento implicados.
- **Si los datos salieron del servidor** (exportación, descarga, correo) o solo
  se vieron en pantalla. Es la diferencia entre riesgo alto y riesgo contenido.
- **Si la cadena de auditoría verifica.** Si no, dilo: el alcance calculado tiene
  un límite de confianza y eso también se notifica.

---

## 5. Decidir si se notifica: art. 33 y art. 34

**Quién decide.** El **responsable del tratamiento** es el hotel, y la decisión
la toma con su **DPO** (o con su asesoría, si no tiene DPO designado). Ni IT ni
el fabricante deciden esto. Lo que IT aporta son los hechos del
[§4](#4-acotar-el-alcance-desde-audit_log).

**El plazo son 72 horas** desde el conocimiento (art. 33.1). Si se pasa, la
notificación se envía igual **indicando el motivo del retraso**: llegar tarde con
explicación es mucho mejor que no llegar.

| Pregunta | Artículo | Si la respuesta es sí |
| --- | --- | --- |
| ¿Hay violación de seguridad con datos personales implicados? | 4.12 | Sigue leyendo. Si no, se documenta internamente y se cierra |
| ¿Es **improbable** que entrañe riesgo para los derechos de las personas? | 33.1 | **No se notifica a la autoridad**, pero **sí se documenta** (art. 33.5). Ejemplo típico: acceso interno fuera de alcance, sin extracción, detectado y cerrado en minutos |
| ¿Entraña riesgo? | 33.1 | **Notificación a la autoridad de control en 72 h** |
| ¿Entraña **alto** riesgo para los interesados? | 34.1 | **Además, comunicación a cada persona afectada, sin dilación indebida** |
| ¿Los datos eran ininteligibles para quien accedió (cifrados, con la clave a salvo)? | 34.3.a | La comunicación a los afectados **puede no ser necesaria**. Aplica a una copia de seguridad cifrada extraviada cuya `BACKUP_ENCRYPTION_KEY` no se ha comprometido |

**Lo que en este producto sube el riesgo:** que los datos **salgan** del servidor
(exportación legal, informe descargado, correo), que afecten a **toda la
plantilla**, o que incluyan el registro horario completo de personas concretas
—que es información sobre sus hábitos y su vida diaria—.

**Lo que lo baja, y es cierto:** no hay biometría, no hay geolocalización, no hay
datos del art. 9, los PIN y las contraseñas se guardan con bcrypt y no en claro,
y el `audit_log` permite acotar el alcance con precisión de segundo.

**Se documenta siempre, se notifique o no** (art. 33.5). Un incidente valorado y
descartado **por escrito** es defendible; uno que nadie anotó, no.

---

## 6. Contener y cerrar la vía

En paralelo a la investigación, no después. Cada acción de contención deja su
propio asiento en `audit_log` —`credential.revoked`, `credential.reissued`,
`auth.two_factor_reset`, `device.revoked`—, y eso es lo que acredita la reacción.

```bash
# Retirar el segundo factor de una cuenta de gestión cuyo TOTP pudo quedar en
# manos ajenas. Deja `auth.two_factor_reset` con actor, momento y motivo; la
# cuenta vuelve a tener que activarlo en su siguiente acceso.
docker compose --env-file .env -f infra/compose.prod.yaml exec -T app \
  php artisan identity:2fa-reset <users.uuid> --reason='<motivo>'

# Revocar la tarjeta comprometida de una persona. Deja `credential.revoked`
# con el motivo; la reemisión posterior deja `credential.reissued`.
docker compose --env-file .env -f infra/compose.prod.yaml exec -T app \
  php artisan credentials:revoke <uuid de la credencial> --reason='<motivo>'

# Qué más hay disponible en TU versión, por módulo. Compruébalo antes de
# teclear nada a las 03:00: este runbook no sustituye a `--help`.
docker compose --env-file .env -f infra/compose.prod.yaml exec -T app \
  php artisan list identity
docker compose --env-file .env -f infra/compose.prod.yaml exec -T app \
  php artisan list credentials
```

Lo demás se hace **desde el panel**: desactivar una cuenta de gestión, retirarle
el rol o revocar un dispositivo de quiosco.

Procedimientos que ya están escritos y que aquí solo se enlazan:

- **Tarjeta comprometida o extraviada**, revocación y reemisión en el día:
  [`rotacion-clave-qr.md`](rotacion-clave-qr.md) §4 explica cómo se reimprime sin
  dejar a nadie sin fichar (regla dura 19).
- **Material criptográfico comprometido** —`APP_KEY`, clave HMAC del QR,
  contraseña de un rol de base de datos, `BACKUP_ENCRYPTION_KEY`—:
  [`rotacion-secretos.md`](rotacion-secretos.md).
- **Ataque a credenciales todavía en curso**, incluido el bloqueo en el borde:
  [`ataque-a-credenciales.md`](ataque-a-credenciales.md).

**Vuelta atrás.** Ninguna acción de contención se deshace «restaurando»: una
credencial revocada se **reemite**, un token revocado se vuelve a emitir al
iniciar sesión, y un segundo factor restablecido lo vuelve a activar su titular.
Restaurar una copia de seguridad para «deshacer» una contención **borraría los
asientos del incidente**: no se hace.

---

## 7. Plantillas

Breves a propósito. **Las valida el DPO antes de salir**; aquí están para que a
las 03:00 nadie empiece con la página en blanco.

### 7.1 Notificación a la autoridad de control (art. 33.3)

> **Notificación de violación de la seguridad de los datos personales**
>
> **Responsable del tratamiento:** [razón social, NIF, dirección].
> **Contacto (DPO o punto de contacto):** [nombre, correo, teléfono].
>
> **1. Descripción de la naturaleza de la violación.** El día DD/MM/AAAA a las
> HH:MM se detectó [acceso no autorizado / comunicación no autorizada /
> alteración / pérdida] de datos personales en el sistema de registro de jornada.
> [Cómo se detectó: alerta, asiento de auditoría, aviso de una persona].
>
> **2. Categorías y número aproximado de interesados:** personas trabajadoras del
> centro; **N** afectadas.
>
> **3. Categorías y número aproximado de registros:** datos identificativos de
> plantilla y registro horario; **N** registros. No se tratan datos de las
> categorías especiales del art. 9 RGPD, ni datos biométricos, ni datos de
> geolocalización.
>
> **4. Consecuencias probables:** [conocimiento por terceros de la jornada
> laboral de las personas afectadas / …].
>
> **5. Medidas adoptadas o propuestas:** [contención: revocación de credenciales,
> rotación de claves, cierre de la vía de acceso]. La trazabilidad del sistema
> —registro de auditoría encadenado por hash— ha permitido acotar el alcance a la
> ventana comprendida entre DD/MM/AAAA HH:MM y DD/MM/AAAA HH:MM.
>
> **6. Fecha y hora de conocimiento:** DD/MM/AAAA HH:MM.
> [Si procede] **Motivo del retraso respecto de las 72 horas:** […].

### 7.2 Comunicación a las personas afectadas (art. 34.2)

En lenguaje claro, sin tecnicismos y sin culpar a nadie:

> Estimado/a [nombre]:
>
> Te informamos de que el DD/MM/AAAA se produjo un acceso no autorizado a datos
> del sistema de registro de jornada de [centro]. Entre los datos afectados se
> encuentran [tu nombre, tu código de empleado y tus registros de entrada y
> salida entre DD/MM/AAAA y DD/MM/AAAA].
>
> **No se han visto afectadas tus contraseñas ni tu PIN**, que se almacenan
> cifrados y no son legibles. El sistema no trata datos biométricos ni de
> localización.
>
> Hemos [medidas adoptadas] y hemos comunicado el incidente a la autoridad de
> control competente.
>
> Puedes dirigir cualquier consulta, o ejercer tus derechos de acceso,
> rectificación, limitación u oposición, en [canal de contacto]. Nuestro delegado
> de protección de datos es [nombre / correo].

**Si la persona ejerce un derecho a raíz de esta comunicación**, se atiende con
[`solicitud-derechos-rgpd.md`](solicitud-derechos-rgpd.md), y el plazo de ese
procedimiento es otro: **un mes**.

---

## 8. Preservación y cierre

**Lo que se conserva, y no se purga hasta que el expediente esté cerrado:**

| Qué | Dónde | Cuidado |
| --- | --- | --- |
| Extracto de `audit_log` del incidente | `evidencia/$INC/audit.csv`, fuera del servidor | Lleva `employee_uuid` e IP: **es un fichero con datos personales**. Custódialo como tal |
| Copia física de la base | La del paso 1 del [§3](#3-antes-de-tocar-nada-preservar-la-evidencia) | Cifrada con `BACKUP_ENCRYPTION_KEY`. Sin esa clave no se restaura |
| Verificación de la cadena | `evidencia/$INC/verificacion-cadena.txt` | No lleva datos personales: se puede compartir tal cual |
| Logs de aplicación y borde | `evidencia/$INC/aplicacion-y-borde.log` | Sin nombres (regla dura 21) |
| Notificación enviada y acuse | Registro interno del hotel | Es lo que acredita el cumplimiento del art. 33 |
| Valoración del DPO, se notifique o no | Registro interno del hotel | **Obligatorio por el art. 33.5**, también cuando se decide no notificar |

**Sellar la evidencia.** Un extracto sin sello es un fichero que alguien pudo
editar. Basta con esto, y el resguardo se guarda **separado** del fichero:

```bash
cd "/var/backups/fichaje/evidencia/$INC" && sha256sum * > SHA256SUMS
# El resguardo va a otro sitio: correo interno, gestor documental, papel.
cat SHA256SUMS
```

**La retención no se reanuda hasta el cierre.** Cuando el expediente esté
cerrado, la purga vencida vuelve a su procedimiento normal
([`solicitud-derechos-rgpd.md`](solicitud-derechos-rgpd.md) §6) — y ni antes, ni
como forma de «limpiar» nada.

---

## 9. Escalado

| Situación | A quién | En cuánto |
| --- | --- | --- |
| Sospecha de brecha, cualquiera | Responsable de seguridad del cliente **y DPO** | Inmediato. El reloj de las 72 h corre |
| Datos que **salieron** del servidor (exportación, descarga, correo) | DPO y dirección | Inmediato |
| Acceso vivo todavía en curso | IT del cliente, para contener; seguridad informada | Inmediato |
| Cadena de auditoría rota además del acceso | Sigue [`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md) §2 **antes** que este runbook | Inmediato |
| Sospecha de un defecto del producto como causa | Fabricante, con el paquete de diagnóstico **anonimizado** | Dentro de la jornada |

**El fabricante no accede a los datos del cliente** (ADR-020, regla dura 16). Lo
que se le envía es el paquete de diagnóstico anonimizado; **el extracto de
`audit_log`, los logs con IP y la copia de la base no salen de la instalación**
salvo concesión expresa, temporal y auditada del cliente.
