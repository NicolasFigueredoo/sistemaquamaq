<!doctype html>
<html lang="es">
<body style="margin:0;background:#eef6fb;font-family:Segoe UI,Arial,sans-serif;color:#183651">
    <div style="max-width:480px;margin:0 auto;padding:28px 16px">
        <div style="background:#fff;border:1px solid #cfe0eb;border-radius:14px;padding:28px">
            <h1 style="font-size:18px;margin:0 0 16px">IVESS CONECTA 4.0</h1>
            <p style="margin:0 0 12px">Hola {{ $user->name }} ({{ $user->username }}):</p>
            <p style="margin:0 0 18px">Usá este código para {{ $action }}:</p>
            <p style="font-size:32px;font-weight:800;letter-spacing:8px;text-align:center;margin:0 0 18px">{{ $code }}</p>
            <p style="margin:0;font-size:12px;color:#6d86a1">Vence en {{ $minutes }} minutos. Si no fuiste vos, ignorá este mensaje: nadie puede hacer el cambio sin este código.</p>
        </div>
    </div>
</body>
</html>
