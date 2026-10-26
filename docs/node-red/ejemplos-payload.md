# Mensajes que entiende IVESS Conecta

Topic base: `ivess/{SERIAL}/...` (el serial es el que diste de alta con `php artisan telemetry:gateway`).

## Telemetría — `ivess/{SERIAL}/telemetry`

```json
{"ts": 1759600000000, "values": {"ozone": 0.55, "temperature": 62.4, "alkaline": 361, "effective_time": 121, "chlorine": 1}}
```

- `ts`: epoch en milisegundos o segundos, o texto ISO (`2026-10-04T14:32:10-03:00`). Sin zona se asume hora argentina.
- Se puede mandar un lote (por ejemplo al vaciar la cola tras un corte):
  `{"records": [{"ts": ..., "values": {...}}, {"ts": ..., "values": {...}}]}`
- También se acepta el formato plano: `{"ts": ..., "ozone": 0.55, "temperature": 62.4}`
- Variables desconocidas se ignoran. Lecturas repetidas (misma variable y misma hora) se descartan solas.

## Ciclo CIP — `ivess/{SERIAL}/cip`

```json
{"cycle_id": "LAV-412", "equipment": "lavadora", "finished_at": 1759600000000,
 "stages": {"saneado": 15, "enjuague": 10, "desinfeccion": null, "enjuague_final": 8}}
```

`equipment`: `lavadora` o `llenadora`. Etapa en `null` = no cumplida → ciclo incompleto.

## Presencia — `ivess/{SERIAL}/status`

`online` / `offline`. Configurarlo como **Last Will** del cliente MQTT del EM300 (mensaje `offline`, retain) y publicar `online` al conectar: así el panel marca "Sin conexión" al instante, sin esperar los 10 minutos.

## Alternativa HTTP (sin broker)

```
POST /api/ingest/telemetry
POST /api/ingest/cip
Authorization: Bearer {TOKEN_DEL_GATEWAY}
Content-Type: application/json
```

Mismo JSON que por MQTT. El token se genera con `php artisan telemetry:gateway {planta} {serial}`.
