# Endurecimiento de una instalación de KronoQR

Anexo de [`instalacion.md`](instalacion.md). Cuando terminas de instalar, el
sistema **funciona**. Esta guía es lo que hace falta para que además **esté bien
puesto**: qué se publica y a quién, dónde vive cada secreto, qué le toca al
servidor y qué le toca a las tablets.

Se lee entera una vez, el día de la puesta en marcha, y se repasa con la lista
de comprobación del §11 cada tres meses.

---

## 0. Para quién es esto, y qué no cubre

Para el **personal de IT del hotel**, después de instalar y antes de dar la
instalación por entregada. No hace falta saber Laravel.

**El reparto, sin ambigüedad:**

| Lo endurece el fabricante | Lo endureces tú |
| --- | --- |
| Las imágenes: cada versión pasa un análisis de vulnerabilidades (Trivy) sobre las imágenes del producto y **no se publica** con hallazgos altos o críticos | El sistema operativo del servidor y su Docker: parches, usuarios, SSH |
| El borde HTTP: TLS 1.3, cabeceras de seguridad, límites de peticiones por zona, `/metrics` y el portal restringidos por rango | **La red**: qué puerto llega desde dónde, y qué no sale a internet |
| La aplicación: separación de roles de base de datos, auditoría solo-añadir, secretos generados en tu servidor, y negativa a arrancar en producción con la depuración encendida | Las **tablets**: modo quiosco, custodia física, actualizaciones de Android |
| La etiqueta de imagen fija por versión: una instalación siempre sabe decir qué versión corre | Las **cuentas**: quién tiene acceso, con qué rol, y darlas de baja a tiempo |
| Los scripts (`install.sh`, `update.sh`, `backup.sh`, `doctor.sh`): comprueban antes de tocar, no imprimen secretos y deshacen lo que hicieron si fallan | La **custodia** de los secretos que salen del servidor, y la de las copias |

**Cada control de esta guía dice qué cierra y de quién es.** Si dice
«Dueño: tú», no hay ninguna versión futura del producto que lo vaya a resolver:
no se puede resolver desde dentro de un contenedor.

**Lo que esta guía no cubre**, a propósito: cómo endurecer *tu* distribución de
Linux, *tu* gestor de dispositivos o *tu* cortafuegos concretos. Los ejemplos
son genéricos y están marcados como tales; el manual de tu herramienta manda
sobre ellos.

---

## 1. Exposición de red: qué debe llegar desde dónde

Es el apartado que más incidencias evita, y el único que hay que hacer **antes**
de dar la URL a nadie.

### 1.1 La tabla

Suponiendo la URL `https://fichaje.tuhotel.local`:

| Ruta | Quién la usa | Debe llegar desde | Quién lo aplica |
| --- | --- | --- | --- |
| `/kiosk/` | La PWA de las tablets | **Solo la VLAN de quioscos** | **Tú** (VLAN y cortafuegos) |
| `/api/v1/scan`, `/api/v1/scan/batch`, `/api/v1/scan/pin` | Las tablets, al fichar | **Solo la VLAN de quioscos** | **Tú**. El producto **eleva el límite** dentro de `KIOSK_VLAN_CIDR` (§1.4); no restringe el acceso |
| `/api/v1/kiosk/*` (emparejamiento, padrón, latido) | Las tablets | **Solo la VLAN de quioscos** | **Tú** |
| `/admin/` y la API de gestión (`/api/v1/auth/*` y el resto de `/api/v1/*`) | El panel de RRHH y de IT | **Solo la red interna o la VPN. Nunca internet** | **Tú.** El producto **no** filtra estas rutas por rango |
| `/portal/` y `/api/v1/me/*` | El portal del empleado | Solo `PORTAL_INTERNAL_CIDR`; cualquier otro origen recibe `403` en el borde | **El producto** |
| `/metrics` | El recolector de métricas | Solo `METRICS_ALLOW_CIDR`; cualquier otro origen recibe `403` | **El producto** |
| `/api/v1/health`, `/api/v1/ready`, `/healthz` | Sondas y `doctor` | Red interna. **Sin autenticar**: ver §10 | **Tú** |
| `/api/v1/branding`, `/api/v1/branding/logo` | Las tres aplicaciones, antes de identificar a nadie | Donde llegue el borde. **Sin autenticar**: ver §10 | — |
| Grafana | Tú, para mirar cuadros | Escucha **solo en `127.0.0.1:3000`**: se llega por túnel SSH | **El producto** |
| Prometheus, Alertmanager, Loki, node-exporter | El propio sistema | **No publican ningún puerto** en el servidor | **El producto** |
| PostgreSQL, Redis, Reverb | El propio sistema | **No publican ningún puerto** en el servidor | **El producto** |

