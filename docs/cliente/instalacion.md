# Instalación de KronoQR

Guía para el personal de IT del hotel. **No hace falta saber Laravel, PHP ni
Vue.** Hace falta un servidor Linux con Docker y treinta minutos.

> **Estado.** El procedimiento de esta guía es el real y está probado en cada
> publicación de versión (etapa de instalación limpia de la integración
> continua del fabricante). La **tarea 5.11** añadirá capturas de pantalla y la
> guía de endurecimiento; el procedimiento no cambiará.

---

## 0. Antes de empezar: los cinco minutos que ahorran la tarde

### El servidor

| Recurso | Mínimo (≤ 100 empleados) | Recomendado (≤ 500) |
| --- | --- | --- |
| CPU | 2 núcleos | 4 núcleos |
| RAM | 4 GB | 8 GB |
| Disco | 40 GB SSD | 100 GB SSD |
| Sistema | **Linux** con **Docker 24 o superior** y **Compose v2** | Íd. |
| Red | Alcanzable desde la red interna. Salida a internet **opcional** | Íd. |

**Linux con Docker, sin ambigüedad.** No existe instalador de Windows y no está
previsto que exista. Si tu infraestructura es solo Windows, la instalación va
sobre una **máquina virtual Linux** (Hyper-V, VMware, Proxmox: cualquiera).
Te lo decimos ahora y no a mitad del proceso.

**Sin salida a internet el sistema funciona entero.** La verificación de la
licencia es local por diseño: el producto no llama a ningún servidor del
fabricante, nunca. Sin internet solo pierdes los certificados automáticos de
Let's Encrypt (usas el tuyo) y el correo si tu SMTP es externo. Para instalar
sin internet, ver §7.

> **Sobre la memoria.** El instalador exige **3700 MiB** y no 4096, y no es una
> rebaja: una máquina virtual de 4 GB declara entre 3800 y 3950 MiB porque el
> propio núcleo se reserva una parte. Exigir 4096 haría fallar a toda máquina
> que cumple el mínimo publicado.

### Lo que tienes que traer decidido

1. **La URL** por la que los quioscos y el panel llegarán al servidor
   (`https://fichaje.tuhotel.local`, por ejemplo).
2. **El certificado TLS** para ese nombre, con su clave privada.
3. **El rango de red de las tablets** (`KIOSK_VLAN_CIDR`) y el de la red desde
   la que se podrá abrir el portal del empleado (`PORTAL_INTERNAL_CIDR`). Los
   dos están explicados en §6.
4. **Dónde se guardan las copias de seguridad** (`BACKUP_PATH`), y si ese
   destino es un recurso de red, **montado antes de instalar**.
5. **La clave de licencia**, si ya la tienes. Si no, instala igualmente: se
   activa después y el sistema **ficha con normalidad sin ella**.

### Lo que el instalador hace por ti, y lo que no

| Hace | No hace |
| --- | --- |
| Comprueba los requisitos antes de tocar nada | **No crea usuarios**: la primera cuenta la creas tú en el asistente |
| Genera todos los secretos **en tu servidor** | **No siembra datos de demostración**: ni un empleado, ni un fichaje |
| Levanta los servicios y aplica el esquema | **No pide licencia** para completarse |
| Verifica que el sistema responde | **No toca una instalación previa**: si la encuentra, se aparta |
| Deshace lo que haya hecho si algo falla | No configura tus tablets (eso es el runbook `alta-nuevo-quiosco.md`) |

---

## 1. El procedimiento, de principio a fin

### 1.1 Descomprime el paquete

```bash
tar xzf kronoqr-2.0.0.tar.gz
cd kronoqr-2.0.0
ls
```

Deberías ver exactamente esto:

```
docker-compose.yml       Definición de los servicios. No se toca.
.env.example             Plantilla de configuración. La copias en el paso 1.2.
install.sh               El instalador.
backup.sh restore.sh     Copias de seguridad y restauración.
restore-drill.sh         Simulacro trimestral de restauración.
lib/                     Bibliotecas de los scripts. No se tocan.
observability/           Configuración de las alertas. No se toca.
certs/                   Aquí colocas tu certificado (paso 1.2).
VERSION                  La versión que se va a instalar.
LICENCIA.txt
docs/                    Esta guía y las otras tres.
docs/runbooks/           Procedimientos: restaurar, rotar secretos, alta de
                         quiosco, requerimiento de Inspección, RGPD…
```

> **Lo que todavía NO viene en la 2.0.0, y cuándo llega.** Se dice aquí y no se
> descubre a mitad de una incidencia:
>
> | Pieza | Para qué | Llega en |
> | --- | --- | --- |
> | `update.sh` | Actualizar a una versión posterior con vuelta atrás | 2.1 |
> | `doctor.sh` | Comprobación de salud en un comando | 2.1 |
> | Asistente de puesta en marcha | Crear la organización y la primera cuenta desde el panel | 2.1 |
>
> Mientras tanto: la instalación se verifica con las dos sondas del paso 1.5, el
> estado se mira con `docker compose ps` y con los dos comandos de
> [`operacion.md`](operacion.md), y **no hay actualización desde una versión
> anterior porque la 2.0.0 es la primera**.

### 1.2 Coloca el certificado y rellena la configuración

```bash
cp /ruta/de/tu/certificado.crt certs/tls.crt
cp /ruta/de/tu/clave.key       certs/tls.key

# IMPRESCINDIBLE: el servidor web corre SIN PRIVILEGIOS, con el uid 101,
# y no puede abrir un fichero de root.
sudo chown 101:101 certs/tls.crt certs/tls.key
sudo chmod 0444 certs/tls.crt
sudo chmod 0400 certs/tls.key

cp .env.example .env
```

