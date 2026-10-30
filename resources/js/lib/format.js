// Formatos iguales a los de la maqueta: coma decimal, "dd/mm/aaaa · hh:mm".
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
        return `${p.day}/${p.month}/${p.year} · ${p.hour}:${p.minute}`
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

    const formatDuration = (seconds) => {
        const s = Math.max(0, Math.round(seconds || 0))
        if (s < 60) return `${s} s`
        const minutes = Math.round(s / 60)
        if (minutes < 60) return `${minutes} min`
        const h = Math.floor(minutes / 60)
        const m = minutes % 60
        return m ? `${h} h ${m} min` : `${h} h`
    }

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

    return { formatComma, formatDateTime, formatShort, formatClock, formatDuration, formatAgo }
}

export const statusLabels = { conforme: 'Conforme', atencion: 'Atención', sin_conexion: 'Sin conexión', alarma: 'Alarma' }
export const statusColors = { conforme: 'var(--green)', atencion: 'var(--yellow)', sin_conexion: 'var(--gray)', alarma: 'var(--red)' }
export const statusRank = { conforme: 0, atencion: 1, sin_conexion: 2, alarma: 3 }
export const variableColors = { ok: 'var(--green)', atencion: 'var(--yellow)', alarma: 'var(--red)' }
export const cipColors = { vigente: 'var(--green)', proximo: 'var(--yellow)', vencido: 'var(--red)', sin_registro: 'var(--gray)' }
