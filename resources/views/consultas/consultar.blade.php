<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Consultar Cédula - Sistema ADRES</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .fade-in { animation: fadeIn 0.3s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-5xl">
        
        <!-- Header con menú -->
        <!-- Header con menú -->
        <div class="bg-gradient-to-r from-blue-600 to-blue-800 rounded-lg shadow-lg p-6 mb-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold flex items-center gap-3">
                        <i class="fas fa-hospital-user"></i>
                        Sistema de Consulta Masiva del Sistema de Seguridad Social
                    </h1>
                    <p class="text-blue-100 mt-1">Consulta información de afiliación al sistema de salud colombiano</p>
                </div>
                <div class="flex items-center gap-3">
                    <span class="text-blue-200 text-sm"><i class="fas fa-user mr-1"></i>{{ Auth::user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}" class="inline">
                        @csrf
                        <button type="submit" class="text-blue-200 hover:text-white text-sm transition" title="Cerrar sesión">
                            <i class="fas fa-sign-out-alt"></i>
                        </button>
                    </form>
                </div>
            </div>
            <!-- Menú de navegación -->
            <div class="flex gap-4 mt-4 border-t border-blue-500 pt-3">
                <a href="{{ route('consultas.index') }}" class="text-blue-200 hover:text-white transition flex items-center gap-2 text-sm font-medium px-3 py-1 rounded hover:bg-blue-700">
                    <i class="fas fa-upload"></i> Consulta Masiva
                </a>
                <a href="{{ route('consultas.consultar') }}" class="text-white bg-blue-700 transition flex items-center gap-2 text-sm font-medium px-3 py-1 rounded">
                    <i class="fas fa-search"></i> Consultar Cédula
                </a>
                @if(Auth::user()->is_admin)
                <a href="{{ route('usuarios.index') }}" class="text-blue-200 hover:text-white transition flex items-center gap-2 text-sm font-medium px-3 py-1 rounded hover:bg-blue-700">
                    <i class="fas fa-users-cog"></i> Usuarios
                </a>
                @endif
            </div>
        </div>

        <!-- Buscador -->
        <div class="bg-white rounded-lg shadow-lg p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center gap-2">
                <i class="fas fa-id-card text-blue-600"></i>
                Consultar Historial de Cédula
            </h2>

            <div class="flex gap-3">
                <div class="relative flex-1">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"><i class="fas fa-hashtag"></i></span>
                    <input type="text" id="inputCedula" placeholder="Ingrese número de cédula..."
                           class="w-full border border-gray-300 rounded-lg pl-10 pr-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                           onkeydown="if(event.key==='Enter') buscarCedula()" autofocus>
                </div>
                <button onclick="buscarCedula()" id="btnBuscar"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-6 py-3 rounded-lg transition flex items-center gap-2 text-sm">
                    <i class="fas fa-search"></i>
                    Buscar
                </button>
            </div>
        </div>

        <!-- Resultados -->
        <div id="resultadoContainer" class="hidden fade-in">
            <!-- Se llena dinámicamente -->
        </div>

        <!-- Estado inicial -->
        <div id="estadoInicial" class="bg-white rounded-lg shadow-lg p-12 text-center text-gray-400">
            <i class="fas fa-search text-5xl mb-4"></i>
            <p class="text-lg">Ingresa un número de cédula para ver su historial de consultas</p>
            <p class="text-sm mt-2">Se mostrarán todas las veces que ha sido consultada con su información</p>
        </div>

        <!-- Footer -->
        <div class="text-center text-gray-500 text-sm mt-6">
            <p>Sistema de Consulta Masiva  del Sistema de Seguridad Social</p>
        </div>
    </div>

    <script>
        async function buscarCedula() {
            const input = document.getElementById('inputCedula');
            const cedula = input.value.trim();
            const container = document.getElementById('resultadoContainer');
            const inicial = document.getElementById('estadoInicial');
            const btn = document.getElementById('btnBuscar');

            if (!cedula || cedula.length < 3) {
                alert('Ingrese un número de cédula válido (mínimo 3 dígitos).');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Buscando...';
            inicial.classList.add('hidden');
            container.classList.remove('hidden');
            container.innerHTML = '<div class="bg-white rounded-lg shadow-lg p-8 text-center text-gray-400"><i class="fas fa-spinner fa-spin text-2xl mb-3"></i><p>Buscando registros...</p></div>';

            try {
                const response = await fetch(`/buscar-cedula?cedula=${encodeURIComponent(cedula)}`);
                const data = await response.json();

                if (!data.ok || data.total_consultas === 0) {
                    container.innerHTML = `
                        <div class="bg-white rounded-lg shadow-lg p-12 text-center">
                            <div class="text-gray-400 mb-4">
                                <i class="fas fa-user-slash text-5xl"></i>
                            </div>
                            <p class="text-lg text-gray-600">No se encontraron registros</p>
                            <p class="text-sm text-gray-400 mt-2">La cédula <strong>${cedula}</strong> no ha sido consultada previamente.</p>
                        </div>`;
                    return;
                }

                // Header con resumen
                let html = `
                    <div class="bg-white rounded-lg shadow-lg p-6 mb-4">
                        <div class="flex items-center justify-between flex-wrap gap-3">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-user text-blue-600 text-xl"></i>
                                </div>
                                <div>
                                    <h3 class="text-lg font-bold text-gray-800">Cédula: ${data.cedula}</h3>
                                    <p class="text-sm text-gray-500">${data.total_consultas} registro(s) encontrado(s)</p>
                                </div>
                            </div>`;

                // Mostrar nombre del último registro exitoso
                const ultimoExitoso = data.resultados.find(r => r.exitosa);
                if (ultimoExitoso) {
                    html += `
                            <div class="text-right">
                                <p class="text-sm text-gray-500">Último dato registrado</p>
                                <p class="font-bold text-gray-800">${ultimoExitoso.nombres || ''} ${ultimoExitoso.apellidos || ''}</p>
                                <p class="text-sm text-blue-600">${ultimoExitoso.entidad_eps || '—'}</p>
                            </div>`;
                }

                html += `
                        </div>
                    </div>`;

                // Timeline de registros
                html += `<div class="space-y-4">`;

                data.resultados.forEach((r, idx) => {
                    const esExitosa = r.exitosa;
                    const borderLeft = esExitosa ? 'border-l-4 border-l-green-500' : 'border-l-4 border-l-red-400';
                    const iconColor = esExitosa ? 'text-green-500' : 'text-red-400';
                    const icon = esExitosa ? 'fa-check-circle' : 'fa-times-circle';
                    const badgeColor = esExitosa ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700';
                    const badgeText = esExitosa ? 'Consulta exitosa' : 'Con error';

                    html += `
                        <div class="bg-white rounded-lg shadow ${borderLeft} p-5 fade-in">
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center gap-2">
                                    <i class="fas ${icon} ${iconColor}"></i>
                                    <span class="font-semibold text-gray-700">Registro #${data.total_consultas - idx}</span>
                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full ${badgeColor}">${badgeText}</span>
                                </div>
                                <div class="text-right text-xs text-gray-500">
                                    <div><i class="fas fa-calendar-alt mr-1"></i>${r.consultado_en}</div>
                                    <div class="text-gray-400">Archivo: ${r.archivo_origen}</div>
                                </div>
                            </div>`;

                    if (esExitosa) {
                        html += `
                            <div class="grid grid-cols-2 md:grid-cols-3 gap-x-6 gap-y-2 text-sm">
                                <div>
                                    <span class="text-gray-400 text-xs uppercase">Nombres</span>
                                    <p class="font-medium text-gray-800">${r.nombres || '—'}</p>
                                </div>
                                <div>
                                    <span class="text-gray-400 text-xs uppercase">Apellidos</span>
                                    <p class="font-medium text-gray-800">${r.apellidos || '—'}</p>
                                </div>
                                <div>
                                    <span class="text-gray-400 text-xs uppercase">Tipo Documento</span>
                                    <p class="font-medium text-gray-800">${r.tipo_documento || '—'}</p>
                                </div>
                                <div>
                                    <span class="text-gray-400 text-xs uppercase">Fecha Nacimiento</span>
                                    <p class="font-medium text-gray-800">${r.fecha_nacimiento || '—'}</p>
                                </div>
                                <div>
                                    <span class="text-gray-400 text-xs uppercase">Departamento</span>
                                    <p class="font-medium text-gray-800">${r.departamento || '—'}</p>
                                </div>
                                <div>
                                    <span class="text-gray-400 text-xs uppercase">Municipio</span>
                                    <p class="font-medium text-gray-800">${r.municipio || '—'}</p>
                                </div>
                                <div>
                                    <span class="text-gray-400 text-xs uppercase">EPS</span>
                                    <p class="font-bold text-blue-700">${r.entidad_eps || '—'}</p>
                                </div>
                                <div>
                                    <span class="text-gray-400 text-xs uppercase">Régimen</span>
                                    <p class="font-medium text-gray-800">${r.regimen || '—'}</p>
                                </div>
                                <div>
                                    <span class="text-gray-400 text-xs uppercase">Estado Afiliación</span>
                                    <p class="font-medium text-gray-800">${r.estado_afiliacion || '—'}</p>
                                </div>
                                <div>
                                    <span class="text-gray-400 text-xs uppercase">Tipo Afiliado</span>
                                    <p class="font-medium text-gray-800">${r.tipo_afiliado || '—'}</p>
                                </div>
                                <div>
                                    <span class="text-gray-400 text-xs uppercase">Fecha Afiliación</span>
                                    <p class="font-medium text-gray-800">${r.fecha_afiliacion || '—'}</p>
                                </div>
                                <div>
                                    <span class="text-gray-400 text-xs uppercase">Fecha Finalización</span>
                                    <p class="font-medium text-gray-800">${r.fecha_finalizacion || '—'}</p>
                                </div>
                            </div>`;
                    } else {
                        html += `
                            <div class="bg-red-50 rounded p-3 text-sm text-red-600">
                                <i class="fas fa-exclamation-circle mr-1"></i>${r.error || 'Error desconocido'}
                            </div>`;
                    }

                    html += `</div>`;
                });

                html += `</div>`;
                container.innerHTML = html;

            } catch (err) {
                container.innerHTML = `
                    <div class="bg-white rounded-lg shadow-lg p-8 text-center">
                        <div class="text-red-400 mb-3"><i class="fas fa-exclamation-triangle text-3xl"></i></div>
                        <p class="text-red-600">Error de conexión: ${err.message}</p>
                    </div>`;
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-search"></i> Buscar';
            }
        }
    </script>
</body>
</html>