> **Por qué esas tres órdenes, y qué pasa si te las saltas.** `cp` conserva los
> permisos del original, y las claves privadas se escriben `0600` para el
> usuario que las creó —normalmente `root`—. El borde HTTP de KronoQR corre
> dentro de su contenedor **sin privilegios**, como el uid `101`, así que no
> puede abrir esa clave: nginx entra en **bucle de reinicio** con
> `cannot load certificate key ... Permission denied` y no se sirve nada.
>
> **El instalador lo comprueba en la fase 1 y no instala si no puede leerlos**,
> así que no vas a descubrirlo con el sistema a medias.
>
> **Si `TLS_CERT_DIR` apunta a un directorio compartido con otro servicio** —el
> de Let's Encrypt, por ejemplo—, **no le cambies el propietario**: romperías
> ese otro servicio. Copia el certificado a un directorio propio, apunta ahí
> `TLS_CERT_DIR` y aplica allí esas órdenes.
>
> Si `tls.key` queda legible por todo el servidor (`0444`, `0644`), el sistema
> **funciona** y el instalador solo avisa: es tu clave privada y la decisión es
> tuya, pero cualquiera con una sesión en esa máquina puede copiarla.

Abre `.env` con tu editor. **Cada variable lleva una marca. Solo tienes que
tocar las marcadas `[CLIENTE]`:**

| Marca | Qué significa |
| --- | --- |
| `[CLIENTE]` | Lo rellenas tú. El instalador comprueba que están y no instala si falta alguna. |
| `[INSTALADOR]` | **Déjalo vacío.** Lo genera el instalador en tu servidor. Si escribes algo, lo sustituye. |
| `[FIJO]` | No se toca. Cambiarlo rompe algo que no se parece a este fichero. |
| Sin marca | Tiene un valor por defecto pensado. Si dudas, déjalo como está. |

Lo mínimo que hay que rellenar:

```dotenv
APP_ENV=production
APP_URL=https://fichaje.tuhotel.local
KIOSK_VLAN_CIDR=10.0.20.0/24
PORTAL_INTERNAL_CIDR=10.0.10.0/24
METRICS_ALLOW_CIDR=172.29.0.20/32
TLS_ALLOW_SELF_SIGNED=false
BACKUP_PATH=/var/backups/fichaje
IMAGE_REGISTRY=ghcr.io/kronoqr
```

**Los cuatro valores de red y `APP_URL` no pueden quedarse como vienen.** El
instalador **compara con la plantilla** y se niega a instalar si `APP_URL`,
`KIOSK_VLAN_CIDR`, `PORTAL_INTERNAL_CIDR` o `METRICS_ALLOW_CIDR` siguen con el
valor de ejemplo. El motivo es concreto: con `APP_URL=https://localhost` el
sistema arranca, todas las comprobaciones pasan —la verificación final sondea
`127.0.0.1`— y **ningún quiosco puede llegar a él**. Nada posterior detecta eso.

**`TLS_ALLOW_SELF_SIGNED=false` en producción, y el instalador lo exige.** Con
`true`, el servidor web se genera un certificado autofirmado: las tablets
avisarían de sitio no seguro cada mañana y alguien acabaría desactivando la
comprobación de certificado en ellas. Desde ese día, el canal por el que viajan
los fichajes no lo protege nadie.

Esto **no escribe absolutamente nada**. Sirve para reservar la ventana de
mantenimiento sabiendo que va a salir bien. Salida esperada:

```
Fase 1 de 5 — comprobando requisitos. Todavia no se escribe nada.
  [ok]    Fichero de compose /opt/kronoqr-2.0.0/docker-compose.yml
  [ok]    Version que se instala: 2.0.0
  [ok]    Plantilla de configuracion /opt/kronoqr-2.0.0/.env
  [ok]    Permiso para hablar con Docker
  [ok]    Docker 27.3.1 (se exige 24 o superior)
  [ok]    Docker Compose v2 (2.29.7)
  [ok]    openssl disponible para generar los secretos
  [ok]    curl disponible para verificar la instalacion
  [ok]    CPU: 4 nucleos (minimo publicado: 2)
  [ok]    Memoria: 7936 MiB (minimo publicado: 3700 MiB)
  [ok]    Disco libre en /var/lib/docker: 92 GiB (minimo publicado: 40 GiB)
  [ok]    APP_URL relleno en la plantilla
  ...
  [ok]    Certificado TLS en /opt/kronoqr-2.0.0/certs
  [ok]    Puerto 80 libre
  [ok]    Puerto 443 libre
  [ok]    Se puede escribir en /var/backups/fichaje

Requisitos cumplidos: 25 comprobaciones, 0 avisos.

Solo comprobacion (--check-only): no se ha tocado nada. Vuelve a ejecutar sin la opcion para instalar.
```

Si algo sale en `[FALLA]`, debajo tienes una línea **«Que hacer»** con la orden
concreta. Los avisos (`[aviso]`) no impiden instalar.

**`APP_TIMEZONE=UTC` no se toca nunca.** Las horas se guardan siempre en UTC y
se muestran en la zona horaria de cada centro, que se configura después, en el
panel. Cambiar esta variable invalida el cálculo de la jornada.

**`APP_DEBUG=false` no se toca nunca.** Con `true`, cualquier error muestra las
contraseñas de la instalación a quien lo provoque. La aplicación **se niega a
arrancar** con `APP_ENV=production` y `APP_DEBUG=true`, y dice cómo corregirlo.

### 1.3 Comprueba los requisitos sin tocar nada

```bash
sudo ./install.sh --check-only
```

Esto **no escribe absolutamente nada**. Sirve para reservar la ventana de
mantenimiento sabiendo que va a salir bien.

> **Con `sudo`, y no es un detalle.** El instalador tiene que asignar el
> propietario del directorio donde PostgreSQL archiva su registro de escritura
> (`BACKUP_PATH/wal`), y eso exige root. **Pertenecer al grupo `docker` no
> basta**: sirve para hablar con Docker, no para asignar propietarios. La
> fase 1 lo comprueba y te lo dice antes de escribir nada.

Salida esperada:

