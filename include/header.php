<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title><?= getSystemSetting('system_name', APP_NAME) ?></title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#2463eb",
                        "background-light": "#f6f6f8",
                        "background-dark": "#111621",
                    },
                    fontFamily: {
                        "display": ["Inter", "system-ui", "sans-serif"]
                    },
                    borderRadius: { "DEFAULT": "0.5rem", "lg": "1rem", "xl": "1.5rem", "full": "9999px" },
                },
            },
        }
    </script>
    <style>
        .glass-effect {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        .sparkline-gradient {
            background: linear-gradient(90deg, transparent, rgba(36, 99, 235, 0.1), transparent);
        }
        
        /* Prevent mobile sidebar flash on page load */
        @media (max-width: 767px) {
            #sidebar {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                z-index: 60 !important;
                height: 100vh !important;
                width: min(20rem, 84vw) !important;
                transform: translateX(-100%) !important;
                transition: transform 0.25s ease !important;
            }
            
            #sidebar.mobile-open {
                transform: translateX(0) !important;
            }
            
            #mobileSidebarOverlay {
                position: fixed !important;
                inset: 0 !important;
                z-index: 50 !important;
                background: rgba(15, 23, 42, 0.45) !important;
                opacity: 0 !important;
                pointer-events: none !important;
                transition: opacity 0.2s ease !important;
            }
            
            #mobileSidebarOverlay.open {
                opacity: 1 !important;
                pointer-events: auto !important;
            }
        }

        /* Desktop Sidebar Collapse Styles */
        @media (min-width: 768px) {
            #sidebar {
                transition: width 0.3s ease;
                overflow-x: hidden !important;
            }

            /* Prevent overflow during all states */
            #sidebar > div:first-child,
            #sidebar nav,
            #sidebar .px-4.pt-3.pb-4.mt-auto,
            #sidebar .p-4.mt-auto {
                overflow-x: hidden !important;
            }

            /* Smooth transitions for all text elements */
            .sidebar-label,
            .sidebar-brand-text,
            .sidebar-brand-subtitle,
            .sidebar-toggle-text {
                transition: opacity 0.25s ease 0.15s, width 0.25s ease;
            }

            /* Smooth transitions for logo container and glass effect */
            #sidebar > div:first-child {
                transition: padding 0.3s ease;
            }

            .glass-effect {
                transition: width 0.3s ease, height 0.3s ease, padding 0.3s ease, margin 0.3s ease, gap 0.3s ease, background 0.3s ease, box-shadow 0.3s ease, border-radius 0.3s ease;
            }

            /* Delay border appearance until width transition completes */
            #sidebar:not(.sidebar-collapsed) .glass-effect {
                transition: all 0.3s ease 0.15s;
            }

            /* Hide border instantly when collapsing */
            #sidebar.sidebar-collapsed .glass-effect {
                border-color: transparent !important;
            }

            .sidebar-link {
                transition: all 0.3s ease;
            }

            nav {
                transition: padding 0.3s ease;
            }

            .px-4.pt-3.pb-4.mt-auto,
            .p-4.mt-auto {
                transition: padding 0.3s ease;
            }

            #sidebar.sidebar-collapsed {
                width: 4.5rem !important;
            }

            #sidebar.sidebar-collapsed .sidebar-label,
            #sidebar.sidebar-collapsed .sidebar-brand-text,
            #sidebar.sidebar-collapsed .sidebar-brand-subtitle,
            #sidebar.sidebar-collapsed .sidebar-toggle-text {
                opacity: 0;
                width: 0;
                overflow: hidden;
                transition: opacity 0.15s ease, width 0.15s ease;
            }

            /* Logo section centering */
            #sidebar.sidebar-collapsed > div:first-child {
                padding: 2rem 0 1rem 0 !important;
                display: flex !important;
                justify-content: center !important;
                align-items: flex-start !important;
            }

            #sidebar.sidebar-collapsed .glass-effect {
                background: transparent !important;
                border: none !important;
                border-color: transparent !important;
                box-shadow: none !important;
                border-radius: 0 !important;
                padding: 0 !important;
                margin: 0 !important;
                width: 2.5rem !important;
                height: 2.5rem !important;
                gap: 0 !important;
                display: flex !important;
                justify-content: center !important;
                align-items: center !important;
                transition: width 0.3s ease, height 0.3s ease, padding 0.3s ease, margin 0.3s ease, gap 0.3s ease, background 0s ease, box-shadow 0s ease, border 0s ease !important;
            }

            #sidebar.sidebar-collapsed .glass-effect > div {
                display: none !important;
                transition: none !important;
            }

            #sidebar.sidebar-collapsed .glass-effect > div:first-child {
                display: flex !important;
                margin: 0 !important;
                transition: none !important;
            }

            /* Center all sidebar links */
            #sidebar.sidebar-collapsed .sidebar-link {
                justify-content: center;
                padding-left: 0 !important;
                padding-right: 0 !important;
                gap: 0 !important;
            }

            /* Center navigation container and remove side padding */
            #sidebar.sidebar-collapsed nav {
                display: flex;
                flex-direction: column;
                align-items: center;
                padding-left: 0 !important;
                padding-right: 0 !important;
            }

            /* Center footer items and remove side padding */
            #sidebar.sidebar-collapsed .p-4.mt-auto,
            #sidebar.sidebar-collapsed .px-4.pt-3.pb-4.mt-auto {
                padding-left: 0 !important;
                padding-right: 0 !important;
                transition: padding 0.3s ease;
            }

            #sidebar.sidebar-collapsed .p-4.mt-auto a,
            #sidebar.sidebar-collapsed .px-4.pt-3.pb-4.mt-auto a,
            #sidebar.sidebar-collapsed .p-4.mt-auto button,
            #sidebar.sidebar-collapsed .px-4.pt-3.pb-4.mt-auto button {
                justify-content: center;
                padding-left: 0 !important;
                padding-right: 0 !important;
                gap: 0 !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
                transition: padding 0.3s ease, gap 0.3s ease;
            }

            /* Ensure proper width for navigation links */
            #sidebar.sidebar-collapsed nav a {
                width: 100%;
            }
        }
    </style>
    <script>
        // Apply saved sidebar state immediately to prevent flickering
        (function() {
            if (window.innerWidth >= 768) {
                var isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
                if (isCollapsed) {
                    document.documentElement.style.setProperty('--sidebar-width', '4.5rem');
                    
                    // Add a style tag to apply collapsed state before page renders
                    var style = document.createElement('style');
                    style.id = 'sidebar-preload-style';
                    style.textContent = `
                        #sidebar { width: 4.5rem !important; overflow-x: hidden !important; }
                        #sidebar .sidebar-label,
                        #sidebar .sidebar-brand-text,
                        #sidebar .sidebar-brand-subtitle,
                        #sidebar .sidebar-toggle-text {
                            opacity: 0 !important;
                            width: 0 !important;
                            overflow: hidden !important;
                        }
                        #sidebar .glass-effect {
                            background: transparent !important;
                            border: none !important;
                            border-color: transparent !important;
                            box-shadow: none !important;
                            border-radius: 0 !important;
                            padding: 0 !important;
                            margin: 0 !important;
                            width: 2.5rem !important;
                            height: 2.5rem !important;
                            gap: 0 !important;
                            display: flex !important;
                            justify-content: center !important;
                            align-items: center !important;
                        }
                        #sidebar .glass-effect > div {
                            display: none !important;
                        }
                        #sidebar .glass-effect > div:first-child {
                            display: flex !important;
                            margin: 0 !important;
                        }
                        #sidebar > div:first-child {
                            padding: 2rem 0 1rem 0 !important;
                            display: flex !important;
                            justify-content: center !important;
                            align-items: flex-start !important;
                        }
                        #sidebar .sidebar-link {
                            justify-content: center !important;
                            padding-left: 0 !important;
                            padding-right: 0 !important;
                            gap: 0 !important;
                        }
                        #sidebar nav {
                            display: flex !important;
                            flex-direction: column !important;
                            align-items: center !important;
                            padding-left: 0 !important;
                            padding-right: 0 !important;
                        }
                        #sidebar .p-4.mt-auto,
                        #sidebar .px-4.pt-3.pb-4.mt-auto {
                            padding-left: 0 !important;
                            padding-right: 0 !important;
                        }
                        #sidebar .p-4.mt-auto a,
                        #sidebar .px-4.pt-3.pb-4.mt-auto a,
                        #sidebar .p-4.mt-auto button,
                        #sidebar .px-4.pt-3.pb-4.mt-auto button {
                            justify-content: center !important;
                            padding-left: 0 !important;
                            padding-right: 0 !important;
                            gap: 0 !important;
                        }
                        #sidebar nav a {
                            width: 100% !important;
                        }
                    `;
                    document.head.appendChild(style);
                    
                    // Remove the style tag after DOM is loaded to allow transitions
                    document.addEventListener('DOMContentLoaded', function() {
                        setTimeout(function() {
                            var preloadStyle = document.getElementById('sidebar-preload-style');
                            if (preloadStyle) preloadStyle.remove();
                            
                            // Apply classes normally
                            var sidebar = document.getElementById('sidebar');
                            if (sidebar) sidebar.classList.add('sidebar-collapsed');
                            
                            // Update icons
                            var collapseIcon = document.getElementById('collapseIcon');
                            var collapseText = document.getElementById('collapseText');
                            if (collapseIcon) collapseIcon.textContent = 'keyboard_double_arrow_right';
                            if (collapseText) collapseText.textContent = 'Expand';
                        }, 50);
                    });
                }
            }
        })();
    </script>
</head>
<body class="bg-background-light dark:bg-background-dark font-display text-slate-900 dark:text-slate-100 antialiased">
