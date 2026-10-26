// Nodo "function" del EM300 (FlowPLC / Node-RED): arma el mensaje de telemetría.
// Antes de este nodo: la lectura Modbus del PLC (ajustá nombres y escalas a tus registros).
// Después: nodo "Cache queue" (reenvío tras corte, ver demo del fabricante) → nodo MQTT out (QoS 1).

const SERIAL = 'EM300-0033';   // el mismo serial dado de alta con: php artisan telemetry:gateway
const r = msg.payload;          // ej. { ozone: 55, temp: 624, cond: 241, t_eff: 121, cloro: 1 }

msg.topic = `ivess/${SERIAL}/telemetry`;
msg.payload = {
    ts: Date.now(),                    // hora de la medición: importante para los datos reenviados tras un corte
    values: {
        ozone: r.ozone / 100,          // ppm
        temperature: r.temp / 10,      // °C
        alkaline: r.cond,              // µS/cm
        effective_time: r.t_eff,       // segundos
        chlorine: r.cloro ? 1 : 0,     // presencia
    },
};
msg.qos = 1;
return msg;
