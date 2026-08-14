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

El `.env.example` del repositorio no contiene ni un solo secreto real: son
plantillas comentadas.
