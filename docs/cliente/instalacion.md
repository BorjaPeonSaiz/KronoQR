# Instalación de KronoQR

> **Estado.** Este documento se completa en la **tarea 5.11** (guía de
> instalación, operación, configuración y obligaciones legales). Aquí está ya
> lo que la tarea 0.1 introduce y **no puede esperar**: los parámetros de red
> cuya mala configuración produce un fallo silencioso.

## Parámetros de red que hay que decidir antes de instalar

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

`TLS_ALLOW_SELF_SIGNED=true` es exclusivo de entornos de prueba.

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
bash /opt/kronoqr/scripts/restore-drill.sh        # simulacro trimestral
```

El procedimiento completo de recuperación —y el simulacro que hay que ejecutar
cada trimestre— está en
[`docs/runbooks/restaurar-backup.md`](../runbooks/restaurar-backup.md).

## Secretos

**El instalador genera los secretos en el servidor del cliente y no los
transmite a nadie** (doc 02 §7.7). El fabricante no los conoce y no puede
recuperarlos. Lo que hay que custodiar:

| Secreto | Consecuencia de perderlo |
| --- | --- |
| `APP_KEY` | Las sesiones y los datos cifrados dejan de poder leerse |
| Contraseña de la base de datos | Se puede rotar; ver `docs/runbooks/rotacion-secretos.md` |
| `QR_SIGNING_KEY_CURRENT` | **Hay que reimprimir todas las tarjetas** |
| `BACKUP_ENCRYPTION_KEY` | **Las copias de seguridad dejan de poder restaurarse** |
| `IDENTITY_PIN_SEALING_SECRET_KEY` | **Los fichajes por PIN encolados sin red en el quiosco no se pueden abrir nunca.** Genérala con `php artisan tinker --execute="echo base64_encode(sodium_crypto_box_secretkey(sodium_crypto_box_keypair()));"` (ver `.env.example`). Vacía es válida: significa que esta instalación no ofrece fichaje por PIN |

El `.env.example` del repositorio no contiene ni un solo secreto real: son
plantillas comentadas.

**La clave de licencia no es un secreto y no está en esa tabla.** Es una
afirmación firmada sobre lo que has contratado, no abre nada, y perderla no
tiene consecuencia alguna: se pide otra al proveedor y se vuelve a activar.

## La licencia, al instalar

Pega la clave que te entregó tu proveedor en `LICENSE_KEY` del `.env` **antes**
de ejecutar el instalador, o actívala después con:

```bash
docker compose exec app php artisan license:activate "KQL1...."
```

> **Si no la tienes a mano, instala igualmente.** Sin licencia activada, el
> sistema se instala, arranca y **registra jornada con normalidad**: lo único
> que no estará disponible son las funcionalidades accesorias —informes por
> periodo y actualización en tiempo real de la presencia—. La activas cuando la
> tengas y aparecen solas, sin reiniciar nada.

Comprueba en cualquier momento cómo está con
`docker compose exec app php artisan license:show`. Todo lo demás sobre la
licencia está en [`configuracion.md`](configuracion.md), sección 3 bis.