Solo dos servicios publican puerto en el servidor: el borde HTTP (`HTTP_PORT` y
`HTTPS_PORT`, 80 y 443 de serie) y Grafana, atado a `127.0.0.1`. Compruébalo:

```bash
cd /opt/kronoqr-2.1.0
docker compose ps --format 'table {{.Service}}\t{{.Ports}}'
```

> **Cierra:** exposición innecesaria de la base de datos, de la cola y de la
> observabilidad. · **Dueño:** fabricante (que no se publiquen), tú
> (comprobarlo).

### 1.2 Lo que filtra el producto, y lo que tienes que filtrar tú

Dilo en voz alta antes de seguir: **el producto restringe por rango el portal
del empleado y las métricas, y nada más.** El panel, la API de gestión y el
camino del quiosco se sirven a quien alcance el puerto 443.

No es un descuido. El borde no sabe cuál es tu red y no puede adivinarla sin
que un valor por defecto demasiado abierto acabe siendo el de todos. La
segmentación la pones tú, con la VLAN y el cortafuegos, y por eso está aquí.

> **Cierra:** que el panel de gestión de un hotel acabe alcanzable desde
> internet porque nadie decidió lo contrario. · **Dueño:** tú.

### 1.3 El panel, antes del paso 1 del asistente

**No publiques el panel —ni le pongas nombre DNS, ni le abras el puerto al
exterior— hasta haber terminado el paso 1 del asistente de puesta en marcha.**
La pantalla de «primer administrador» es la única del producto que escribe sin
pedir credenciales, y **la usa quien llegue primero**. Se cierra sola y para
siempre en cuanto existe una cuenta de gestión.

El procedimiento completo, con qué hacer si te encuentras un `409` que dice que
ya hay una cuenta de gestión y tú no has creado ninguna, está en
[`instalacion.md`](instalacion.md) §1.7.

> **Cierra:** apropiación de la instalación por quien llegue antes que tú.
> · **Dueño:** tú.

### 1.4 La VLAN de quioscos: el fallo es silencioso

Las tablets van en **su propia VLAN**, separada de la red de invitados y de la
ofimática, y ese rango se declara en `KIOSK_VLAN_CIDR`. Desde ahí el borde
admite **600 fichajes por minuto**; desde cualquier otro origen, **30**, que es
un límite pensado para internet. El límite interno **se eleva, no se elimina**:
una máquina comprometida enchufada a esa VLAN sigue teniendo techo.

Si las tablets quedan fuera del rango, **no verás ningún error**: verás *«el
quiosco va lento a las 06:00»*. Los dos límites y por qué son dos están en
[`instalacion.md`](instalacion.md) §6; lo que añade esta guía es **cómo
comprobar que la configuración se corresponde con la realidad**, desde el
servidor y con las tablets ya montadas:

```bash
cd /opt/kronoqr-2.1.0
docker compose logs --tail 200 nginx | grep '"from_kiosk_vlan":0'
```

Cada línea que salga ahí con una ruta `/api/v1/scan` es un fichaje que **no**
entró por la zona rápida. Si son las de tus tablets, corrige `KIOSK_VLAN_CIDR`
en el `.env` y recarga el borde:

```bash
cd /opt/kronoqr-2.1.0
docker compose up -d nginx
```

> **Cierra:** degradación silenciosa del fichaje en el pico de turno.
> · **Dueño:** tú (la VLAN y el valor), fabricante (las dos zonas).

### 1.5 Ejemplo de cortafuegos

**Son un ejemplo, no una configuración soportada.** Los rangos son inventados:
sustitúyelos por los tuyos. Manda el manual de tu cortafuegos.

