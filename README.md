# IVESS Conecta 4.0

Panel de telemetría para las plantas de IVESS. Los gateways EM300 publican por MQTT, un proceso de Laravel guarda los datos en MySQL, calcula alarmas, conexión y vencimientos CIP, y el panel (Vue + Inertia) los muestra con el diseño de la maqueta `Panel_Interactivo_290926.html`.

```
PLC ──Modbus──▶ EM300 (Node-RED) ──MQTT/TLS──▶ Mosquitto ──▶ php artisan telemetry:listen ──▶ MySQL ◀── Panel (Laravel + Vue)
                       └──────────── o HTTP POST /api/ingest (alternativa sin broker) ─────────────┘
```

**Stack:** Laravel 12 · PHP 8.2+ · Inertia 3 + Vue 3 · Vite · MySQL 8 / MariaDB 10.6+ · php-mqtt/client.

---

## 1. Levantarlo en tu PC

Necesitás PHP 8.2+ (con `pdo_mysql` o `pdo_sqlite`), Composer, Node 20+ y MySQL (o SQLite). Sirve el PHP de XAMPP.

```bash
composer install
cp .env.example .env          # en Windows: copy .env.example .env
php artisan key:generate
```

Creá la base vacía `ivess_conecta` (utf8mb4) y revisá `DB_USERNAME` / `DB_PASSWORD` en `.env`. Después:

```bash
php artisan migrate --seed    # tablas + demo con las 43 plantas de la maqueta
npm install
composer dev                  # servidor + Vite + scheduler + simulador, todo junto (Ctrl+C para cortar)
```

Abrí **http://localhost:8000**.

| Usuario | Contraseña | Rol |
|---|---|---|
| `admin` | `IVESS40` | Administrador: ve todo, configura alarmas, escribe notas |
| `auditor` | `Auditor40` | Auditor: ve todo y escribe notas, no configura |
| `jumillano` | `Jumillano40` | Asociado: solo su planta (El Jumillano), lectura |

Con `composer dev` corriendo, el simulador manda datos cada 10 s: vas a ver plantas que entran en atención, pasan a alarma a los 5 minutos y se normalizan, igual que pasaría con los equipos reales. El panel se actualiza solo cada 30 s.

> Si preferís correr cada cosa por separado: `php artisan serve`, `npm run dev`, `php artisan schedule:work` y `php artisan telemetry:simulate --loop`.
> Para producción o sin Vite: `npm run build` y alcanza con `php artisan serve`.

### Probar con un broker MQTT real (en tu PC)

