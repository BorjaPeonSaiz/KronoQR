# Runbook — diagnosticar una incidencia sin acceso al servidor del cliente

> **Quién lo usa:** soporte del fabricante, con un paquete de diagnóstico
> delante. **Cuándo:** cada vez que un cliente abre una incidencia. **Cuánto
> dura:** entre 10 y 30 minutos hasta tener una hipótesis; casi todas las
> incidencias se resuelven sin pasar del paso 4.
>
> Este runbook es el que decide si el paquete está bien diseñado (ficha 5.9):
> si para diagnosticar hay que pedir «¿puedes mirar los datos?», el paquete ha
> fallado y hay que corregir el paquete, no pedir acceso. El acceso directo es
> la excepción del paso 6 y se pide **como concesión expresa, temporal, con
> alcance y motivo**, nunca como atajo ([ADR-020](../adr/ADR-020-soporte-con-paquete-de-diagnostico.md),
> regla dura 16).

---

## 1. Lo que no vas a hacer

- **No vas a pedir nombres, correos ni fichajes.** El paquete anonimizado
  identifica a los empleados por `employee_uuid` y a los quioscos por `uuid`,
  y con eso se diagnostica: un `uuid` que se repite en tres errores es el mismo
  patrón que un nombre que se repite en tres errores.
- **No vas a pedir el `.env`, ni una clave, ni un volcado de base de datos.**
  El paquete lleva la configuración que hace falta para diagnosticar y ninguna
  secreta; si crees que te falta una clave, es que quieres saber si está bien
  puesta, y eso lo dice `doctor` (paso 3).
- **No vas a pedir el `update-<marca>.detalle.log`.** Puede llevar datos
  personales y por eso el paquete no lo incluye (doc 07 §6). Si el problema es
  una actualización, el informe `update-<marca>.log` sí va, y basta.

---

## 2. Comprobar que el paquete es lo que dice ser (2 minutos)

El cliente te ha enviado `kronoqr-diagnostics-<version>-<UTC>.json`. Es un
único fichero JSON legible; ábrelo con lo que tengas a mano.

1. **`manifest`**: mira `product_version`, `generated_at`, `anonymized` y
   `generated_by`. Si `anonymized` es `false`, el cliente **eligió** incluir
   datos personales: trátalo como tal (paso 7) y no lo reenvíes a nadie.
2. **Huella.** `manifest.sha256` es el SHA-256 del documento **sin** el
   `manifest`, serializado en JSON canónico (claves ordenadas, sin espacios).
   Si no coincide, el fichero está truncado o se ha editado por el camino:
   pídelo de nuevo antes de diagnosticar nada.

   ```bash
   docker compose exec -T app php artisan product:diagnostics --verify /ruta/al/paquete.json
   ```

   El comando lo recalcula y dice si coincide. Si no tienes una instalación a
   mano, cualquier herramienta que canonice JSON sirve; lo importante es no
   diagnosticar sobre un paquete a medias.
3. **`manifest.sections`** enumera lo que hay. Una sección que dice
   `{"status": "not_installed"}` o `{"status": "unavailable", "reason": …}` no
   es un fallo del paquete: dice que en esa instalación esa fuente no existe o
   no se pudo leer, y **por qué**.

---

## 3. Empezar siempre por `doctor` (5 minutos)

`doctor` es la comprobación de salud que el cliente puede ejecutar solo
(RF-PD-13), y el paquete lleva su resultado completo en la sección `doctor`.
Es lo primero que se mira porque responde a las dos primeras preguntas de
cualquier incidencia: **¿hay algo roto?** y **¿desde cuándo?**

| `status` | Qué significa | Qué hacer |
| --- | --- | --- |
| `ok` | Nada roto en el momento de generar el paquete | El problema no es de infraestructura: sigue por el paso 4 |
| `warning` | Algo que conviene mirar y **no** explica una caída | Léelos; a menudo explican un problema intermitente (disco al 85 %, certificado a 20 días, cola con retraso) |
| `failure` | Algo que hay que corregir | Cada comprobación en rojo trae un `fix` redactado para el IT del cliente: **cópialo tal cual** en la respuesta |