```
Fase 1 de 5 — comprobando requisitos. Todavia no se escribe nada.
  [ok]    Fichero de compose /opt/kronoqr-2.0.0/docker-compose.yml
  [ok]    Version que se instala: 2.0.0
  [ok]    Plantilla de configuracion /opt/kronoqr-2.0.0/.env
  [ok]    Permiso para hablar con Docker
  [ok]    Docker 27.3.1 (se exige 24 o superior)
  [ok]    Docker Compose v2 (2.29.7)
  [ok]    openssl disponible para generar los secretos
  [ok]    curl disponible para verificar la instalacion
  [ok]    CPU: 4 nucleos (minimo publicado: 2)
  [ok]    Memoria: 7936 MiB (minimo publicado: 3700 MiB)
  [ok]    Disco libre en /var/lib/docker: 92 GiB (minimo publicado: 40 GiB)
  [ok]    APP_URL relleno en la plantilla
  [ok]    KIOSK_VLAN_CIDR relleno en la plantilla
  [ok]    PORTAL_INTERNAL_CIDR relleno en la plantilla
  [ok]    METRICS_ALLOW_CIDR relleno en la plantilla
  [ok]    BACKUP_PATH relleno en la plantilla
  [ok]    TLS_CERT_DIR relleno en la plantilla
  [ok]    APP_ENV=production
  [ok]    APP_DEBUG=false
  [ok]    APP_URL: https://fichaje.tuhotel.local
  [ok]    El nombre fichaje.tuhotel.local resuelve desde este servidor
  [ok]    Certificado TLS en /opt/kronoqr-2.0.0/certs
  [ok]    El borde (uid 101) puede leer tls.crt
  [ok]    El borde (uid 101) puede leer tls.key
  [ok]    Puerto 80 libre
  [ok]    Puerto 443 libre
  [ok]    Se puede escribir en /var/backups/fichaje
  [ok]    Se puede escribir en /opt/kronoqr-2.0.0
  [ok]    Privilegios para asignar el propietario del archivo de WAL

Requisitos cumplidos: 29 comprobaciones, 0 avisos.

Solo comprobacion (--check-only): no se ha tocado nada. Vuelve a ejecutar sin la opcion para instalar.
```

Si algo sale en `[FALLA]`, debajo tienes una línea **«Que hacer»** con la orden
concreta. Los avisos (`[aviso]`) no impiden instalar.

**En inglés**, con `--lang en` o con la configuración regional del servidor.
Todos los mensajes del instalador están en los dos idiomas.

### 1.4 Instala

```bash
sudo ./install.sh
```

Tarda entre tres y quince minutos, según lo que tarde en descargar las
imágenes. Verás las cinco fases. Al terminar:

```
KronoQR 2.0.0 instalado y verificado.

  Panel de gestion:    https://fichaje.tuhotel.local/admin/
  Quiosco (tablet):    https://fichaje.tuhotel.local/kiosk/
  Portal del empleado: https://fichaje.tuhotel.local/portal/  (solo desde PORTAL_INTERNAL_CIDR)

SIGUIENTE PASO: abre el panel de gestion. La primera vez te guia un asistente
que crea la organizacion, el centro, el primer administrador y el primer
quiosco. Hasta que lo termines no hay ninguna cuenta: el instalador no crea
usuarios.

DOCUMENTACION, en /opt/kronoqr-2.0.0/docs
  ...

ANTES DE CERRAR LA SESION: custodia BACKUP_ENCRYPTION_KEY fuera de este
servidor (docs/cliente/operacion.md, «Custodia de secretos»).
```

### 1.5 Comprueba tú mismo que responde

```bash
curl -fsS https://fichaje.tuhotel.local/api/v1/health
curl -fsS https://fichaje.tuhotel.local/api/v1/ready
```

Las dos tienen que devolver `200`. **Hazlo desde un quiosco, no solo desde el
servidor**: es la única forma de comprobar que el nombre del certificado
resuelve donde tiene que resolver.

### 1.6 Custodia la clave de las copias

**Ahora, antes de cerrar la sesión.** Procedimiento en
[`operacion.md`](operacion.md), sección «Custodia de secretos». Si pierdes esa
clave y pierdes el servidor, has perdido el registro horario, y el registro
horario hay que conservarlo cuatro años por ley.

### 1.7 Abre el panel y termina el asistente

`https://fichaje.tuhotel.local/admin/`.

Si la instalación está recién hecha, el panel te lleva solo al **asistente de
puesta en marcha**. No hay nada que buscar en ningún menú.

> **No hay ningún usuario y es lo correcto.** El instalador **no crea cuentas**:
> una contraseña generada por un script acaba en el historial del shell o en un
> fichero de despliegue, y ahí se queda. La primera cuenta la creas tú, aquí, y
> es la única vez que el sistema deja crear una sin estar dentro.
>
> ---
>
> ⚠️ **Haz el paso 1 ahora, antes que nada, y no publiques el panel hasta
> haberlo hecho.**
>
> La pantalla de «primer administrador» es la **única página de todo el producto
> que escribe sin pedir credenciales**, y tiene que serlo: en ese momento no
> existe ninguna cuenta con la que autenticarse. Se cierra **sola y para
> siempre** en cuanto hay una cuenta de gestión — pero **la crea quien llegue
> primero**, no quien tenga derecho.
>
> En la práctica eso significa dos cosas:
>
> 1. **No abras el puerto al exterior ni le pongas nombre DNS** hasta que el paso
>    1 esté terminado. Entra desde la red interna del hotel o por túnel SSH. Si
>    la instalación tiene que ser accesible desde fuera, hazlo **después**.
> 2. **Termina el paso 1 en la misma sesión** en la que instalas. No es un paso
>    que se deje para mañana: entre hoy y mañana la puerta sigue abierta.
>
> **Si al abrir el panel te encuentras un error 409 que dice que ya existe una
> cuenta de gestión y tú no has creado ninguna, para.** No es un fallo de la
> instalación: alguien se te ha adelantado. Trátalo como incidente de seguridad,
> avisa a quien corresponda y reinstala con el panel cerrado al exterior; el
> apartado «qué hacer si…» de esta guía lo explica paso a paso.

