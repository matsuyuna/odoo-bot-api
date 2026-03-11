<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitor WATI</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; color: #222; }
        h1, h2 { margin-bottom: 0.8rem; }
        .grid { display: grid; grid-template-columns: 1fr; gap: 1rem; }
        .card { border: 1px solid #ddd; border-radius: 8px; padding: 1rem; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #e5e5e5; padding: 0.5rem; text-align: left; }
        th { background: #f7f7f7; }
        .status { padding: 0.6rem; border-radius: 6px; margin-bottom: 1rem; }
        .ok { background: #ecfdf3; border: 1px solid #b7ebc6; }
        .err { background: #fff1f0; border: 1px solid #ffccc7; }
        input { padding: 0.5rem; width: 100%; margin-bottom: 0.5rem; }
        button { padding: 0.6rem 1rem; cursor: pointer; }
        pre { background: #fafafa; border: 1px solid #eee; padding: 1rem; overflow: auto; }
    </style>
</head>
<body>
    <h1>Testing y monitoreo de contactos WATI</h1>

    @if(session('status'))
        <div class="status ok">{{ session('status') }}</div>
    @endif

    @if(session('error') || $error)
        <div class="status err">{{ session('error') ?: $error }}</div>
    @endif

    <div class="grid">
        <section class="card">
            <h2>Insertar contacto en WATI</h2>
            <form method="POST" action="{{ route('wati.monitor.store') }}">
                @csrf
                <label>Teléfono</label>
                <input type="text" name="phone" placeholder="584244162964" required>

                <label>Nombre</label>
                <input type="text" name="name" placeholder="Contacto de prueba" required>

                <label>Producto más comprado</label>
                <input type="text" name="producto_mas_comprado" placeholder="Acetaminofén 500mg">

                <label>Último producto comprado</label>
                <input type="text" name="ultimo_producto_comprado" placeholder="Vitamina C 1g">

                <button type="submit">Enviar a WATI</button>
            </form>
            @if($errors->any())
                <div class="status err">
                    {{ $errors->first() }}
                </div>
            @endif
        </section>

        <section class="card">
            <h2>Lista acotada de contactos</h2>
            <form method="GET" action="{{ route('wati.monitor') }}">
                <label>Limit</label>
                <input type="number" name="limit" value="{{ $limit }}" min="1" max="100">
                <label>Página</label>
                <input type="number" name="page" value="{{ $page }}" min="1">
                <button type="submit">Actualizar lista</button>
            </form>

            <table>
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Teléfono</th>
                        <th>ID</th>
                        <th>Producto más comprado</th>
                        <th>Último producto comprado</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($contacts as $contact)
                        <tr>
                            <td>{{ $contact['name'] ?? $contact['fullName'] ?? '-' }}</td>
                            <td>{{ $contact['phone'] ?? $contact['waId'] ?? '-' }}</td>
                            <td>{{ $contact['id'] ?? '-' }}</td>
                            <td>{{ $contact['producto_mas_comprado'] ?: '-' }}</td>
                            <td>{{ $contact['ultimo_producto_comprado'] ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">Sin contactos para mostrar</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </section>

        @if(session('wati_response') || $raw)
            <section class="card">
                <h2>Respuesta técnica (debug)</h2>
                <pre>{{ json_encode(session('wati_response') ?: $raw, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
            </section>
        @endif
    </div>
</body>
</html>
