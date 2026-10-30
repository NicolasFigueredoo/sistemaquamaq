<?php

return [

    /*
    | Zona horaria para mostrar fechas en el panel. La base guarda todo en UTC.
    */
    'display_timezone' => env('TELEMETRY_DISPLAY_TZ', 'America/Argentina/Buenos_Aires'),

    /*
    | Zona horaria que se asume cuando el gateway manda una fecha sin zona
    | (por ejemplo "2026-10-04 14:32:10"). Si manda epoch (segundos o ms) no se usa.
    */
    'gateway_timezone' => env('TELEMETRY_GATEWAY_TZ', 'America/Argentina/Buenos_Aires'),

    /*
    | Broker MQTT al que se suscribe `php artisan telemetry:listen`.
    | Topics esperados: {prefix}/{serial_gateway}/telemetry | cip | status
    */
    'mqtt' => [
        'host' => env('MQTT_HOST', '127.0.0.1'),
        'port' => (int) env('MQTT_PORT', 1883),
        'client_id' => env('MQTT_CLIENT_ID', 'ivess-ingestor'),
        'username' => env('MQTT_USERNAME') ?: null,
        'password' => env('MQTT_PASSWORD') ?: null,
        'tls' => (bool) env('MQTT_TLS', false),
        'tls_ca_file' => env('MQTT_TLS_CA_FILE') ?: null,
        'topic_prefix' => env('MQTT_TOPIC_PREFIX', 'ivess'),
        'qos' => (int) env('MQTT_QOS', 1),
        // Sesión persistente: si el ingestor se cae, el broker le guarda los mensajes QoS 1.
        'clean_session' => filter_var(env('MQTT_CLEAN_SESSION', false), FILTER_VALIDATE_BOOL),
        'keep_alive' => (int) env('MQTT_KEEP_ALIVE', 30),
    ],

    /*
    | API REST de Exemys M2M (m2m.exemys.com). Basic Auth con un usuario del acceso web.
    | El unit_id es el ID interno de la plataforma (el combo "sitio" del portal), no el ID del GRD.
    */
    'exemys' => [
        'base_url' => env('EXEMYS_BASE_URL', 'https://m2m.exemys.com/api/v1'),
        'username' => env('EXEMYS_USERNAME', ''),
        'password' => env('EXEMYS_PASSWORD', ''),
        'unit_id' => (int) env('EXEMYS_UNIT_ID', 0),
        'timeout' => (int) env('EXEMYS_TIMEOUT', 20),
        // El servidor no envía el certificado intermedio y OpenSSL no lo descarga solo:
        // se usa un bundle propio con la cadena de Let's Encrypt (ISRG Root X1 -> Root YR -> YR2).
        'ca_bundle' => env('EXEMYS_CA_BUNDLE') ?: resource_path('certs/exemys-ca.pem'),

        // Planta que se alimenta de esta unidad (`php artisan exemys:sync` la crea si no existe).
        'plant_name' => env('EXEMYS_PLANT_NAME', 'Quamaq · Prueba Exemys'),
        'plant_location' => env('EXEMYS_PLANT_LOCATION', 'GRD210'),

        // Canal de Exemys (ioID) => key de la variable del panel. Los canales sin mapear se ignoran.
        'io_map' => [
            53292 => 'temperature', // PV Controlador 1
        ],

        // Marcas CIP del PLC (1 = etapa corriendo, 0 = terminada): ioID => 'equipo.etapa'.
        // Canales I1–I8 del módulo = M800–M807 del PLC FATEK. Completar con los ioID que da `exemys:fetch`.
        'cip_map' => [
            // 00000 => 'lavadora.saneado',        // I1 · M800
            // 00000 => 'lavadora.enjuague',       // I2 · M801
            // 00000 => 'lavadora.desinfeccion',   // I3 · M802
            // 00000 => 'lavadora.enjuague_final', // I4 · M803
            // 00000 => 'llenadora.saneado',       // I5 · M804
            // 00000 => 'llenadora.enjuague',      // I6 · M805
            // 00000 => 'llenadora.desinfeccion',  // I7 · M806
            // 00000 => 'llenadora.enjuague_final',// I8 · M807
        ],

        // Días de histórico a traer la primera vez (después solo trae lo nuevo).
        'backfill_days' => (int) env('EXEMYS_BACKFILL_DAYS', 30),
    ],

    'retention' => [
        'raw_days' => (int) env('TELEMETRY_RAW_DAYS', 120),     // lecturas crudas
        'hourly_days' => (int) env('TELEMETRY_HOURLY_DAYS', 800), // promedios horarios
        'inbox_days' => (int) env('TELEMETRY_INBOX_DAYS', 30),  // mensajes MQTT crudos
    ],

    'cip' => [
        // Estado de la planta cuando un CIP está vencido: 'alarma' o 'atencion'.
        'expired_plant_status' => env('TELEMETRY_CIP_EXPIRED_STATUS', 'alarma'),
        // Si un equipo nunca informó un CIP, ¿pone la planta en atención?
        'missing_is_attention' => filter_var(env('TELEMETRY_CIP_MISSING_ATTENTION', false), FILTER_VALIDATE_BOOL),
        // Un ciclo armado desde las marcas del PLC sin ninguna etapa activa durante estos minutos se cierra incompleto.
        'stale_minutes' => (int) env('TELEMETRY_CIP_STALE_MINUTES', 120),
    ],

    'panel' => [
        'chart_days' => 30,
        'events_days' => 30,
        'cip_history_months' => 13,
        'poll_seconds' => (int) env('TELEMETRY_POLL_SECONDS', 30),
    ],

    // Token del instalador web /instalar (dejarlo vacío una vez instalado).
    'install_token' => (string) env('INSTALL_TOKEN', ''),


    // Tolerancia para relojes de gateway adelantados: más allá de esto se usa la hora de llegada.
    'max_future_skew_seconds' => 300,
];
