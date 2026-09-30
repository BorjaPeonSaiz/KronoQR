# Runbook — el portal del empleado devuelve 403

**Casi nunca es una avería: es el candado del portal haciendo su trabajo.** El
servidor web solo sirve el portal del empleado (`/portal/` y su API,
`/api/v1/me/*`) a las peticiones que llegan desde **un rango de red**,
`PORTAL_INTERNAL_CIDR`. Cualquier otro origen recibe `403` antes de llegar a la
aplicación. Es el requisito RF-ID-08: *el portal es accesible desde la red
interna por defecto, y exponerlo a internet es una decisión explícita del
cliente*.

Este runbook sirve para tres cosas, por este orden: saber si el `403` es ese
candado o es otra cosa, averiguar qué IP está viendo el servidor y por qué no
entra en el rango, y —si el hotel lo decide— abrir el portal a internet sabiendo
lo que se asume.

**Impacto en el fichaje: ninguno.** Las tablets no usan el portal ni su rango:
fichan por otra ruta, con su propio token. Un portal con `403` no deja a nadie
sin fichar ni pierde un solo registro. Lo que pierde el empleado es **consultar**
sus horas desde ese sitio; RRHH puede darle su registro desde el panel mientras
tanto.

**Destinatario: el IT del hotel.** Todo se hace en el servidor, desde el
directorio de la instalación (donde están `docker-compose.yml` y `.env`).

---

## 1. ¿Es el candado del portal, o es otra cosa?

Lo que ve la persona en el navegador lo dice:

| Lo que aparece | Qué es |
| --- | --- |
| Una página con **«Portal del empleado no disponible desde esta red»** y, debajo, lo mismo en inglés | **El candado del portal.** Sigue este runbook |
| En la API, un `403` cuyo cuerpo dice `Acceso no permitido desde esta red` | Lo mismo, visto por la aplicación del portal |
| La pantalla de acceso carga, pero al entrar dice que el código o el PIN no son válidos, o que está bloqueado | **No es este runbook.** El rango está bien; es el PIN. RRHH lo restablece desde el panel ([`../cliente/guia-rrhh.md`](../cliente/guia-rrhh.md)) |
| Aviso de sitio no seguro, o no carga nada | **No es este runbook.** Es el certificado o la red ([`../cliente/instalacion.md`](../cliente/instalacion.md) §5) |

---

## 2. Diagnóstico: qué rango hay puesto y qué IP ve el servidor

### 2.1 Qué rango tienes escrito, y qué rango está usando el servidor web

```bash
grep '^PORTAL_INTERNAL_CIDR=' .env
docker compose exec nginx printenv PORTAL_INTERNAL_CIDR
```

Las dos líneas tienen que decir lo mismo. **Si no coinciden**, alguien cambió el
`.env` y el servidor web sigue con el valor antiguo: el cambio solo se aplica al
recrear el contenedor (§5). Si coinciden, sigue.

**Si dice `172.28.0.0/16`**, es el valor de la plantilla, pensado para el
entorno de desarrollo del fabricante. En producción no cubre a nadie: la red de
contenedores de tu servidor no tiene esa subred fija. Ponle la red real del
hotel (§3) y aplica el cambio (§5).

### 2.2 Qué IP ve el servidor web

Pide a la persona afectada que recargue el portal **ahora**, y después:

```bash
docker compose logs --no-log-prefix --since 10m nginx | grep '"status":403' | grep -o '"remote_addr":"[^"]*"' | sort | uniq -c
```

Sale una línea por cada IP rechazada en los últimos diez minutos, con cuántas
veces. Por ejemplo:

```text
      3 "remote_addr":"10.0.10.57"
```

Esa es **la IP que ve el servidor**, que no siempre es la que el ordenador de la
persona cree tener. Es la única que cuenta. El registro del servidor web no
guarda nombres ni cuerpos: solo IP, ruta y código.

### 2.3 ¿Esa IP cae dentro del rango?

El número tras la barra dice cuántas cifras tienen que coincidir:

| Rango | Entra si… | Ejemplo |
| --- | --- | --- |
| `/32` | Es exactamente esa IP | `10.0.10.57/32` solo admite `10.0.10.57` |
| `/24` | Coinciden los **tres** primeros números | `10.0.10.0/24` admite de `10.0.10.0` a `10.0.10.255` |
| `/16` | Coinciden los **dos** primeros números | `10.0.0.0/16` admite de `10.0.0.0` a `10.0.255.255` |
| `/8` | Coincide el **primero** | `10.0.0.0/8` admite todo `10.x.x.x` |