**Ten a mano antes de empezar**, porque el asistente te lo va a pedir:

- El **nombre del hotel** y su **zona horaria**.
- Los **departamentos** que quieras usar (recepción, pisos, cocina, sala…).
- Un **teléfono con una aplicación de autenticación** (Google Authenticator,
  Microsoft Authenticator, Aegis, 1Password… cualquiera que lea códigos TOTP).
- La **clave de licencia**, si ya la tienes. Si no, se omite: **no hace falta
  para fichar**.
- El **fichero de plantilla**, si vas a cargarla desde CSV o Excel.

#### Los ocho pasos

| # | Paso | ¿Obligatorio? | Qué hace |
| --- | --- | --- | --- |
| 1 | **Primer administrador** | Sí | Crea tu cuenta y activa su segundo factor. |
| 2 | **Datos de la organización** | Sí | Nombre visible del sistema e idiomas. |
| 3 | **Centro y zona horaria** | Sí | El hotel. **La zona horaria decide a qué día va cada turno.** |
| 4 | **Departamentos** | Se puede omitir | Los que uses. Se pueden crear después. |
| 5 | **Perfil de convenio** | Sí | Los umbrales legales, a la vista, para que los contrastes. |
| 6 | **Carga de plantilla** | Se puede omitir | Desde CSV o Excel, con comprobación previa. |
| 7 | **Licencia** | Se puede omitir | Activa la clave si la tienes. |
| 8 | **Primer quiosco** | Se puede omitir | Vincula la primera tablet con un código. |

**Se puede abandonar y retomar.** Lo hecho queda guardado: si te falta un dato o
la tablet llega mañana, cierras el navegador y vuelves cuando puedas. Ningún paso
deja el sistema en un estado del que solo se salga con una consola.

#### Paso 1 — tu cuenta, con segundo factor

Es el primer paso y no el último a propósito: **todo lo que configures después
queda registrado con tu nombre**, y sin una cuenta detrás esos registros dirían
«el sistema», que no responde a nada.

1. Escribe tu nombre, tu correo y una contraseña. La contraseña necesita **al
   menos 12 caracteres, con mayúsculas, minúsculas, números y símbolos**.
2. La pantalla siguiente enseña un **código QR y un texto**. Escanéalo con tu
   aplicación de autenticación.
3. Escribe el código de seis dígitos que te muestre el teléfono.

> **El código QR se enseña una sola vez.** No hay forma de volver a verlo, y es a
> propósito. Si cierras la pantalla antes de escanearlo, **no has perdido la
> cuenta**: entra por la pantalla de acceso normal con tu correo y tu contraseña
> y te lo volverá a ofrecer. Si además pierdes la contraseña, hay salida por
> consola — está en «qué hacer si…».
>
> **El segundo factor es obligatorio y no se puede desactivar** para las cuentas
> con acceso a toda la plantilla. Es la única credencial que protege el registro
> horario de todo el hotel.

#### Paso 3 — la zona horaria no es un detalle de presentación

Es el dato con el que el sistema decide **a qué día pertenece cada turno**. Un
turno de 22:00 a 06:00 cuenta entero en el día en que empezó, y «el día» se mide
en la zona del centro.

Ponla bien a la primera. Se puede cambiar después —queda registrado— pero
**cambiarla no reescribe las jornadas ya calculadas**: a partir de ese momento se
calculan con la nueva y antes se calcularon con la anterior.

Si el hotel está en Canarias, es `Atlantic/Canary`, no `Europe/Madrid`.

#### Paso 5 — el perfil de convenio: léelo, no lo pases

El asistente propone el perfil **`ES-hosteleria`** con estos valores, tomados del
Estatuto de los Trabajadores:

| Umbral | De serie |
| --- | --- |
| Descanso mínimo entre jornadas | 12 h |
| Jornada diaria ordinaria máxima | 9 h |
| Jornada semanal máxima | 40 h |
| Tramo continuo antes de exigir pausa | 6 h |
| Años de conservación del registro | 4 |

**Este paso no se puede omitir**, y es el único obligatorio que no crea nada. La
razón: **tu convenio colectivo puede ser más estricto que la ley**, y estos son
los números con los que el sistema va a avisar de incumplimientos. Contrástalos
con el convenio que os aplica y confírmalos, aunque los dejes tal cual.

Se cambian después en Configuración › Cumplimiento, y cada cambio queda
registrado.

#### Paso 7 — la licencia se puede omitir, y a propósito

Sin licencia el sistema **ficha, guarda, calcula y exporta para la Inspección de
Trabajo exactamente igual**. Lo que no tendrás son los informes por periodo y
algunas funciones accesorias, con un aviso que dice cuáles.

Un asistente que exigiera la clave para terminar convertiría la licencia en un
requisito para cumplir la ley, y eso no puede ser. Actívala cuando la tengas,
desde Configuración › Licencia.

#### Paso 8 — el primer quiosco

La tablet muestra un código y tú lo escribes en el panel. Si aún no ha llegado,
**omite el paso**: el procedimiento completo para vincular una tablet está en el
runbook `alta-nuevo-quiosco.md`, que viene en el paquete.

#### Y al terminar: las tarjetas

La última pantalla es un resumen con **lo que queda por hacer**. La cifra que
importa es **«tarjetas pendientes»**.

**Sin tarjeta impresa y entregada, esa persona no puede fichar.** Emitirlas,
imprimirlas y repartirlas lleva días, así que empieza en cuanto termines el
asistente:

```bash
docker compose exec app php artisan credentials:status --pending
```

> El asistente **no vuelve a aparecer**: es de un solo uso. Todo lo que configuró
> se cambia después desde el panel, y allí cada cambio queda registrado con su
> autor y su fecha.

---

## 2. Códigos de salida del instalador: qué hacer con cada uno

Los cinco scripts de operación (`install.sh`, `update.sh`, `doctor.sh`,
`backup.sh`, `restore.sh`) usan **la misma tabla**. Sirve para escribir un cron
o un runbook sin leerse cada script.