Comprobaciones que más veces explican una incidencia, por familia:

- **`database.*`**: conexión, migraciones pendientes (una actualización a
  medias), privilegios del usuario de la aplicación sobre `audit_log` (regla
  dura 6) y cadena de auditoría.
- **`queue.*`**: Redis, tamaño de las colas y si hay un trabajador vivo. Una
  cola que crece sin trabajador es la causa más común de «los informes no
  llegan» y de «la presencia en vivo no se mueve».
- **`tls.certificate`**: caducado o autofirmado con `TLS_ALLOW_SELF_SIGNED`
  apagado. Es la causa más común de «las tablets dicen sin conexión» cuando
  la red está bien.
- **`disk.*`**, **`permissions.*`**: el quiosco sigue fichando sin disco
  (regla dura 19), pero las copias, los informes y los PDF no.
- **`license.*`**: nunca pasa de `warning` y **nunca explica que no se pueda
  fichar** (regla dura 15). Si el cliente dice que no puede fichar y `doctor`
  solo tiene un aviso de licencia, la causa es otra.

---

## 4. Leer el resto del paquete en este orden (10 minutos)

1. **`installation`** — versión, entorno, zona horaria de la aplicación
   (debe ser `UTC`) y del centro, idiomas, perfil de cumplimiento. Una
   versión que no es la última explica los defectos ya corregidos: mira el
   `CHANGELOG` antes de seguir.
2. **`services`** — base de datos, Redis, colas, Reverb y `audit_log` (tamaño
   y último asiento). Un `audit_log` cuyo último asiento es de hace días en una
   instalación activa es una señal de que nada escribe: mira la cola y el
   trabajador.
3. **`kiosks`** — por quiosco, `status`, `app_version`, `last_seen_at` y
   `pending_queue_size`. Lo que dice cada combinación:

   | Señal | Lectura |
   | --- | --- |
   | `last_seen_at` antiguo y `pending_queue_size` alto | La tablet no llega al servidor: red, TLS o VLAN (`KIOSK_VLAN_CIDR`, que no va en el paquete pero sí en la guía de instalación) |
   | `last_seen_at` reciente y `pending_queue_size` alto | Llega, pero el envío falla: mira `error_events` y el limitador del quiosco |
   | `app_version` distinta entre quioscos | Una tablet no ha recibido la PWA nueva; los errores de esa tablet pueden ser de la versión anterior |
   | `status: revoked` | Desvinculada a propósito; si el cliente dice que «no ficha», es por eso |

4. **`error_events`** — el histórico de errores agrupado por huella con su
   `trace_id` (RF-PD-15). Hasta la tarea 5.12 dice `not_installed`; a partir
   de ella, es la sección con la que se resuelve la mayoría de las
   incidencias: la huella agrupa las repeticiones y el `trace_id` correlaciona
   con la petición.
5. **`configuration`** — solo las claves de la lista blanca, con su valor, y
   dos cosas que valen oro: `invalid_keys` (claves de la base de datos con un
   valor que no valida, tal y como las devuelve `GET /api/v1/settings`) y
   `env_differs_from_db` (claves cuyo valor en `.env` no coincide con el de la
   base de datos: la base de datos manda, y el IT suele creer que manda el
   `.env`).
6. **`updates`** — el último informe de actualización y la lista de los
   anteriores. Si la incidencia empezó «después de actualizar», aquí está la
   secuencia completa con sus comprobaciones y su código de salida.
7. **`metrics`** — contadores agregados: escaneos por resultado, latidos,
   peticiones por código de respuesta. Sirven para ver **proporciones**: un
   30 % de `rejected_signature` es una rotación de clave a medias; un 30 % de
   `429` es un limitador mal dimensionado para ese hotel.