Con `ufw` (Debian/Ubuntu), suponiendo VLAN de quioscos `10.0.20.0/24`, red
ofimática `10.0.10.0/24` y VPN `10.8.0.0/24`:

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow from 10.0.10.0/24 to any port 22 proto tcp comment 'SSH solo desde ofimatica'
sudo ufw allow from 10.0.20.0/24 to any port 443 proto tcp comment 'KronoQR quioscos'
sudo ufw allow from 10.0.10.0/24 to any port 443 proto tcp comment 'KronoQR panel'
sudo ufw allow from 10.8.0.0/24 to any port 443 proto tcp comment 'KronoQR VPN'
sudo ufw enable
sudo ufw status numbered
```

Con `nftables`, el mismo criterio:

```bash
sudo mkdir -p /etc/nftables.d
sudo tee /etc/nftables.d/kronoqr.nft >/dev/null <<'REGLAS'
table inet kronoqr {
  chain input {
    type filter hook input priority 0; policy drop;
    ct state established,related accept
    iif "lo" accept
    ip saddr 10.0.10.0/24 tcp dport 22 accept
    ip saddr { 10.0.20.0/24, 10.0.10.0/24, 10.8.0.0/24 } tcp dport 443 accept
  }
}
REGLAS
sudo nft -f /etc/nftables.d/kronoqr.nft
sudo nft list table inet kronoqr
```

> **Antes de aplicar una política `drop`, asegúrate de poder volver a entrar.**
> Deja abierta una segunda sesión SSH, o ten a mano la consola de la máquina
> virtual. Y comprueba que esas reglas **se cargan al arrancar**: `nft -f` las
> aplica ahora, no las hace permanentes. En la mayoría de distribuciones eso es
> incluir el fichero desde la configuración de `nftables` y habilitar su
> servicio; el manual de la tuya lo dice.

Dos avisos que se pagan caros si se olvidan:

1. **Docker publica puertos saltándose `ufw`** en muchas distribuciones, porque
   escribe sus propias reglas. Comprueba desde **fuera** del servidor que lo que
   crees cerrado está cerrado, en vez de fiarte de la tabla de reglas:

   ```bash
   nmap -Pn -p 22,80,443,3000,5432,6379 fichaje.tuhotel.local
   ```

   Solo deben aparecer abiertos los puertos que decidiste tú.
2. **Deja el puerto 80 abierto solo si lo necesitas.** El borde lo usa para
   redirigir a HTTPS y para la renovación automática de certificados. Con
   certificado propio y sin esa renovación, ciérralo.

> **Cierra:** alcance del panel y del camino de fichaje desde redes que no
> deberían llegar. · **Dueño:** tú.

### 1.6 Si pones un proxy inverso delante

Es una configuración legítima —por ejemplo, para conservar un servicio que ya
escuchaba en el 443—, pero tiene **dos consecuencias que hay que conocer antes**:

1. **Todo el tráfico llegará al borde con la IP del proxy.** Las dos zonas de
   fichaje dejan de distinguir el origen y `KIOSK_VLAN_CIDR` deja de tener
   efecto: todo cae en la zona que corresponda a esa única IP. Lo mismo le pasa
   a `PORTAL_INTERNAL_CIDR` y a `METRICS_ALLOW_CIDR`. **No se puede corregir
   desde el `.env`.**
2. **Las tablets tienen que abrir la PWA por el mismo origen que sirve la API,
   y el proxy no puede tocar las cabeceras de seguridad.** Si quita o reescribe
   `Permissions-Policy`, la cámara del quiosco deja de concederse y el síntoma
   no apunta a su causa —está en
   [`../runbooks/alta-nuevo-quiosco.md`](../runbooks/alta-nuevo-quiosco.md) §6—.

La disposición recomendada es publicar el borde de KronoQR directamente y
segmentar con el cortafuegos.

> **Cierra:** una degradación de rendimiento y una avería de cámara que cuestan
> horas de diagnóstico. · **Dueño:** tú.

---

## 2. TLS

**Certificado propio o Let's Encrypt: los dos valen.** Lo que no vale es un
autofirmado en producción: obliga a aceptar un aviso en cada tablet cada
mañana, y el día que alguien no lo acepte, ese quiosco no ficha. Por eso
`TLS_ALLOW_SELF_SIGNED` se queda en `true` **solo en pruebas**, y en producción
va a `false`.

Dónde se coloca el certificado, qué propietario tiene que tener y qué repetir
en cada renovación está en [`instalacion.md`](instalacion.md) §6. Lo que añade
esta guía son tres cosas:

- **El nombre del certificado tiene que ser exactamente el de `APP_URL`**, y
  `tls.crt` tiene que llevar la **cadena completa** de intermedios. Con la
  cadena incompleta hay navegadores que lo aceptan y otros que no, y las
  tablets suelen estar en el segundo grupo.
- **Si tu CA es interna, instálala en el almacén de confianza de cada tablet**
  ([`../runbooks/alta-nuevo-quiosco.md`](../runbooks/alta-nuevo-quiosco.md) §2.5).
  No enseñes al personal a aceptar el aviso.
- **El borde exige TLS 1.3.** Un dispositivo que no lo soporte no conecta:
  pruébalo con una tablet antes de comprar veinte.

**La caducidad la vigila `doctor`**, con 30 días de margen, y lee el
certificado **que sirve el borde**, no el fichero del disco: así detecta también
el caso de haber renovado sin recargar, que es justo el error que deja a alguien
creyendo que ya lo arregló.

```bash
cd /opt/kronoqr-2.1.0
./doctor.sh
```

> **Cierra:** interceptación del tráfico, y quioscos que dejan de sincronizar
> sin avisar. · **Dueño:** tú (el certificado), fabricante (TLS 1.3 y el aviso
> de caducidad).

---

## 3. El anfitrión

Fuera del producto, y enteramente tuyo. Lo mínimo:

- **Un servidor dedicado a esto.** No compartas la máquina con el TPV ni con el
  servidor de ficheros.
- **Sin sesión interactiva de `root`.** Un usuario propio con `sudo`.
- **El grupo `docker` equivale a `root`.** Quien está en ese grupo puede montar
  el disco entero dentro de un contenedor. Trata su pertenencia como tratarías
  el `sudo` sin contraseña: nominal, revisada y corta.

  ```bash
  getent group docker
  ```

- **SSH con clave, nunca con contraseña**, y sin acceso directo de `root`:

  ```bash
  sudo sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
  sudo sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin no/' /etc/ssh/sshd_config
  sudo sshd -t && sudo systemctl reload ssh
  ```

  Si tu distribución llama `sshd` a ese servicio, usa `sudo systemctl reload
  sshd`. Y comprueba que **sigues pudiendo entrar** desde otra terminal **antes**
  de cerrar la sesión actual.
- **Parches del sistema y de Docker.** El fabricante parchea las imágenes del
  producto en cada versión; el anfitrión es tuyo. Actualizaciones automáticas de
  seguridad, y reinicio planificado fuera del cambio de turno.
- **Hora sincronizada por NTP.** El sistema guarda todo en UTC y la conversión
  la hace la pantalla, así que un reloj desviado no corrompe nada, pero **sí
  llena la bandeja de incidencias**: un fichaje que llega con más de
  `ATTENDANCE_MAX_CLOCK_SKEW_MINUTES` de desfase (15 minutos de serie) genera
  una incidencia para revisión humana y **nunca se rechaza** — el empleado ficha
  igual.

  ```bash
  timedatectl status
  ```

- **El `.env` es `0600`.** El instalador lo deja así, y solo lo puede leer el
  usuario que instaló —`root`, si lo ejecutaste con `sudo`, que es lo
  recomendado—. Compruébalo después de cualquier restauración o de copiar
  directorios:

  ```bash
  cd /opt/kronoqr
  sudo ls -l .env
  sudo chmod 0600 .env
  ```

  Los permisos tienen que ser `-rw-------`. `./doctor.sh` también lo comprueba,
  pero **solo con la aplicación parada** (con ella en marcha delega en
  `product:doctor`, que corre dentro del contenedor y no ve el `.env` del
  servidor): la orden de arriba es la comprobación de verdad.
- **Nada del `.env` viaja en un ticket, en un correo ni en una captura.** El
  fabricante no necesita ningún valor de ese fichero para ayudarte: para eso
  está el paquete de diagnóstico ([`operacion.md`](operacion.md) §12.2), que
  además está construido para no llevarlos.

> **Cierra:** acceso al anfitrión, escalada por el grupo `docker`, lectura de
> los secretos de la instalación e incidencias falsas por hora desviada.
> · **Dueño:** tú.

---

## 4. Los secretos: dónde vive cada uno

El instalador los genera **en tu servidor**, los escribe en el `.env` en `0600`
y **no imprime ninguno**. El fabricante no los conoce y no puede recuperarlos.

| Secreto | Dónde vive | ¿Sale del servidor? |
| --- | --- | --- |
| Clave de la aplicación, claves de firma del QR, contraseñas de base de datos, credenciales del servidor de tiempo real, clave de sellado del PIN | `.env`, `0600` de `root` | **No** |
| Contraseña de administración de Grafana | `.env`, generada por el instalador | **No** |
| `BACKUP_ENCRYPTION_KEY` | `.env` **y una copia custodiada fuera** | **Sí, y es obligatorio** |
| Contraseña del rol de mantenimiento de base de datos | **No existe** hasta la purga anual: se crea para esa operación y se retira al terminar | No |
| Token de una tablet | En la propia tablet (§6) | No |
| Token de un acceso de soporte | Se muestra **una sola vez** al concederlo | Se entrega a soporte por el canal del contrato |

**`BACKUP_ENCRYPTION_KEY` es la única que hay que sacar del servidor**, y hay
que hacerlo el día de la instalación: si se pierde la máquina y la clave estaba
solo ahí, las copias cifradas son bytes sin valor y el registro horario —que
hay que conservar cuatro años— se ha perdido. El procedimiento, con dónde
custodiarla y qué no hacer con ella, está en [`operacion.md`](operacion.md) §9.

**Nunca escribas un secreto en un fichero nuevo del servidor.** Cuando
necesites generar uno:

```bash
openssl rand -base64 32
```

**Rotación.** Cada secreto tiene su procedimiento y no todos se rotan igual: la
clave de copia, por ejemplo, no se rota sin conservar la anterior, porque **una
copia solo se descifra con la clave con la que se hizo**. Todo está en
[`../runbooks/rotacion-secretos.md`](../runbooks/rotacion-secretos.md). Rota
cuando cambie quién tiene acceso, no por calendario.

> **Cierra:** filtración de credenciales de la instalación, y pérdida
> irrecuperable de las copias. · **Dueño:** fabricante (generarlos bien y no
> imprimirlos), tú (custodiarlos).

---

## 5. Las copias

Tres cosas, y ninguna es opcional:

1. **Fuera del servidor.** Una copia en el mismo disco que la base de datos no
   es una copia. Si `BACKUP_PATH` apunta a un recurso de red, tiene que estar
   montado **antes** de levantar los servicios.
2. **Cifradas, y con la clave custodiada aparte** (§4).
3. **Probadas.** Una copia que nadie ha restaurado nunca es una hipótesis.
   Simulacro **trimestral**, siguiendo
   [`../runbooks/restaurar-backup.md`](../runbooks/restaurar-backup.md).

El calendario, qué copia cada trabajo, cuánto se conserva y cómo se verifica
están en [`operacion.md`](operacion.md). Si apagas la observabilidad,
**comprobar que la copia de anoche se hizo pasa a ser una tarea manual tuya**
(§9).

> **Cierra:** pérdida del registro horario, que es una obligación legal de
> cuatro años. · **Dueño:** tú.

---

## 6. Las tablets

**El riesgo real de un quiosco es físico.** Quien se lleva la tablet se lleva su
token de dispositivo: la PWA lo guarda en el almacenamiento del navegador, en
claro, y tiene que ser así. Una tablet colgada de una pared se reinicia sola
tras un corte de luz y debe volver a fichar **sin que nadie intervenga**;
guardar el token solo en memoria obligaría a re-emparejarla, con una persona
delante y un código nuevo, cada vez — y el quiosco no puede dejar sin fichar a
nadie a las 06:00.

Es un riesgo **conocido y aceptado por diseño**, con estos controles ya en el
producto:

- El token lleva **solo tres permisos**: registrar fichajes, leer el padrón y
  emitir el latido. Quien lo extraiga **no alcanza** la plantilla ni la gestión.
- **Desvincular lo invalida en la petición siguiente**, no en 90 días. La
  tablet purga entonces el padrón que tenía guardado y vuelve a la pantalla de
  emparejamiento **sin tocar la cola de fichajes pendientes**, porque ahí hay
  jornadas de personas.
- El secreto de emparejamiento **no se conserva** una vez recogido el token, así
  que no queda una segunda credencial olvidada.

Y estos son tuyos:

- **Modo quiosco o gestión de dispositivos (MDM)**, con la PWA como única
  aplicación y la salida protegida por PIN. Es lo que impide abrir un navegador
  o una consola en el aparato. **No es una funcionalidad del producto y no lo
  será**: ninguna aplicación web puede impedir que alguien deslice y salga al
  escritorio. Procedimiento completo en
  [`../runbooks/alta-nuevo-quiosco.md`](../runbooks/alta-nuevo-quiosco.md) §2.
- **Custodia física**: soporte anclado, a la vista del personal, sin acceso
  público.
- **La respuesta a un robo o a una pérdida es desvincular esa tablet**, de
  inmediato, desde «Quioscos» en el panel: es la acción que invalida el token.
  Después, si procede, trata el hecho como incidencia de seguridad
  ([`../runbooks/brecha-de-seguridad.md`](../runbooks/brecha-de-seguridad.md)).

> **Cierra:** uso del token de una tablet sustraída para enviar fichajes falsos.
> · **Dueño:** fabricante (permisos mínimos y revocación inmediata), tú (modo
> quiosco y custodia física).

---

## 7. El correo

**Por este canal salen nombres de la plantilla, a diario.** El resumen nocturno
de incidencias se envía al responsable de cada departamento con **la fecha, el
nombre, el tipo y la gravedad** de cada hallazgo, y nada más: ni el registro horario, ni
datos de contrato, ni totales. Es el único camino por el que datos personales
salen del servidor sin que nadie pulse nada, y por eso **deja asiento en la
auditoría**: sabrás siempre de quién se enviaron datos y cuándo.

Dos cosas te tocan a ti:

1. **`MAIL_SCHEME=smtps` en producción.**

   ```dotenv
   MAIL_SCHEME=smtps
   ```

   Vacío o `smtp` significa STARTTLS **oportunista**: si el relevo no lo
   anuncia, la sesión sigue **en claro** y ese correo —con nombres dentro—
   viaja legible. Con `smtps` el cifrado es obligatorio desde el primer byte y,
   si el relevo no lo soporta, **el envío falla en vez de degradarse**. Un envío
   fallido no rompe nada: la incidencia sigue abierta en la bandeja y entra en
   el resumen de la noche siguiente.

2. **Si el relevo SMTP es de un tercero** (el correo corporativo alojado, el
   proveedor de tu dominio), ese tercero es **encargado del tratamiento** y
   tienes que tenerlo contratado como tal. Está en
   [`obligaciones-legales.md`](obligaciones-legales.md) §2.

Después de configurarlo, comprueba que el sistema alcanza el relevo y que no
está mandando los correos a un fichero:

```bash
cd /opt/kronoqr-2.1.0
docker compose exec app php artisan product:doctor
```

Las dos comprobaciones que miran esto son `mail.transport` —avisa si en
producción el correo va al registro en vez de salir— y `mail.reachable`, que
abre la conexión al servidor de correo configurado y la cierra. **No envía
ningún correo de prueba** y **no comprueba el cifrado**: eso lo verificas
mirando que llega el resumen de la noche siguiente.

> **Cierra:** nombres de la plantilla viajando en claro por internet, y un
> tratamiento por un tercero sin contrato. · **Dueño:** fabricante (el canal y
> el mínimo que viaja), tú (el cifrado y el contrato).

---

## 8. Cuentas y accesos

- **Segundo factor obligatorio.** De serie lo exigen los tres roles que
  alcanzan datos de toda la plantilla: administrador, RRHH y auditor. El
  responsable de departamento no lo exige porque su alcance está acotado al
  suyo. Si tu política de seguridad es más dura, añádelo sin tocar nada más:

  ```dotenv
  IDENTITY_2FA_REQUIRED_ROLES=admin,rrhh,auditor,responsable_departamento
  ```

  Quien ya tiene segundo factor lo usa siempre, aunque su rol no lo exija.
- **Un rol por función, y el más pequeño que sirva.** Hay cuatro:

  | Rol | Para quién | Alcance |
  | --- | --- | --- |
  | `responsable_departamento` | Jefes de sala, de pisos, de cocina | Su departamento: consulta, corrección e incidencias |
  | `rrhh` | Quien opera el producto a diario | Toda la plantilla: jornadas, correcciones, empleados, tarjetas e informes |
  | `auditor` | Auditoría interna, asesoría laboral | Lectura: jornadas, auditoría y exportación legal |
  | `admin` | IT | Lo anterior, más ajustes, licencia, soporte y diagnóstico |

  **No des `admin` a RRHH.** El rol `rrhh` hace todo el trabajo diario; `admin`
  añade justo lo que RRHH no debería poder tocar: los umbrales legales, la
  licencia y los accesos de soporte.
- **Nunca una cuenta compartida.** Una corrección de fichaje se guarda con su
  autor, y una cuenta «recepcion» convierte la auditoría en un documento que no
  responde a la única pregunta que le van a hacer: quién.
- **Revisa las cuentas al dar bajas.** Cuando alguien deja el hotel o cambia de
  puesto, su cuenta de gestión se retira ese mismo día. Ponlo en la lista de
  salida de personal, junto a las llaves y al correo.
- **Accesos de soporte: con motivo, con alcance y con caducidad.** El fabricante
  **no tiene acceso a tu instalación**. Cuando el paquete de diagnóstico no
  basta, concedes un acceso temporal: lo concedes tú, caduca solo (72 horas como
  máximo) y lo revocas cuando quieras. Queda registrado quién lo concedió, por
  qué, con qué alcance, **cuándo se usó** y cuándo se revocó. Procedimiento y
  tabla de alcances en [`operacion.md`](operacion.md) §12.4.

  Revísalos de vez en cuando, y revoca lo que sobre:

  ```bash
  cd /opt/kronoqr
  docker compose exec app php artisan support:revoke --all
  ```

- **El portal del empleado se abre con código de empleado y PIN**, no con
  correo. El PIN se entrega en mano y no se recupera por correo: se restablece
  desde RRHH. No hay credencial en el móvil ni biometría, por decisión de
  producto: la credencial es la tarjeta física.

> **Cierra:** cuentas con más permisos de los necesarios, cuentas de personas
> que ya no están, y accesos del fabricante sin caducidad ni traza.
> · **Dueño:** tú (las cuentas), fabricante (los alcances y la auditoría).

---

## 9. Observabilidad

Viene **encendida** (`COMPOSE_PROFILES=observability`) y conviene dejarla: es lo
que avisa de las dos cosas que convierten una instalación sana en una pérdida de
datos sin que nadie lo note mirando la pantalla — que la copia de anoche falló y
que el archivado del registro de escritura se ha parado y el disco se llena.

- **Grafana escucha solo en `127.0.0.1:3000`.** Se llega por túnel SSH:

  ```bash
  ssh -L 3000:127.0.0.1:3000 tu-usuario@fichaje.tuhotel.local
  ```

  Y después, `http://127.0.0.1:3000` en tu navegador.