| Código | Significa | Qué hacer |
| --- | --- | --- |
| `0` | Correcto. | Nada. Sigue en el paso 1.5. |
| `1` | **Uso incorrecto.** Un argumento que no existe. Nada tocado. | `./install.sh --help`. |
| `2` | **Requisitos no cumplidos. NADA escrito.** El servidor está exactamente como estaba. | Lee la línea «Que hacer» de cada `[FALLA]`, corrígelo y vuelve a ejecutar. |
| `3` | **Hay una instalación previa. NADA escrito.** | El instalador **no reinstala encima**: destruiría el registro horario. Para actualizar, `./update.sh` (ver [`../runbooks/actualizacion-cliente.md`](../runbooks/actualizacion-cliente.md)). Para ver cómo está, `./doctor.sh`. |
| `4` | **Falló y deshizo todo lo que había hecho.** El servidor vuelve a estar como antes. | El mensaje dice la causa. Corrígela y vuelve a ejecutar el instalador: es seguro. |
| `5` | **Falló y NO pudo deshacerlo todo.** Requiere intervención. | El mensaje enumera **exactamente qué ha quedado** y qué orden lo retira. Hazlo y vuelve a ejecutar. Es el único código que exige a alguien delante. |
| `6` | **Instalado, pero la verificación final no pasó.** Los servicios están en pie y **no se ha deshecho nada**. | Casi siempre es el certificado o el nombre del servidor. Revisa `docker compose logs nginx app` y el punto «no responde» de §5. Los datos están a salvo. |

---

## 3. Qué genera el instalador, y qué pasa si lo pierdes

Todos los secretos se generan **en tu servidor** con `openssl` y **no se
transmiten a nadie**. El fabricante no los conoce y no puede recuperarlos.

| Secreto | Para qué | Si lo pierdes |
| --- | --- | --- |
| `APP_KEY` | Cifra sesiones y datos cifrados | Las sesiones y esos datos dejan de poder leerse |
| `QR_SIGNING_KEY_CURRENT` (+ su `_ID`) | Firma los códigos QR de las tarjetas | **Hay que reimprimir todas las tarjetas** |
| `DB_PASSWORD` | Rol de la aplicación en PostgreSQL | Se rota; ver `../runbooks/rotacion-secretos.md` |
| `DB_MIGRATION_PASSWORD` | Rol de migración y de copias | Íd. |
| `REVERB_APP_ID` / `_KEY` / `_SECRET` | Presencia en vivo del panel | Se rotan; solo afecta al tiempo real |
| `BACKUP_ENCRYPTION_KEY` | Cifra las copias de seguridad | **Las copias dejan de poder restaurarse.** Custódiala fuera del servidor |
| `IDENTITY_PIN_SEALING_SECRET_KEY` | Abre los PIN que el quiosco sella sin red | Los fichajes por PIN encolados sin red no se podrían abrir |
| `GRAFANA_ADMIN_PASSWORD` | Acceso al cuadro de mandos | Se rota en Grafana |

El fichero `.env` queda con permisos `0600`. **Ningún secreto se imprime por
pantalla ni queda en el log del instalador**, y eso se comprueba en cada
publicación de versión.

**Un rol de base de datos NO recibe contraseña**: `fichaje_maintenance`, el
único que puede soltar particiones vencidas del registro de auditoría. Nace sin
credencial a propósito, y se le asigna una **en el momento** de la purga anual.
El procedimiento está en [`operacion.md`](operacion.md).

**La clave de licencia no es un secreto** y no está en esa tabla: es una
afirmación firmada sobre lo que has contratado, no abre nada, y perderla no
tiene consecuencia. Se pide otra al proveedor.

---

## 4. La licencia, al instalar

Pega la clave que te entregó tu proveedor en `LICENSE_KEY` del `.env` **antes**
de ejecutar el instalador, o actívala después con:

```bash
docker compose exec app php artisan license:activate "KQL1...."
```

> **Si no la tienes a mano, instala igualmente.** Sin licencia activada el
> sistema se instala, arranca y **registra jornada con normalidad**: lo único
> que no estará disponible son funcionalidades accesorias —informes por periodo
> y actualización en tiempo real de la presencia—. La activas cuando la tengas y
> aparecen solas, sin reiniciar nada.
>
> **Y una licencia caducada tampoco bloquea nunca el fichaje ni el acceso al
> registro.** Eso te dejaría incumpliendo la ley por una acción nuestra.

Comprueba en cualquier momento cómo está con
`docker compose exec app php artisan license:show`. Todo lo demás sobre la
licencia está en [`configuracion.md`](configuracion.md), sección 3 bis.

---

## 5. Qué hacer si…

### …`install.sh` dice «Permiso para hablar con Docker: FALLA»

Ejecútalo con `sudo`, o añade tu usuario al grupo `docker` y vuelve a entrar en
la sesión:

```bash
sudo usermod -aG docker "$USER"
# cierra la sesión y vuelve a entrar
```

### …dice que el puerto 80 o el 443 están ocupados

Algo más escucha ahí (casi siempre un Apache o un Nginx del sistema):

```bash
sudo ss -lptn 'sport = :443'
sudo systemctl stop nginx      # o lo que aparezca
```

Si necesitas conservar ese servicio, publica KronoQR en otros puertos con
`HTTP_PORT` y `HTTPS_PORT` en el `.env`, y ponlo detrás de tu proxy.

### …dice «no se han podido descargar las imagenes»

El servidor no llega al registro del fabricante, o no has iniciado sesión:

```bash
docker login ghcr.io/kronoqr
```

Si esta instalación no tiene salida a internet, ve a §7.

### …dice «Se ha encontrado una instalacion previa» y sale con `3`

Es correcto y es deliberado: el instalador no se instala encima de un registro
horario. Si querías **actualizar**, usa `./update.sh`. Si de verdad quieres
empezar de cero, retira la instalación anterior a conciencia —**con copia de
seguridad primero**— y vuelve a ejecutar.

