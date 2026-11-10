// Formatos del panel: coma decimal, "dd/mm/aaaa · hh:mm:ss".
import { usePage } from '@inertiajs/vue3'

const formatters = new Map()

function partsFor(ms, timeZone) {
    if (!formatters.has(timeZone)) {
        formatters.set(timeZone, new Intl.DateTimeFormat('es-AR', {
            timeZone, day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23',
        }))
    }
    const out = {}
    for (const p of formatters.get(timeZone).formatToParts(new Date(ms))) out[p.type] = p.value
    return out
}

export function useFormat() {
    const page = usePage()
    const tz = () => page.props.panel?.timezone || 'America/Argentina/Buenos_Aires'

    const formatComma = (value, decimals) => Number(value).toFixed(decimals).replace('.', ',')

    const formatDateTime = (ms) => {
        if (ms == null) return '—'
        const p = partsFor(ms, tz())
        return `${p.day}/${p.month}/${p.year} · ${p.hour}:${p.minute}:${p.second}`
    }

    const formatShort = (ms) => {
        if (ms == null) return '—'
        const p = partsFor(ms, tz())
        return `${p.day}/${p.month} · ${p.hour}:${p.minute}`
    }

    const formatClock = (ms) => {
        const p = partsFor(ms, tz())
        return `${p.hour}:${p.minute}:${p.second}`
    }

    // Duración exacta: "45 s", "3 min 05 s", "1 h 02 min 10 s".
    const formatDuration = (seconds) => {
        const total = Math.max(0, Math.round(seconds || 0))
        const h = Math.floor(total / 3600)
        const m = Math.floor((total % 3600) / 60)
        const s = String(total % 60).padStart(2, '0')
        if (h) return `${h} h ${String(m).padStart(2, '0')} min ${s} s`
        if (m) return `${m} min ${s} s`
        return `${total} s`
    }

    // Solo segundos: "185 s", "1.200 s".
    const formatSeconds = (seconds) => `${Math.max(0, Math.round(seconds || 0)).toLocaleString('es-AR')} s`

    const formatAgo = (ms, now) => {
        if (ms == null) return 'Sin datos recibidos'
        const s = Math.max(0, Math.round((now - ms) / 1000))
        if (s < 60) return `hace ${s} ${s === 1 ? 'segundo' : 'segundos'}`
        const m = Math.floor(s / 60)
        if (m < 60) return `hace ${m} ${m === 1 ? 'minuto' : 'minutos'}`
        const h = Math.floor(m / 60)
        if (h < 24) return `hace ${h} ${h === 1 ? 'hora' : 'horas'}`
        return `el ${formatDateTime(ms)}`
    }

    return { formatComma, formatDateTime, formatShort, formatClock, formatDuration, formatSeconds, formatAgo }
}

export const statusLabels = { conforme: 'Conforme', atencion: 'Atención', sin_conexion: 'Sin conexión', alarma: 'Alarma' }
export const statusColors = { conforme: 'var(--green)', atencion: 'var(--yellow)', sin_conexion: 'var(--gray)', alarma: 'var(--red)' }
export const statusRank = { conforme: 0, atencion: 1, sin_conexion: 2, alarma: 3 }
export const variableColors = { ok: 'var(--green)', atencion: 'var(--yellow)', alarma: 'var(--red)' }
export const cipColors = { vigente: 'var(--green)', proximo: 'var(--yellow)', vencido: 'var(--red)', sin_registro: 'var(--gray)' }