- **Su contraseña la genera el instalador** y vive en el `.env`. Si necesitas
  leerla:

  ```bash
  cd /opt/kronoqr
  sudo sed -n 's/^GRAFANA_ADMIN_PASSWORD=//p' .env
  ```

  Si la cambias desde la propia interfaz de Grafana, anótalo: el `.env` dejará
  de reflejar la que vale.
- **`METRICS_ALLOW_CIDR` autoriza una sola dirección**, la del recolector de
  métricas, y el valor de serie ya es la correcta. Cualquier otro origen recibe
  `403`, **incluido el propio servidor**. Que sea una dirección concreta y no
  una red entera no es manía: con la red completa autorizada, las peticiones
  hechas desde el anfitrión entran dentro del rango y `/metrics` queda accesible
  sin que nada lo avise.
- **Si la apagas** —es una configuración soportada, y libera unos 700 MiB en un
  servidor justo de memoria—, pierdes las alertas de copia y de disco:
  comprobar la copia pasa a ser una tarea manual **semanal** tuya. Lo que se
  pierde exactamente está en [`operacion.md`](operacion.md) §10.

> **Cierra:** exposición del estado interno del sistema, y una pérdida de copias
> que nadie detecta. · **Dueño:** fabricante (el aislamiento), tú (la contraseña
> y la decisión de apagarla).