### …dice «El borde (uid 101) NO puede leer tls.key»

El servidor web corre **sin privilegios** dentro de su contenedor y no puede
abrir un fichero de `root`. Es lo que pasa si copiaste la clave como `root`:
`openssl` las escribe `0600` y `cp` conserva el modo.

```bash
sudo chown 101:101 certs/tls.crt certs/tls.key
sudo chmod 0444 certs/tls.crt
sudo chmod 0400 certs/tls.key
```

**Si ese directorio lo comparte otro servicio del hotel**, no le cambies el
propietario: copia el certificado a un directorio propio, apunta ahí
`TLS_CERT_DIR` y aplica allí las órdenes.

El instalador lo detecta en la **fase 1**, antes de escribir nada, así que no
hay nada que limpiar: corrígelo y vuelve a ejecutar.

### …nginx reinicia una y otra vez con «Permission denied»

Es el mismo problema del punto anterior en una instalación que ya existe —por
ejemplo, después de renovar el certificado con `certbot`, que lo reescribe con
su propio propietario—. Compruébalo y corrígelo:

```bash
ls -l certs/                       # tls.key tiene que ser legible por el uid 101
docker compose logs --tail 20 nginx
sudo chown 101:101 certs/tls.crt certs/tls.key
sudo chmod 0400 certs/tls.key
docker compose up -d nginx
```

### …el certificado no lo acepta el navegador de la tablet

El nombre del certificado tiene que ser **el mismo** que el de `APP_URL`, y la
cadena completa (certificado + intermedios) tiene que estar en `certs/tls.crt`.
Un autofirmado hace que las tablets avisen cada mañana hasta que alguien
desactive la comprobación, y ese día el quiosco deja de ser fiable.

### …instalé bien pero `/api/v1/ready` devuelve 503

`ready` dice «puedo atender tráfico», y para eso comprueba PostgreSQL y Redis:

```bash
docker compose ps
docker compose logs --tail 50 postgres redis
```

`health`, en cambio, solo dice «el proceso vive» y no toca nada: si `health`
responde y `ready` no, el problema es una dependencia, no la aplicación.

### …el portal del empleado devuelve 403 desde mi ordenador

**Es lo correcto** si tu ordenador está fuera de `PORTAL_INTERNAL_CIDR`. El
portal se abre con código de empleado y PIN de 6 dígitos, y una de las
protecciones es que no sea alcanzable desde cualquier IP. Ver §6.

### …no encuentro dónde iniciar sesión: no hay ningún usuario

Es lo esperado. **El instalador no crea usuarios.** Abre
`https://tu-servidor/admin/` y el asistente de puesta en marcha crea el primer
administrador, con su alta registrada en la auditoría (sección 1.7).

### …cerré la pantalla del código QR antes de escanearlo

**No has perdido la cuenta.** Está creada, solo le falta el segundo factor. Entra
por la pantalla de acceso normal con tu correo y tu contraseña: como todavía no
lo tienes activado, la respuesta te ofrecerá darlo de alta y te enseñará el
código otra vez.

Lo que **no** funciona es volver a crear el primer administrador: esa puerta se
cierra en cuanto existe una cuenta de gestión, y no se reabre ni siquiera si
desactivas esa cuenta. Es deliberado — si se reabriera, dar de baja a una persona
sería una forma de crear un administrador nuevo sin credenciales.

Si además has perdido la contraseña:

```bash
# Crea otra cuenta de gestión (pide la contraseña por consola, sin eco)
docker compose exec app php artisan identity:create-user --role=admin

# O retira el segundo factor de la cuenta que ya existe, para volver a darlo de alta
docker compose exec app php artisan identity:2fa-reset
```

### …el asistente no aparece y el panel me pide credenciales

La puesta en marcha ya se completó. Es de un solo uso y no se reabre: todo lo que
configuró —el centro, los departamentos, el perfil de convenio, la licencia— se
cambia después desde el panel, y allí cada cambio queda registrado con su autor y
su fecha, que es justo lo que un asistente reabrible no podría garantizar.

Para comprobarlo sin entrar:

```bash
curl -sS https://TU-SERVIDOR/api/v1/setup/status
# {"available":false,"completed_at":"2026-09-02T09:14:00Z"}
```

Esta consulta **no necesita credenciales y por eso no dice nada más**: solo si el
asistente sigue abierto y cuándo se cerró. El detalle de los pasos está en el
panel, con sesión iniciada.

### …el panel dice que ya hay una cuenta de gestión y yo no he creado ninguna

**Para y trátalo como incidente de seguridad.** No es un fallo de la instalación.

La pantalla de «primer administrador» escribe sin pedir credenciales —tiene que
hacerlo: en ese momento no existe ninguna cuenta— y se cierra sola en cuanto hay
una. Si tú no la has creado, **la ha creado otro**, y esa cuenta es hoy la
administradora de la instalación.

Qué hacer, en este orden:

1. **Corta el acceso al panel desde fuera** (regla de cortafuegos o del proxy
   de entrada). El fichaje del quiosco no depende del panel y sigue funcionando.
2. **Mira cuándo y desde dónde.** El alta de la cuenta y la activación de su
   segundo factor quedan registradas en la auditoría, que es solo-añadir y no se
   puede reescribir:

   ```bash
   cd /opt/kronoqr-2.0.0
   sudo docker compose --env-file .env -f docker-compose.yml \
     exec -T postgres psql -U fichaje_migrator -d fichaje -c \
     "SELECT occurred_at, action, ip, payload
        FROM audit_log
       WHERE action IN ('role_assignment.changed','auth.two_factor_enabled')
       ORDER BY occurred_at
       LIMIT 10"
   ```

   El primer asiento sale **sin actor**: es correcto y es la firma de esta
   pantalla —no había ninguna sesión detrás—. Lo que te interesa es la **hora** y
   la **IP**: si no son las tuyas, es la confirmación.

3. **Avisa al responsable** del hotel y a soporte, con esa salida.
4. **Reinstala desde cero** si la instalación es nueva y no hay datos que
   conservar (ver el apartado siguiente), esta vez **con el panel cerrado al
   exterior** hasta terminar el paso 1.

