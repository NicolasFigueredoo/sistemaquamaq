// Nodo "function": se dispara cuando el PLC indica fin de ciclo CIP (completo o interrumpido).
// Las etapas no cumplidas van en null: el panel las marca "No cumplido" y el ciclo queda "Incompleto".

const SERIAL = 'EM300-0033';
const r = msg.payload; // ej. { equipo: 1, ciclo: 412, t1: 15, t2: 10, t3: 0, t4: 8, ok1: 1, ok2: 1, ok3: 0, ok4: 1 }

msg.topic = `ivess/${SERIAL}/cip`;
msg.payload = {
    cycle_id: `${r.equipo === 1 ? 'LAV' : 'LLE'}-${r.ciclo}`, // único por equipo: evita duplicados si se reenvía
    equipment: r.equipo === 1 ? 'lavadora' : 'llenadora',
    finished_at: Date.now(),
    stages: {
        saneado: r.ok1 ? r.t1 : null,
        enjuague: r.ok2 ? r.t2 : null,
        desinfeccion: r.ok3 ? r.t3 : null,
        enjuague_final: r.ok4 ? r.t4 : null,
    },
};
msg.qos = 1;
return msg;
