<?php
session_start();
require 'config/database.php';
require 'config/constants.php';

$error = '';

// Get system name from database
$systemName = getSystemSetting('system_name', 'Container Management');

// Get the latest background image from assets folder
$background_path = '';
$assets_folder = 'assets/';
if (is_dir($assets_folder)) {
    $files = array_diff(scandir($assets_folder, SCANDIR_SORT_DESCENDING), array('.', '..'));
    foreach ($files as $file) {
        if (preg_match('/\.(png|jpg|jpeg|gif)$/i', $file)) {
            $background_path = $assets_folder . $file;
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $identifier = trim($_POST['email']); // Can be email or name
    $password   = trim($_POST['password']);
    $role       = trim($_POST['role']); // Get selected role

    if ($identifier === '' || $password === '') {
        $error = "Email/Username and password are required";
    } else {

        $stmt = $conn->prepare(
            "SELECT id, role FROM users 
             WHERE (email = ? OR name = ?) AND password = ? AND role = ? AND status = 1 
             LIMIT 1"
        );
        $stmt->bind_param("ssss", $identifier, $identifier, $password, $role);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($user = $result->fetch_assoc()) {

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role']    = $user['role'];

            header("Location: dashboard.php");
            exit;

        } else {
            $error = "Invalid credentials or unauthorized role";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Login | Container Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /**
         * Login Page Responsive Styles
         * Uses fluid clamp() sizing for smooth, continuous scaling at any zoom level.
         */

        /* Mobile: Fix vertical centering & remove scrolling */
        @media (max-width: 767px) {
            body {
                height: 100vh !important;
                height: 100dvh !important;
                min-height: unset !important;
                overflow: hidden !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
            }

            /* Smaller card on mobile */
            .bg-white.rounded-lg.shadow-2xl {
                width: min(340px, calc(100vw - 32px)) !important;
                padding: 20px 16px !important;
            }

            /* Smaller text throughout */
            .bg-white.rounded-lg.shadow-2xl h1 {
                font-size: 1.5rem !important;
            }

            .bg-white.rounded-lg.shadow-2xl p,
            .bg-white.rounded-lg.shadow-2xl label span,
            .bg-white.rounded-lg.shadow-2xl .text-xs {
                font-size: 11px !important;
            }

            /* Smaller inputs */
            input[type="text"],
            input[type="password"] {
                font-size: 12px !important;
                padding: 9px 36px 9px 10px !important;
                min-height: unset !important;
            }

            /* Smaller button */
            button[type="submit"] {
                font-size: 13px !important;
                padding: 10px !important;
                min-height: unset !important;
            }

            /* Tighten spacing */
            .bg-white.rounded-lg.shadow-2xl .mb-6 {
                margin-bottom: 14px !important;
            }

            .bg-white.rounded-lg.shadow-2xl .mb-4 {
                margin-bottom: 10px !important;
            }
        }

        /* Fluid Form Sizing — scales smoothly at every zoom level */
        .bg-white.rounded-lg.shadow-2xl {
            width: min(420px, calc(100vw - 40px)) !important;
            max-width: unset !important;
            padding: clamp(16px, 3.5vw, 40px) clamp(12px, 3vw, 34px) !important;
            margin-left: auto !important;
            margin-right: auto !important;
            box-sizing: border-box !important;
        }

        input[type="text"],
        input[type="password"] {
            font-size: clamp(13px, 1.4vw, 17px) !important;
            min-height: 44px !important;
        }

        button[type="submit"] {
            font-size: clamp(14px, 1.4vw, 18px) !important;
            min-height: 44px !important;
        }

        /* Landscape Orientation (Mobile) */
        @media (orientation: landscape) and (max-height: 600px) {
            .bg-white.rounded-lg.shadow-2xl {
                max-width: 90vw !important;
                padding: 16px clamp(12px, 3vw, 20px) !important;
            }

            .mb-6 {
                margin-bottom: 12px !important;
            }
        }

        /* Touch Device Optimizations */
        @media (hover: none) and (pointer: coarse) {
            input[type="radio"] {
                min-width: 20px !important;
                min-height: 20px !important;
            }

            label {
                min-height: 44px !important;
                display: flex !important;
                align-items: center !important;
            }
        }

        /* Accessibility */
        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }

        /* High DPI Optimization */
        @media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
            body {
                -webkit-font-smoothing: antialiased;
                -moz-osx-font-smoothing: grayscale;
            }
        }
    </style>
</head>

<body class="min-h-screen flex items-center justify-center bg-gray-100" style="background: linear-gradient(rgba(0, 0, 0, 0.5), rgba(0, 0, 0, 0.5)), url('<?php echo htmlspecialchars($background_path); ?>') center/cover no-repeat fixed;">

    <div class="bg-white rounded-lg shadow-2xl w-full mx-auto">

        <!-- System Name Section -->
        <div class="flex justify-center mb-6">
            <h1 class="text-4xl font-black tracking-tight text-blue-700 text-center" style="letter-spacing: -0.02em; text-shadow: 0 2px 10px rgba(37, 99, 235, 0.3); font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; line-height: 1.3; padding-bottom: 4px;">
                <?php echo htmlspecialchars($systemName); ?>
            </h1>
        </div>

        <!-- Role Selection -->
        <div class="mb-6">
            <p class="text-gray-700 font-semibold mb-3 text-center text-sm">Select Role</p>
            <div class="flex gap-4 justify-center flex-wrap">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input
                        type="radio"
                        name="role_select"
                        value="admin"
                        checked
                        onchange="document.getElementById('role_input').value = this.value"
                        class="accent-blue-800"
                    >
                    <span class="text-gray-600">Admin</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input
                        type="radio"
                        name="role_select"
                        value="partner"
                        onchange="document.getElementById('role_input').value = this.value"
                        class="accent-blue-800"
                    >
                    <span class="text-gray-600">Partner</span>
                </label>
            </div>
        </div>

        <!-- Error Message -->
        <?php if ($error): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-3 mb-4 rounded">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Login Form -->
        <form method="POST" id="loginForm">
            <input type="hidden" name="role" id="role_input" value="admin">

            <!-- Email/Username Field -->
            <div class="mb-4 relative">
                <input
                    type="text"
                    name="email"
                    id="email"
                    placeholder="Email or Username"
                    class="w-full px-3 py-3 pr-10 border border-gray-300 rounded-lg bg-blue-50 text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-800 focus:bg-white"
                    autocomplete="username"
                    required
                >
                <svg class="absolute right-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
            </div>

            <!-- Password Field -->
            <div class="mb-6 relative">
                <input
                    type="password"
                    name="password"
                    id="password"
                    placeholder="Password"
                    class="w-full px-3 py-3 pr-10 border border-gray-300 rounded-lg bg-blue-50 text-gray-900 focus:outline-none focus:ring-2 focus:ring-blue-800 focus:bg-white"
                    autocomplete="current-password"
                    required
                >
                <svg class="absolute right-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                </svg>
            </div>

            <!-- Submit Button -->
            <button
                type="submit"
                class="w-full bg-blue-700 hover:bg-blue-800 text-white font-semibold py-3 px-4 rounded-lg transition duration-200 shadow-md hover:shadow-lg"
            >
                Login
            </button>
        </form>

        <!-- Footer -->
        <div class="mt-6 pt-4 border-t border-gray-200 text-center">
            <p class="text-xs text-gray-500">For any query contact administrator</p>
        </div>

    </div>

<script>
    // Auto-focus on email field on page load
    window.addEventListener('load', function() {
        document.getElementById('email').focus();
    });
</script>

</body>
</html>
