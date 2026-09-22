# Runbook — el certificado TLS caduca, ya ha caducado, o no verifica

**Alertas que llevan aquí** (doc 01 §9.3, fila *«Certificado TLS próximo a
expirar | < 21 días | Alta»*, más una tercera de la tarea 3.8), definidas en
[`infra/observability/prometheus/rules/tls.yml`](../../infra/observability/prometheus/rules/tls.yml):

| Alerta | Umbral | Severidad | Destinatario | Sección |
| --- | --- | --- | --- | --- |
| `CertificadoTlsProximoACaducar` | menos de 21 días para expirar, `for: 1h` | Alta | IT del cliente | [§3](#3-renovar-el-certificado) |
| `CertificadoTlsCaducado` | ya ha expirado, `for: 5m` | Crítica | IT del cliente | [§3](#3-renovar-el-certificado) |
| `CertificadoTlsNoVerificable` | no verifica contra `APP_URL` (cadena, nombre o emisor), no es por caducidad, `for: 15m` | Crítica | IT del cliente | [§3.4](#34-el-certificado-no-verifica-cadena-nombre-o-emisor-certificadotlsnoverificable) |

**A las 06:30, quien la reciba hace esto:** comprueba la fecha real con
`openssl` (§2), consigue o renueva el certificado según de dónde venga el
tuyo (§3.1), lo coloca en `TLS_CERT_DIR` con los permisos del uid 101, y
recarga Nginx **sin reiniciar** (§3.2). Quince minutos si el certificado ya
lo tienes; el tiempo que tarde tu CA si hay que pedirlo de nuevo — por eso el
aviso llega con 21 días de margen y no el día que caduca. Si lo que llegó fue
`CertificadoTlsNoVerificable` y no una de las dos de caducidad, ve directo al
[§3.4](#34-el-certificado-no-verifica-cadena-nombre-o-emisor-certificadotlsnoverificable):
el problema no es la fecha.

---

## 1. Impacto, y por qué esta alerta es una cuenta atrás

**Mientras el certificado siga siendo válido (`CertificadoTlsProximoACaducar`),
ninguno: nadie nota nada.** Es un aviso, no una avería — de ahí que este
runbook empiece con margen de días, no de minutos.

**En cuanto caduca (`CertificadoTlsCaducado`), el impacto es real y en dos
sitios a la vez:**

- **Las tablets dejan de poder hablar con el servidor por HTTPS.** Un
  navegador moderno no deja pasar una conexión a un certificado caducado ni
  con la comprobación de identidad relajada: la PWA del quiosco no puede
  completar la petición. La regla dura 19 sigue protegiendo a quien va a
  fichar — la app encola en local igual que si no hubiera red — pero **nada
  de lo encolado sincroniza** hasta que el certificado se corrija: es
  exactamente el mismo efecto que [`cola-offline-atascada.md`](cola-offline-atascada.md)
  en **todos** los quioscos a la vez.
- **El panel de gestión y el portal del empleado no abren.** Quien intente
  entrar verá el aviso de conexión no segura de su navegador, sin forma
  razonable de continuar — y no debe enseñarse a nadie a saltárselo: el día
  que alguien lo haga sin pensar, deja de proteger nada.

---

## 2. Ver la fecha real, sin fiarte solo de la alerta

**Desde la sonda**, si el perfil `observability` está encendido (es el mismo
exportador que hace de sonda de disponibilidad, doc 02 §8.1):

```bash
curl -s 'http://127.0.0.1:9090/api/v1/query?query=probe_ssl_earliest_cert_expiry' \
  | jq -r '.data.result[0].value[1] | tonumber | gmtime | strftime("%Y-%m-%d %H:%M:%SZ")'
```

**Directamente contra el servidor**, sin pasar por Prometheus — la
comprobación que vale aunque la observabilidad esté apagada:

```bash
echo | openssl s_client -connect fichaje.tuhotel.local:443 \
    -servername fichaje.tuhotel.local 2>/dev/null \
  | openssl x509 -noout -enddate -subject -issuer
```

Salida esperada: `notAfter=` con la fecha de caducidad, y en `subject` el
mismo nombre que `APP_URL`. Si el nombre no coincide, el problema no es solo
de caducidad — revisa también §3.3.

---

## 3. Renovar el certificado

### 3.1 De dónde viene el tuyo, porque cambia cómo lo consigues

KronoQR **no trae ningún mecanismo de emisión ni de renovación automática de
certificados** (ADR y §3.4 del doc 02: el certificado es responsabilidad del
cliente, `TLS_CERT_DIR` — `./certs` de serie — es donde se coloca el que
tú aportes). Lo que hagas en este paso depende de cómo lo conseguiste al
instalar ([`../cliente/instalacion.md`](../cliente/instalacion.md) §1.2 y
§6):

| Origen | Cómo renuevas |
| --- | --- |
| **Certificado propio del hotel, o de la CA interna del cliente** | Pídelo de nuevo al mismo emisor con la antelación que te dé el aviso de 21 días. No hay atajo: es exactamente el mismo trámite que al instalar |
| **Let's Encrypt (u otra ACME) gestionado con `certbot` u otra herramienta del propio servidor** | La renovación la hace **esa** herramienta, fuera del producto — normalmente sola, por su propio temporizador. Lo que sí es del producto es lo que pasa después: `certbot` reescribe `tls.crt`/`tls.key` con su propio propietario (`root`), y el borde de KronoQR corre **sin privilegios**, como el uid `101`: en cuanto eso ocurre, Nginx entra en bucle de reinicio con `Permission denied` hasta que se corrigen los permisos (§3.2) — es el mismo caso que ya cubre [`../cliente/instalacion.md`](../cliente/instalacion.md) §5, «…nginx reinicia una y otra vez con "Permission denied"» |
| **Certificado autofirmado** | Solo válido en `TLS_ALLOW_SELF_SIGNED=true`, que es exclusivo de entornos de prueba. Si ves esta alerta en un entorno así, es esperable y no urge — pero un entorno de producción real **nunca** debería tener `TLS_ALLOW_SELF_SIGNED=true` (`../cliente/instalacion.md` §1.2) |

**Si `TLS_CERT_DIR` apunta a un directorio compartido con otro servicio del
hotel** (el propio de `certbot`, por ejemplo), no le cambies el propietario a
ese directorio: copia el certificado renovado a un directorio propio de
KronoQR y apunta ahí `TLS_CERT_DIR` — la misma advertencia de la instalación.

### 3.2 Colocarlo y recargar Nginx sin parar el fichaje

```bash
cp /ruta/del/certificado/renovado.crt "${TLS_CERT_DIR:-./certs}"/tls.crt
cp /ruta/del/certificado/renovado.key "${TLS_CERT_DIR:-./certs}"/tls.key

# IMPRESCINDIBLE: el borde corre sin privilegios, con el uid 101.
sudo chown 101:101 "${TLS_CERT_DIR:-./certs}"/tls.crt "${TLS_CERT_DIR:-./certs}"/tls.key
sudo chmod 0444 "${TLS_CERT_DIR:-./certs}"/tls.crt
sudo chmod 0400 "${TLS_CERT_DIR:-./certs}"/tls.key

# Valida la sintaxis ANTES de recargar, y recarga sin caída.
docker compose exec nginx nginx -t
docker compose exec nginx nginx -s reload
```

`nginx -s reload` es una recarga en caliente: los quioscos, el panel y el
portal siguen atendidos durante todo el proceso — no hay ventana de
mantenimiento ni parada del fichaje que reservar para esto. `TLS_CERT_DIR`
está montado de solo lectura **dentro** del contenedor pero es una carpeta
normal **del anfitrión** (`infra/compose.prod.yaml`), así que sustituir el
fichero en el servidor basta: no hace falta `docker cp` ni recrear ningún
contenedor.

Si el certificado lo renovó `certbot` u otra herramienta automática, conviene
que su propio *hook* de renovación (`--deploy-hook` de `certbot`, por
ejemplo) ejecute estas mismas tres últimas órdenes — el `chown`/`chmod` y el
`nginx -s reload` — para que la próxima renovación no dependa de que alguien
la reciba a mano. Eso es configuración de **tu** herramienta de renovación,
no una funcionalidad del producto.

### 3.3 Si el navegador de la tablet lo sigue rechazando después de renovar

- **El nombre del certificado no coincide con `APP_URL`.** Revísalo con la
  orden de `openssl` del §2 (`subject`).
- **Falta la cadena completa.** `certs/tls.crt` tiene que llevar el
  certificado **y** los intermedios, no solo la hoja: es la causa más
  frecuente de que un certificado válido, correctamente colocado, siga
  provocando avisos en algunos dispositivos y no en otros.
- **La tablet tiene cacheada la versión anterior.** Recarga completa de la
  PWA (no solo la pantalla) tras confirmar que el servidor ya sirve el
  certificado nuevo.

### 3.4 El certificado no verifica (cadena, nombre o emisor): `CertificadoTlsNoVerificable`

**Añadida en la tarea 3.8** (hallazgo H-05 de la revisión interna de
seguridad). Hasta esta tarea, las dos alertas de arriba vigilaban únicamente
la **fecha** de caducidad (`probe_ssl_earliest_cert_expiry`), medida desde
**dentro** de la red de contenedores con `insecure_skip_verify: true` — a
propósito, porque el nombre `nginx` nunca coincide con el certificado del
hotel y verificar la cadena ahí daría siempre un falso negativo. Eso dejaba
un hueco real: una cadena incompleta, un certificado sustituido por otro de
emisor distinto, o un nombre que dejó de coincidir con `APP_URL` tras un
cambio de dominio, **no disparaban ninguna alerta** — el síntoma le llegaba
primero al empleado (aviso del navegador) que al IT del cliente.

`CertificadoTlsNoVerificable` cierra ese hueco con una segunda sonda,
`kronoqr-uptime-tls-verified` (módulo `http_2xx_tls_verified` de
`infra/observability/blackbox/blackbox.yml`, `insecure_skip_verify: false`),
que llega por `APP_URL` — el dominio público real — y exige cadena completa,
emisor de confianza y nombre coincidente, exactamente lo que exige un
navegador de verdad. La alerta solo dispara cuando las tres condiciones se
cumplen a la vez: el probe verificado falla, el borde **sí** responde desde
dentro (no es una caída completa — eso ya lo cubre `SondaDelBordeFallida`,
[`errores-en-el-panel.md`](errores-en-el-panel.md)) y el certificado **no**
ha caducado por fecha (eso ya lo cubre `CertificadoTlsCaducado`, arriba). Si
ves esta alerta, el problema es específicamente uno de estos tres:

1. **La cadena está incompleta.** `certs/tls.crt` no lleva los certificados
   intermedios, solo la hoja — la causa más frecuente. Corrección: §3.2 con
   el fichero de cadena completa que te dé tu CA.
2. **El nombre no coincide con `APP_URL`.** Revísalo con `openssl` (§2,
   campo `subject`): si cambió el dominio del hotel y el certificado sigue
   siendo del antiguo, hay que emitir uno nuevo con el nombre correcto.
3. **El certificado fue sustituido por uno de otro emisor**, uno que el
   navegador no reconoce como de confianza — comprueba `issuer` con la misma
   orden de `openssl` del §2 y confírmalo contra quien tú sabes que emitió tu
   certificado. Si no coincide y tú no lo cambiaste, trátalo como posible
   manipulación del canal (§1 y §6 de
   [`brecha-de-seguridad.md`](brecha-de-seguridad.md): preserva evidencia
   antes de sustituir nada).

**Si la instalación declaró `TLS_ALLOW_SELF_SIGNED=true` a propósito**
(entornos de prueba, nunca producción real — `docs/cliente/instalacion.md`
§1.2), **esta alerta no puede dispararse**: `render-config.sh` de
`infra/observability/prometheus/` omite el job de la sonda verificada por
completo cuando esa variable vale `true`, porque un certificado autofirmado
elegido a conciencia jamás pasaría esta verificación — sondearlo igual solo
produciría una alerta permanente por un hecho ya conocido y aceptado. Si ves
`CertificadoTlsNoVerificable` en una instalación así, revisa primero si
`TLS_ALLOW_SELF_SIGNED` sigue puesta a `true` en el `.env` de verdad (la sonda
lee la variable del entorno del contenedor de Prometheus, no del panel).

**Diagnóstico exacto**, sin esperar a que Prometheus vuelva a sondear:

```bash
echo | openssl s_client -connect fichaje.tuhotel.local:443 \
    -servername fichaje.tuhotel.local -showcerts 2>/dev/null \
  | openssl x509 -noout -subject -issuer -enddate

# Verificacion completa de la cadena, como la hace un navegador:
echo | openssl s_client -connect fichaje.tuhotel.local:443 \
    -servername fichaje.tuhotel.local -verify_return_error 2>&1 \
  | grep -E 'Verify return code|subject|issuer'
```

`Verify return code: 0 (ok)` es lo que tiene que salir. Cualquier otro código
(`unable to get local issuer certificate`, `certificate has expired`,
`hostname mismatch`) dice exactamente cuál de los tres casos de arriba es.

Una vez corregido, sigue el mismo §3.2 para colocar el certificado (o la
cadena completa) y recargar Nginx sin caída, y confirma con la misma orden
`openssl -verify_return_error` de arriba antes de dar el incidente por
cerrado — no basta con que `CertificadoTlsNoVerificable` deje de estar activa
en Prometheus (`for: 15m`, hasta 15 minutos de margen), esta comprobación es
inmediata.

---

## 4. Confirmar que la alerta vuelve a estar por encima de 21 días

```bash
curl -s 'http://127.0.0.1:9090/api/v1/query?query=(probe_ssl_earliest_cert_expiry-time())/86400' \
  | jq -r '.data.result[0].value[1]'
```

El número es días hasta la caducidad. Tiene que quedar por encima de 21 antes
de dar el incidente por cerrado — si acabas de renovar por, por ejemplo, 90
días, verifícalo aquí en vez de dar por hecho que el certificado nuevo se
sirvió: un `nginx -s reload` con la ruta equivocada en `TLS_CERT_DIR` deja el
certificado antiguo sirviéndose sin ningún error visible.

---

## 5. Qué no hacer

- **No enseñes al personal a aceptar el aviso de sitio no seguro.** El día
  que alguien no lo acepte, ese quiosco deja de fichar, y mientras tanto el
  canal por el que viajan los fichajes no lo protege nadie de verdad.
- **No pongas `TLS_ALLOW_SELF_SIGNED=true` en producción "mientras llega el
  certificado nuevo".** El instalador lo rechaza a propósito y reactivarlo a
  mano dejaría el mismo problema que intentas resolver, solo que silenciado.
- **No cambies el propietario de un `TLS_CERT_DIR` compartido con otro
  servicio del hotel.** Rompes ese otro servicio. Copia el certificado a un
  directorio propio de KronoQR (§3.1).
- **No reinicies el contenedor de Nginx entero (`docker compose restart
  nginx` o `up -d --force-recreate`) cuando basta con recargar.** `nginx -s
  reload` hace lo mismo sin la breve interrupción de un reinicio completo.

---

## 6. Escalado

| Situación | A quién | En cuánto |
| --- | --- | --- |
| Aviso a 21 días, certificado propio o de CA interna | IT del cliente: iniciar el trámite de renovación | Esta semana |
| Certificado ya caducado | IT del cliente | Inmediato: el fichaje está afectado en todos los quioscos |
| `CertificadoTlsNoVerificable`: cadena o nombre incorrectos (§3.4, casos 1-2) | IT del cliente | Inmediato: quien accede por `APP_URL` desde fuera ya ve el aviso del navegador |
| `CertificadoTlsNoVerificable`: emisor que no reconoces (§3.4, caso 3) | Responsable de seguridad, antes de sustituir nada | Inmediato — posible manipulación del canal |
| El nuevo certificado sigue sin aceptarse tras §3.3 | Soporte del fabricante, con el paquete de diagnóstico | El mismo día |
| El certificado lo gestiona un tercero (proveedor de red, de hosting) y no responde | IT del cliente: escalar con ese proveedor, y valorar un certificado propio mientras tanto | Según el margen restante hasta la caducidad |

**Relacionados:** [`quiosco-no-responde.md`](quiosco-no-responde.md) ·
[`cola-offline-atascada.md`](cola-offline-atascada.md) ·
[`../cliente/instalacion.md`](../cliente/instalacion.md) §1.2, §5 y §6 ·
[`ataque-a-credenciales.md`](ataque-a-credenciales.md) (mismo mecanismo de
recarga sin caída de Nginx).