Prevenirlo es la advertencia de la sección 1.7: el paso 1 se hace
inmediatamente después de instalar, y el panel no se publica antes.

### …el asistente no me deja terminar

Dice qué falta, con el nombre del paso. Los pasos **obligatorios** hay que
completarlos; los **omitibles** —departamentos, plantilla, licencia y quiosco—
basta con omitirlos explícitamente, y esa decisión queda guardada.

El que más se atasca es el **perfil de convenio**: no se puede omitir, hay que
confirmarlo aunque lo dejes como viene. La razón está en la sección 1.7.

### …quiero volver a empezar la instalación desde cero

Solo si estás seguro de que **no hay datos que conservar**:

```bash
cd /opt/kronoqr-2.0.0
sudo docker compose --env-file .env -f docker-compose.yml down -v --remove-orphans
sudo rm -f .env
sudo rm -rf /var/backups/fichaje
cp .env.example .env    # y vuelve a rellenar lo marcado [CLIENTE]
```

`down -v` **borra los volúmenes, y con ellos el registro horario.** El
instalador no hace esto por ti, y es a propósito.

---

## 6. Los parámetros de red, en detalle

### `KIOSK_VLAN_CIDR` — rango de la VLAN de quioscos

```dotenv
KIOSK_VLAN_CIDR=10.0.20.0/24
```

**Qué hace.** El servidor web limita el número de fichajes por minuto y por IP.
Para el tráfico que llega desde este rango, el límite es **600 por minuto con
ráfaga de 50**. Para cualquier otro origen, **30 por minuto con ráfaga de 10**.

**Por qué son dos límites y no uno.** Los 30 r/m por IP son un control pensado
para internet. **Todos los quioscos de un hotel salen por la misma IP**, así
que aplicado sin distinguir el origen frenaría el fichaje muy por debajo de lo
que el producto necesita en el cambio de turno.

**Qué pasa si se configura mal.** Si los quioscos quedan fuera de este rango,
caen bajo el límite pensado para internet. **No aparece ningún error**: el
síntoma es *«el quiosco va lento a las 06:00»*, justo en el momento en que 500
personas fichan a la vez. Si alguien describe ese síntoma, esta variable es lo
primero que hay que comprobar.

**Cómo comprobarlo.** Desde un quiosco ya instalado:

```bash
# La IP que el quiosco presenta al servidor debe caer dentro de KIOSK_VLAN_CIDR
ip -4 addr show | grep inet
```

**El límite interno se eleva, no se elimina.** El producto limita también por
IP dentro de la VLAN: un equipo comprometido enchufado a esa red no puede
quedar sin techo.

### `METRICS_ALLOW_CIDR` — quién puede leer las métricas

```dotenv
METRICS_ALLOW_CIDR=172.29.0.20/32
```

`/metrics` expone el estado interno del sistema y **solo se sirve a este
origen**, que es el del servicio que recoge las métricas. Cualquier otro
recibe `403`, incluido el propio servidor. No se expone a internet, y el cuadro
de mandos (Grafana) tampoco: escucha únicamente en `127.0.0.1`.

Conviene que sea una dirección concreta y no una red entera: si se autoriza la
red completa de contenedores, las peticiones hechas desde el propio servidor
entran dentro de ese rango y `/metrics` queda accesible sin que nada lo avise.

### `PORTAL_INTERNAL_CIDR` — desde dónde se puede entrar al portal del empleado

```dotenv
PORTAL_INTERNAL_CIDR=172.28.0.0/16
```

**Qué hace.** El portal del empleado (código de empleado + PIN de 6 dígitos)
solo responde a las peticiones que llegan desde este rango. Cualquier otro
origen recibe `403` en el propio servidor web, antes de llegar a la
aplicación.

**Por qué existe.** Un PIN de 6 dígitos es un espacio pequeño. Restringir el
portal a la red interna es uno de los cuatro controles que lo compensan, junto
con el bloqueo por intentos, el límite de peticiones por IP y que la sesión
del portal solo pueda leer los datos del propio empleado.

**El valor de ejemplo es de desarrollo**, no de producción: cubre la red
interna de Docker Compose. Antes de desplegar, cámbialo por la LAN real del
hotel o por el rango de la VPN corporativa que use la plantilla para entrar
desde fuera.

**Exponer el portal a internet es una decisión explícita**, nunca un valor por
defecto. Se toma poniendo `PORTAL_INTERNAL_CIDR=0.0.0.0/0` y debe quedar
anotada en el acta de entrega de la instalación: es lo que responde el día que
alguien pregunte por qué el portal es alcanzable desde fuera del hotel.

### Certificado TLS

```dotenv
TLS_ALLOW_SELF_SIGNED=false
TLS_CERT_DIR=./certs
```

Coloca el certificado del cliente —o el de Let's Encrypt— como `tls.crt` y
`tls.key` dentro de `TLS_CERT_DIR`. **Si falta, el servidor web no arranca y
dice qué hacer.** Es intencionado: un certificado autofirmado haría que los
quioscos avisaran de sitio no seguro cada mañana, y alguien acabaría
desactivando la comprobación.

`TLS_ALLOW_SELF_SIGNED=true` es exclusivo de entornos de prueba. En producción
el instalador **se niega a continuar** si lo encuentra a `true`.

**Y el propietario importa tanto como el contenido.** El borde HTTP corre sin
privilegios, con el uid `101`, y tiene que poder **leer** los dos ficheros:

```bash
sudo chown 101:101 "$TLS_CERT_DIR"/tls.crt "$TLS_CERT_DIR"/tls.key
sudo chmod 0444 "$TLS_CERT_DIR"/tls.crt
sudo chmod 0400 "$TLS_CERT_DIR"/tls.key
```

