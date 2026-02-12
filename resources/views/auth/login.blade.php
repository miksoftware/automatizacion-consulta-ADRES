<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Iniciar Sesión - Sistema ADRES</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(15px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-600 via-blue-700 to-blue-900 min-h-screen flex items-center justify-center">

    <div class="w-full max-w-md px-4 fade-in">
        <!-- Logo / Branding -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-20 h-20 bg-white/20 backdrop-blur-sm rounded-2xl mb-4">
                <i class="fas fa-hospital-user text-4xl text-white"></i>
            </div>
            <h1 class="text-2xl font-bold text-white">Sistema de Consulta</h1>
            <p class="text-blue-200 text-sm mt-1">Sistema de Consulta Masiva  del Sistema de Seguridad Social</p>
        </div>

        <!-- Card de Login -->
        <div class="bg-white rounded-2xl shadow-2xl p-8">
            <h2 class="text-xl font-semibold text-gray-800 mb-6 text-center">Iniciar Sesión</h2>

            @if ($errors->any())
                <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-4">
                    <div class="flex items-center gap-2 text-red-700 text-sm">
                        <i class="fas fa-exclamation-circle"></i>
                        <span>{{ $errors->first() }}</span>
                    </div>
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}">
                @csrf

                <!-- Email -->
                <div class="mb-4">
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-envelope text-gray-400 mr-1"></i> Correo Electrónico
                    </label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                           class="w-full border border-gray-300 rounded-lg px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition"
                           placeholder="usuario@ejemplo.com">
                </div>

                <!-- Password -->
                <div class="mb-4">
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">
                        <i class="fas fa-lock text-gray-400 mr-1"></i> Contraseña
                    </label>
                    <div class="relative">
                        <input type="password" id="password" name="password" required
                               class="w-full border border-gray-300 rounded-lg px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition pr-10"
                               placeholder="••••••••">
                        <button type="button" onclick="togglePassword()" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            <i class="fas fa-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Recordarme -->
                <div class="flex items-center justify-between mb-6">
                    <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
                        <input type="checkbox" name="remember" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        Recordarme
                    </label>
                </div>

                <!-- Botón de Login -->
                <button type="submit"
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 rounded-lg transition flex items-center justify-center gap-2 text-sm">
                    <i class="fas fa-sign-in-alt"></i>
                    Ingresar
                </button>
            </form>
        </div>

        <p class="text-center text-blue-200 text-xs mt-6">
            &copy; {{ date('Y') }} Sistema de Consulta Masiva  del Sistema de Seguridad Social
        </p>
    </div>

    <script>
        function togglePassword() {
            const input = document.getElementById('password');
            const icon = document.getElementById('eyeIcon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }
    </script>
</body>
</html>