Con la IP de §2.2 y esta tabla sabes en qué caso estás. Pasa a §3.

---

## 3. Los casos, y qué hacer con cada uno

| Lo que ves | Qué pasa | Qué hacer |
| --- | --- | --- |
| La IP es de la LAN del hotel, pero **fuera** del rango | El rango se quedó corto (otra planta, otra VLAN, otro DHCP) | Pon un rango que cubra las dos redes. **Solo admite un rango**: si la LAN es `10.0.10.0/24` y la otra `10.0.11.0/24`, escribe `10.0.10.0/23` o `10.0.0.0/16`. Aplica (§5) |
| La IP es de la **VPN** corporativa | Quien entra por VPN sale con una IP de su propio rango | Igual que la fila anterior: un rango que cubra LAN y VPN. Si no hay uno razonable que cubra las dos, decide cuál de las dos es la que necesita el portal |
| Pruebas **desde el propio servidor** (`https://localhost/portal/` o su IP de la LAN) y la IP es algo como `172.18.0.1` | Las peticiones del servidor a sí mismo entran por la puerta de enlace de la red de contenedores, no por la LAN: el servidor web ve esa dirección interna | **Es lo esperado y no hay que arreglarlo.** No autorices esa dirección para poder probar: prueba desde un ordenador de la LAN. Para confirmar que es la puerta de enlace, ver el comando de debajo de la tabla |
| **Todas** las peticiones llegan con la misma IP interna (`172.x.0.1`, `192.168.65.x`), vengan de donde vengan | El servidor corre sobre **Docker Desktop** (Windows o macOS) o con Docker **sin root**. En los dos, Docker entrega las conexiones desde una dirección propia y el servidor web nunca ve la IP real | En esa máquina el rango **no puede distinguir** la LAN de internet: autorizar esa IP equivale a abrir el portal a quien llegue al puerto. Docker Desktop no es plataforma de producción ([`../cliente/instalacion.md`](../cliente/instalacion.md) §0: Linux con Docker Engine). Para una demostración, `0.0.0.0/0` a sabiendas (§4) |
| Todas llegan con la IP de **un proxy inverso, un balanceador o una CDN** | El servidor web ve la IP de ese equipo, no la de la persona | **No autorices la IP del proxy en el rango** «para que funcione»: dejarías entrar a todo lo que pase por él. Declara el proxy en `TRUSTED_PROXY_CIDR` (§3.1): el servidor web pasa a tomar la IP real de la cabecera `X-Forwarded-For`, solo cuando la petición viene de ese proxy, y el rango vuelve a distinguir a las personas |
| La IP es **pública** (un móvil con datos, la casa de la persona) | El portal está cerrado a internet, que es el valor de serie | **Es el comportamiento correcto.** O se entra desde la red del hotel (o la VPN), o el hotel decide abrirlo (§4) |
| El rango ya es `0.0.0.0/0` y aun así da `403` | No es el rango: `0.0.0.0/0` lo admite todo | Comprueba §2.1 (¿el servidor web tiene el valor nuevo?). Si lo tiene, recoge el paquete de diagnóstico y escala (§6) |

Para saber cuál es la puerta de enlace de las redes de contenedores del
producto (si no usas la observabilidad, la segunda red no existe y el comando lo
dirá; es normal):

```bash
docker network inspect kronoqr-app kronoqr-observability --format '{{.Name}} {{range .IPAM.Config}}{{.Gateway}}{{end}}'
```

Si la IP de §2.2 es una de esas, la petición salió del propio servidor.

### 3.1 Con un proxy, un balanceador o una CDN delante: `TRUSTED_PROXY_CIDR`

Pon en el `.env` la dirección del proxy (o las de la CDN), en formato CIDR y
separadas por comas; una IP suelta con `/32`:

```dotenv
TRUSTED_PROXY_CIDR=10.0.0.5/32
```

Tres condiciones, y las tres importan:

- **El proxy tiene que añadir `X-Forwarded-For`** con la IP del visitante. Casi
  todos lo hacen de serie; compruébalo en su configuración.
- **Nunca `0.0.0.0/0`.** Confiaría en cualquiera: bastaría con escribir esa
  cabecera para hacerse pasar por la red interna. El servidor web no arranca con
  ese valor, y `./doctor.sh` y el instalador lo rechazan.
- **Deja `TRUSTED_PROXIES` vacía.** Es la variable equivalente de la aplicación;
  con `TRUSTED_PROXY_CIDR` puesta, el servidor web ya le entrega la IP real y la
  aplicación no tiene que volver a leer la cabecera.

