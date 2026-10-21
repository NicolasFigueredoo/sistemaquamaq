import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import '../css/app.css'
import '../css/extras.css'

createInertiaApp({
    title: (title) => (title ? `${title} · IVESS CONECTA 4.0` : 'IVESS CONECTA 4.0'),
    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.vue', { eager: true })
        return pages[`./pages/${name}.vue`]
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el)
    },
    progress: { color: '#0b7fba', delay: 400, showSpinner: false },
})