1. Instalá [Mosquitto](https://mosquitto.org/download/) y arrancalo con `docs/mosquitto/mosquitto.conf` (bloque de desarrollo).
2. En vez de `composer dev`, corré `php artisan serve`, `npm run dev`, `php artisan schedule:work` y `php artisan telemetry:listen` (cada uno en su terminal).
3. En otra terminal, simulá los gateways publicando al broker: `php artisan telemetry:simulate --mqtt --loop`

Ahí el camino es el mismo que en producción: broker → `telemetry:listen` → base → panel. Cada mensaje queda guardado en la tabla `mqtt_inbox` con su resultado.

---

## 2. Conectar un EM300

1. Dar de alta el gateway en la planta (el código de planta es el de la URL, ej. `el-jumillano-sa`):

   ```bash
   php artisan telemetry:gateway el-jumillano-sa EM300-0033
   ```

   Muestra los topics, el usuario MQTT y un token para la alternativa HTTP.

2. En el Node-RED del EM300 (FlowPLC):
   - Lectura Modbus del PLC → nodo function `docs/node-red/telemetria.js` → **Cache queue** (reenvío tras corte, demo del fabricante) → **MQTT out** QoS 1 al topic `ivess/EM300-0033/telemetry`.
   - Fin de ciclo CIP → `docs/node-red/cip.js` → MQTT out a `ivess/EM300-0033/cip`.
   - En el nodo MQTT: **Last Will** `offline` en `ivess/EM300-0033/status`.

   Formatos completos en `docs/node-red/ejemplos-payload.md`. El `ts` de cada lectura es la hora de medición: por eso los datos que se reenvían después de un corte caen en su hora correcta y los repetidos se descartan solos.

3. Alternativa sin broker: nodo **HTTP Request** con `POST https://tu-servidor/api/ingest/telemetry` y header `Authorization: Bearer <token>`.

### Usuarios

```bash
php artisan ivess:user maria --name="María Pérez" --role=auditor
php artisan ivess:user jumillano --role=asociado --plant=el-jumillano-sa
```

---

## 3. Cómo se calculan los estados

| Situación | Resultado |
|---|---|
| Variable fuera de setpoint | **Atención** de inmediato. No se registra evento todavía. |
| Sigue fuera durante `alarm_delay_s` (5 min por defecto, por planta y variable) | **Alarma**. Se registra el evento con inicio = cuando salió de parámetro. |
| Vuelve a parámetro | Se cierra el evento con su duración (tabla "Historial de eventos de alarma"). |
| Gateway sin datos más de 10 min (`offline_after_minutes`) o Last Will `offline` | **Sin conexión**. Se registra el corte en `connection_events`. |
| CIP: último ciclo **completo** + N días (a la misma hora) | Últimas 24 h → **Próximo a vencer** (planta en atención). Pasado → **Vencido** (planta en alarma, configurable con `TELEMETRY_CIP_EXPIRED_STATUS`). Los ciclos incompletos se muestran pero no renuevan la vigencia. |

El estado de la planta es el peor de todos. "Sin conexión" tapa los estados de variables (no hay valor confiable), salvo un CIP vencido.

Cuando el admin cambia un setpoint desde **Configurar alarmas**, el último valor se vuelve a juzgar contra el nuevo, y cada cambio queda en `setpoint_changes` con usuario y hora.

---

## 4. Producción

- **Servidor:** el mismo EC2 de Quamaq o uno nuevo. `APP_ENV=production`, `APP_DEBUG=false`, `npm run build`.
- **Base:** `php artisan migrate --force` y `php artisan db:seed --class=VariableSeeder --force`. **No** correr el seed completo: carga la demo.
- **Broker:** Mosquitto con TLS en 8883, usuario por gateway y ACL (`docs/mosquitto/`). El usuario MQTT de cada gateway es igual a su serial.
- **Procesos permanentes** (Supervisor en Linux):

  ```ini
  [program:ivess-mqtt]
  command=php /var/www/ivess-conecta/artisan telemetry:listen
  autostart=true
  autorestart=true
  user=www-data
  stdout_logfile=/var/www/ivess-conecta/storage/logs/mqtt.log
  ```

- **Cron** (cada minuto): `* * * * * cd /var/www/ivess-conecta && php artisan schedule:run >> /dev/null 2>&1`
  Corre `telemetry:evaluate` (cortes y vencimientos, cada minuto) y `telemetry:prune` (limpieza diaria: crudos 120 días, mensajes MQTT 30 días; los promedios horarios quedan ~2 años).

Comandos útiles: `telemetry:reprocess` (reintenta mensajes con error, por ejemplo de un gateway dado de alta después) y `telemetry:evaluate` (fuerza el recálculo).

---

## 5. Tests

```bash
php artisan test
```

18 tests: ingesta, duplicados, promedios horarios, atención → alarma → normalización, lotes tras corte, lecturas atrasadas, cortes de conexión, CIP (vigente, próximo, incompleto, vencido), MQTT por topic, HTTP con token, permisos por rol, configuración de setpoints y notas, instalador web. Corren en SQLite en memoria; también se probaron contra MariaDB.

---

## 6. Dónde está cada cosa

```
app/Services/Telemetry/   ingesta y reglas: TelemetryIngestor, VariableEvaluator, PlantStatusService, CipService, PayloadParser
app/Services/Panel/       PlantPresenter: arma los datos de cada pantalla
app/Console/Commands/     telemetry:listen | evaluate | prune | simulate | gateway | reprocess, ivess:user
resources/js/pages/       Login, Network (red nacional), Plant (planta), Cip (trazabilidad)
resources/js/components/  TrendChart (gráfico de 30 días), ConfigModal
resources/css/app.css     CSS de la maqueta, sin cambios
config/telemetry.php      zona horaria, broker, retención, reglas CIP
database/seeders/         VariableSeeder (producción) y DemoSeeder (demo)
docs/                     Mosquitto y ejemplos de Node-RED para el EM300
```

### Diferencias con el diagrama de base de datos v1

- Nueva tabla `plant_variable_states`: el estado actual de cada variable (valor, desde cuándo está fuera, alarma abierta). Es lo que permite pasar de atención a alarma y cerrar el evento.
- `setpoint_changes` registra cualquier campo del modal (setpoints, periodicidad, tanque, producto), no solo variables.
- `alarm_events` no tiene `severity`: solo se registran alarmas, como dice la maqueta.
- `plant_settings` suma `cip_warning_hours` (24) y `gateways` suma `api_token_hash` para el ingreso por HTTP.