Aplica el cambio como en §5 (`docker compose up -d nginx`) y repite §2.2: la IP
rechazada debe ser ahora la de la persona, no la del proxy. El detalle, en
[`../cliente/instalacion.md`](../cliente/instalacion.md) §6 y
[`../cliente/endurecimiento.md`](../cliente/endurecimiento.md) §1.6.

---

## 4. Abrir el portal a internet

Se hace con un solo valor:

```dotenv
PORTAL_INTERNAL_CIDR=0.0.0.0/0
```

y se aplica como cualquier otro cambio (§5). El servidor web arranca y deja en
su registro un aviso: `PORTAL_INTERNAL_CIDR=0.0.0.0/0: portal abierto a
internet`. No es un error; es el recordatorio de que la decisión está tomada.

**Es una decisión del hotel, no del IT.** La toma quien responde del registro
horario (dirección o RRHH), por escrito, y **se anota en el acta de entrega de
la instalación**: es lo que responde el día que alguien pregunte por qué el
portal es alcanzable desde fuera.

**Lo que se asume, dicho sin rodeos.** El portal se abre con código de empleado
y PIN de **6 dígitos**. Restringirlo a la red interna es uno de los cuatro
controles que compensan un PIN tan corto; al abrirlo quedan los otros tres:

1. **Bloqueo por intentos**, creciente, por empleado.
2. **Límite de peticiones por IP y por código de empleado**.
3. La sesión del portal **solo alcanza los datos de esa persona**, nunca los de
   otra.

**Hoy, abrir el portal no cambia nada más que el rango**: el acceso sigue siendo
código y PIN de 6 dígitos, ahora frente a cualquiera. Está previsto que una
versión posterior exija un **PIN más largo (8 dígitos)** cuando el portal esté
abierto a internet. Cuando tu versión lo traiga —lo dirán las notas de la
versión—, actívalo el mismo día y pide a la plantilla que cambie el PIN.

**Cuándo NO conviene abrirlo:**

- **Si nadie lo ha pedido.** Que un empleado quiera mirar sus horas desde casa
  una vez al mes no justifica exponer el portal de toda la plantilla; RRHH le
  puede dar su registro desde el panel.
- **Si hay una VPN corporativa que la plantilla ya usa.** Pon el rango de la VPN
  (§3) y el portal sigue cerrado a internet.
- **Si has apagado la observabilidad.** Sin ella no hay alertas de fuerza bruta
  sobre el PIN (`KronoqrAuthFailureBurst` y compañía): los intentos se frenan,
  pero nadie se entera. Enciéndela antes
  ([`../cliente/operacion.md`](../cliente/operacion.md) §10) y ten a mano
  [`ataque-a-credenciales.md`](ataque-a-credenciales.md).
- **Si el servidor está detrás de un proxy o una CDN sin `TRUSTED_PROXY_CIDR`**
  (§3.1): el límite por IP vería una sola IP para todo el mundo y dejaría de
  proteger.

**Y cerrarlo otra vez** es volver a poner el rango de la LAN y aplicar (§5). Las
sesiones de portal ya abiertas desde fuera dejan de poder pedir datos en el
mismo momento: el candado está en el servidor web, delante de la aplicación.

---

## 5. Aplicar un cambio de rango

Edita `PORTAL_INTERNAL_CIDR` en el `.env` —**un solo rango IPv4 con su barra**,
por ejemplo `10.0.10.0/24`; una IP suelta se escribe con `/32`— y recrea el
servidor web:

```bash
docker compose up -d nginx
docker compose logs --tail 20 nginx
```

Si el valor no es válido, el servidor web **no arranca** y el registro dice qué
variable y qué valor. Los quioscos se quedan sin servidor mientras tanto —su cola
local guarda los fichajes, nadie deja de fichar—, así que corrígelo en el acto.

Comprueba después, **desde un ordenador de la LAN** (no desde el servidor, §3),
cambiando la dirección por tu `APP_URL`:

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://fichaje.tuhotel.local/portal/
```

`200` es que entra. `403`, vuelve a §2.2 con esa misma máquina.

---

## 6. A quién se escala

**Al fabricante, solo si** el valor es correcto en el `.env` y en el contenedor
(§2.1), la IP de §2.2 está dentro del rango (§2.3) y aun así da `403`. Adjunta el
paquete de diagnóstico ([`../cliente/operacion.md`](../cliente/operacion.md)
§12.2) y la línea que dio el comando de §2.2. El paquete no lleva la IP de
nadie: por eso hace falta esa línea.

Todo lo demás de este runbook se resuelve en el hotel.