---

## 10. Qué revela el sistema a quien alcance el borde

Tres cosas se sirven **sin autenticar**, y las tres a propósito. Conviene que
las conozcas antes de que te las enseñe un informe de auditoría:

| Qué revela | Dónde | Por qué es aceptable |
| --- | --- | --- |
| **La versión instalada y el estado de la licencia**, en una palabra (`valid`, `expired`, `absent`…) | `GET /api/v1/health` | Es la única sonda que no exige sesión ni base de datos, y es lo que permite a `doctor` y al paquete de diagnóstico informar del estado. De ahí **no sale** el nombre del cliente, ni el plan, ni los límites, ni las fechas: eso exige cuenta de administrador. Una licencia caducada responde `200`, porque lo contrario haría que un orquestador retirara del servicio un sistema que ficha perfectamente |
| **La marca de la instalación**: nombre visible, un color y el logotipo | `GET /api/v1/branding` y `/api/v1/branding/logo` | El quiosco y el portal la necesitan **antes** de identificar a nadie. Lo que revela es lo mismo que lleva impreso cada tarjeta y el rótulo de recepción. La respuesta está cerrada a cinco claves de presentación y tiene su propio límite por IP |
| **Que la instalación se está actualizando ahora**, durante la ventana de mantenimiento | `503` con `Retry-After` en el panel y el portal | Es lo que permite a la tablet distinguir «no se decidió» de «rechazado» y **conservar el fichaje en su cola**. El cuerpo no dice de qué versión a cuál, ni cuánto falta, ni quién es el cliente |