La fase 1 del instalador lo comprueba y no escribe nada si falla. Si
`TLS_CERT_DIR` apunta a un directorio compartido con otro servicio (el de
Let's Encrypt, por ejemplo), **no le cambies el propietario**: copia el
certificado a un directorio propio y apunta ahí `TLS_CERT_DIR`. Eso también
evita que una renovación automática vuelva a dejarlo con el propietario de
antes.

**Al renovar el certificado**, repite esas órdenes: `certbot` y equivalentes
reescriben los ficheros con su propio propietario, y el borde deja de poder
leerlos en el siguiente reinicio.

### `BACKUP_PATH` — dónde se guardan las copias

```dotenv
BACKUP_PATH=/var/backups/fichaje
BACKUP_RETENTION_DAYS=30
BACKUP_WAL_RETENTION_DAYS=8
```

**Qué hace.** Es el destino de las copias cifradas, del WAL archivado y de los
informes de restauración. **Se monta en la misma ruta dentro de los
contenedores**, así que el valor sirve dentro y fuera.

**Las copias no salen de aquí.** Viven en la infraestructura del cliente; el
fabricante no las recibe ni las custodia (RL-14). Si el destino es un recurso de
red (NAS, cabina), **tiene que estar montado antes de levantar los servicios**:
si no lo está, PostgreSQL no puede archivar el WAL y acaba llenando su propio
disco.

**Permisos que hay que dejar puestos** (los deja el instalador; conviene
comprobarlos tras mover el destino):

```bash
# El árbol de copias lo escribe la aplicación, que corre como uid 1000
sudo install -d -o 1000 -g 1000 -m 0750 /var/backups/fichaje
# El archivo de WAL lo escribe PostgreSQL, que corre como su propio usuario
sudo install -d -o 70 -g 70 -m 0750 /var/backups/fichaje/wal
```

**Cuánto ocupa.** Aproximadamente: el tamaño de la base comprimido, por
`BACKUP_RETENTION_DAYS`, más una copia física semanal, más el WAL de
`BACKUP_WAL_RETENTION_DAYS` días. El registro horario se conserva **4 años** por
obligación legal: el almacenamiento tiene que dar para eso.

**`BACKUP_WAL_RETENTION_DAYS` debe ser mayor que el intervalo entre copias
físicas** (semanal por defecto). Sin la copia física anterior, el WAL archivado
no reconstruye nada y la pérdida máxima deja de ser de 15 minutos.

**Cómo comprobar que funciona:**

```bash
docker compose exec app php artisan backup:run    # crea y verifica una copia
docker compose exec app php artisan backup:verify # verifica la última
bash ./restore-drill.sh                            # simulacro trimestral
```

El procedimiento completo de recuperación —y el simulacro que hay que ejecutar
cada trimestre— está en
[`docs/runbooks/restaurar-backup.md`](../runbooks/restaurar-backup.md).

---

## 7. Instalar sin salida a internet

El sistema funciona íntegramente sin internet. Lo único que hay que resolver es
cómo llegan las imágenes al servidor. Desde una máquina que sí tenga acceso:

```bash
version="$(cat VERSION)"
for imagen in php nginx postgres; do
  docker pull "ghcr.io/kronoqr/${imagen}:${version}"
done
docker pull redis:7-alpine

docker save -o "imagenes-${version}.tar" \
  "ghcr.io/kronoqr/php:${version}" \
  "ghcr.io/kronoqr/nginx:${version}" \
  "ghcr.io/kronoqr/postgres:${version}" \
  redis:7-alpine
```

Copia ese fichero al servidor del hotel (USB, recurso interno) y allí:

```bash
docker load -i imagenes-2.0.0.tar
sudo ./install.sh
```

**El instalador solo descarga lo que falta**, imagen por imagen: si ya están
cargadas, no intenta hablar con ningún registro y no se queda esperando.

Si además vas a usar la observabilidad (encendida de serie), añade al `docker
save` las cinco imágenes públicas del perfil: `prom/prometheus`,
`prom/node-exporter`, `prom/alertmanager`, `grafana/grafana` y `grafana/loki`.
Sus versiones exactas están en `docker-compose.yml`. Si prefieres no hacerlo,
apaga el perfil dejando `COMPOSE_PROFILES=` vacío en el `.env` y lee en
[`operacion.md`](operacion.md) qué avisos pierdes.

---

## 8. La observabilidad: encendida de serie, y por qué conviene dejarla

El `.env` trae `COMPOSE_PROFILES=observability`, que levanta cinco servicios
más (Prometheus, node-exporter, Alertmanager, Grafana y Loki) y ocupa unos
700 MiB de RAM.

**Lo que hacen es avisar de las dos cosas que convierten una instalación sana
en una pérdida de datos sin que nadie lo note mirando la pantalla:** que la
copia de anoche falló y que el archivado del registro de escritura (WAL) se ha
parado y el disco se está llenando.

Puedes apagarla dejando la variable vacía —es una configuración soportada—,
pero entonces **verificar la copia pasa a ser una tarea manual tuya**, semanal.
Está escrito en [`operacion.md`](operacion.md).

Grafana escucha **solo en `127.0.0.1:3000`**: se llega por túnel SSH o desde el
propio servidor, nunca desde internet.

---

## 9. Y después de instalar

- **[`operacion.md`](operacion.md)** — el calendario de lo que ocurre solo, lo
  que tienes que atender, las copias, la custodia de secretos y los códigos de
  salida de los cinco scripts.
- **[`configuracion.md`](configuracion.md)** — cada parámetro y qué hace.
- **[`obligaciones-legales.md`](obligaciones-legales.md)** — lo que le
  corresponde al hotel como responsable del tratamiento, y lo que no puede
  hacer el fabricante por ti.
- **`runbooks/alta-nuevo-quiosco.md`** (se entrega con la versión 2.1, junto
  al emparejamiento de quioscos por código) —
  cómo se fija una tablet en modo quiosco. **No es una funcionalidad del
  producto**: es configuración del dispositivo y la ejecutas tú. Sin ella, un
  deslizamiento accidental deja la tablet fuera de la aplicación y el siguiente
  empleado no encuentra dónde fichar.