8. **`audit`** — recuentos por familia y por día, y si la cadena verifica.
   Ningún payload. Si la cadena **no** verifica, para aquí: es
   [`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md), no una
   incidencia de soporte.
9. **`license`** — estado, plan, límites y huella corta; **sin razón social**.
   Solo para explicar una funcionalidad accesoria degradada; nunca para
   explicar un fallo del registro.

Con esto se llega a una hipótesis en casi todos los casos. Escríbela en la
respuesta al cliente junto con el `fix` literal de `doctor` cuando lo haya.

---

## 5. Lo que hace el cliente si la hipótesis exige cambiar algo

Todo lo que el runbook puede pedirle está en su documentación:

- Cambiar configuración: panel → sección correspondiente
  (`docs/cliente/configuracion.md`).
- Reiniciar servicios, ver colas, ver logs: `docs/cliente/operacion.md`.
- Actualizar: [`actualizacion-cliente.md`](actualizacion-cliente.md).
- Volver a generar el paquete tras el cambio: panel → «Soporte» → «Generar y
  descargar», o `php artisan product:diagnostics`.

No se le pide que ejecute SQL, que edite ficheros dentro del contenedor ni que
envíe nada que no salga de esos dos documentos.

---

## 6. Cuándo pedir acceso, y cómo (excepción)

Se pide acceso **solo** cuando el paquete y una segunda ronda de preguntas no
bastan, y se pide con:

- **Motivo**: el número de incidencia y lo que se va a mirar.
- **Alcance mínimo** (RF-PD-11): `diagnostics` casi siempre (generar paquetes
  y leer errores); `read_only` si hay que ver jornadas o auditoría;
  `configuration` solo si el cliente prefiere que soporte haga el cambio en
  lugar de hacerlo él (ajustes operativos y quioscos; nunca el perfil de
  cumplimiento, la licencia ni el asistente).
- **Duración**: la de la intervención, no «por si acaso». 24 h de serie,
  72 h como máximo.

El cliente lo concede desde el panel («Soporte» → «Accesos de soporte») o con
`php artisan support:grant --hours=24 --reason="Incidencia #123"`, y te
entrega el token **por el canal del contrato**. Ese token:

- caduca solo cuando termina la concesión;
- deja asiento en `audit_log` al concederse, en cada uso efectivo y al
  revocarse, **visible para el cliente** en su panel;
- no puede activar licencias, conceder otros accesos, tocar credenciales,
  corregir fichajes ni generar un paquete con datos personales (RL-19).

Durante esa intervención el fabricante es **encargado del tratamiento para ese
supuesto concreto** (RL-18): rige el contrato de encargo del art. 28 RGPD.
Al terminar, pide al cliente que revoque, aunque quede tiempo, y **no
conserves nada** de lo consultado.

---

## 7. Si el paquete lleva datos personales

Solo ocurre si el cliente marcó «Incluir datos personales» (o pasó
`--with-personal-data`): `manifest.anonymized` es `false`, hay una sección
`personal_data` y el cliente tiene el asiento `diagnostics.personal_data_included`
en su auditoría.

- Trátalo como dato del cliente bajo encargo (RL-18): quien lo abre, cuándo y
  para qué queda en el registro interno de soporte.
- No lo reenvíes, no lo adjuntes a un tíquet abierto a terceros, no lo copies
  a un cuaderno.
- Bórralo al cerrar la incidencia y confírmaselo al cliente por escrito.

Si te llega un paquete con datos personales **sin que la incidencia lo
exigiera**, dilo: el cliente debería usar el anonimizado, y que lo sepa es
parte del soporte.

---

## 8. Si el paquete no basta y no es cosa del cliente

Si para diagnosticar te faltó algo que el paquete debería llevar —un contador,
una comprobación de `doctor`, una sección—, **abre una tarea contra el paquete**,
no contra el cliente. La regla de ADR-020 es que la dificultad de diagnosticar
sin datos se convierte en requisito de producto: cada incidencia que exigió
acceso directo es un caso que el paquete tiene que cubrir la próxima vez.

Comprobado contra un paquete real generado en el entorno de desarrollo al
cerrar la tarea 5.9; ver la ficha de la tarea para el extracto.