**Durante esa ventana el fichaje no se interrumpe:** las tablets confirman en
local y encolan, y cada fichaje conserva su hora real.

Nada de esto es un dato personal ni una credencial. Aun así, **quien no debe
alcanzar el borde no debería alcanzarlo**: con el §1 bien hecho, esta
información solo la ve quien ya está dentro de tu red. Esa es la razón de que
este apartado vaya después del de red y no antes.

> **Cierra:** nada por sí mismo. Documenta lo que es **decisión de tu red** y no
> del producto. · **Dueño:** fabricante (que no salga nada más), tú (la
> exposición).

---

## 11. Lista de comprobación

Repásala el día de la entrega y cada tres meses. Lo que no se comprueba, no
está.

| # | Control | Cómo se comprueba | Cada cuánto |
| --- | --- | --- | --- |
| 1 | Solo el borde y Grafana publican puerto | `docker compose ps --format 'table {{.Service}}\t{{.Ports}}'` | Entrega y tras cada actualización |
| 2 | Lo cerrado está cerrado, visto desde fuera | `nmap -Pn -p 22,80,443,3000,5432,6379 fichaje.tuhotel.local` desde otra máquina | Entrega y trimestral |
| 3 | El panel no es alcanzable desde internet | Intentar abrir `/admin/` desde fuera de la red | Entrega y trimestral |
| 4 | Las tablets caen dentro de `KIOSK_VLAN_CIDR` | `docker compose logs --tail 200 nginx \| grep '"from_kiosk_vlan":0'` | Entrega y al añadir un quiosco |
| 5 | El portal responde solo desde su rango | Abrir `/portal/` desde fuera: debe dar `403` | Entrega y trimestral |
| 6 | Certificado válido y con margen | `./doctor.sh` (avisa 30 días antes) | Mensual |
| 7 | `TLS_ALLOW_SELF_SIGNED` en `false` | `sudo grep '^TLS_ALLOW_SELF_SIGNED=' .env` | Entrega |
| 8 | `.env` en `0600` | `sudo ls -l .env` (`./doctor.sh` solo lo comprueba con la aplicación parada) | Entrega y tras cada restauración |
| 9 | SSH con clave, sin contraseña ni `root` | `sudo sshd -T \| grep -E 'passwordauthentication\|permitrootlogin'` | Trimestral |
| 10 | El grupo `docker` solo tiene a quien debe | `getent group docker` | Trimestral y en cada baja |
| 11 | Hora sincronizada | `timedatectl status` | Trimestral |
| 12 | Clave de copias custodiada fuera del servidor | Comprobar que existe en el gestor de contraseñas o en la caja fuerte, con su fecha | Entrega y anual |
| 13 | La copia de anoche existe y se verificó | `docker compose exec app php artisan backup:verify` ([`operacion.md`](operacion.md) §2), o la alerta de la observabilidad | Diario (automático) o semanal (manual, si la apagaste) |
| 14 | Restauración probada de verdad | Simulacro de [`../runbooks/restaurar-backup.md`](../runbooks/restaurar-backup.md) | **Trimestral** |
| 15 | Correo cifrado de forma obligatoria | `sudo grep '^MAIL_SCHEME=' .env` | Entrega |
| 16 | Segundo factor obligatorio en las cuentas de gestión | `grep '^IDENTITY_2FA_REQUIRED_ROLES=' .env` sigue diciendo `admin,rrhh,auditor`: una cuenta de esos roles sin segundo factor no puede entrar | Trimestral |
| 17 | Ninguna cuenta de quien ya no está | No hay pantalla de cuentas de gestión: consulta de solo lectura `docker compose exec -T postgres psql -U fichaje_app -d fichaje -c "select email, is_active, last_login_at from users order by last_login_at nulls first"` (con el rol de la aplicación, que no puede alterar el registro; nunca con `fichaje_migrator`) y contrasta con la lista de personal. **En esta versión no hay baja de cuentas de gestión** ni desde el panel ni por consola: si sobra alguna, abre un caso con el fabricante; llega en una versión posterior de la serie 2.x | Trimestral y en cada baja |
| 18 | Ningún acceso de soporte vivo sin motivo | Panel → «Soporte» → «Accesos de soporte» | Mensual |
| 19 | Todas las tablets en modo quiosco y ancladas | Recorrido físico: reiniciar una y comprobar que arranca sola en la PWA | Trimestral |
| 20 | Quioscos vinculados = puestos que existen | `docker compose exec app php artisan kiosk:health` | Trimestral |
| 21 | Diagnóstico general en verde | `./doctor.sh` | Mensual y antes de cada actualización |
| 22 | Sistema operativo y Docker al día | El gestor de paquetes de tu distribución | Mensual |

---

## Y si algo de aquí choca con tu política interna

Manda la tuya, con dos excepciones que no son negociables porque no son opciones
de configuración:

1. **La caducidad de la licencia no bloquea nunca el fichaje ni el acceso al
   registro.** Ninguna medida de endurecimiento debe intentar «reforzar» eso:
   dejaría al hotel incumpliendo la ley y sin acceso a datos que está obligado a
   conservar cuatro años.
2. **El registro de jornada no se toca por SQL.** Ni para corregir, ni para
   limpiar. Todo lo que hay que hacer tiene su camino en el panel o en un
   comando, y todos dejan traza. Lo que no debe hacerse nunca está en
   [`operacion.md`](operacion.md).

Si dudas de un control concreto, la respuesta segura es la más restrictiva:
todo lo de esta guía está pensado para que el fichaje siga funcionando aunque
apliques la versión estricta.
