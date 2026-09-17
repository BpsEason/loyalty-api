import colors from "tailwindcss/colors";

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        "./app/Filament/**/*.php",
        "./resources/views/filament/**/*.blade.php",
        "./vendor/filament/**/*.blade.php",
        "./vendor/filament/**/*.php",
    ],
    theme: {
        extend: {
            colors: {
                primary: {
                    50: "rgb(var(--primary-50) / <alpha-value>)",
                    100: "rgb(var(--primary-100) / <alpha-value>)",
                    200: "rgb(var(--primary-200) / <alpha-value>)",
                    300: "rgb(var(--primary-300) / <alpha-value>)",
                    400: "rgb(var(--primary-400) / <alpha-value>)",
                    500: "rgb(var(--primary-500) / <alpha-value>)",
                    600: "rgb(var(--primary-600) / <alpha-value>)",
                    700: "rgb(var(--primary-700) / <alpha-value>)",
                    800: "rgb(var(--primary-800) / <alpha-value>)",
                    900: "rgb(var(--primary-900) / <alpha-value>)",
                    950: "rgb(var(--primary-950) / <alpha-value>)",
                },
                success: "rgb(var(--success-500) / <alpha-value>)",
                warning: "rgb(var(--warning-500) / <alpha-value>)",
                danger: "rgb(var(--danger-500) / <alpha-value>)",
                background: "rgb(var(--background) / <alpha-value>)",
                surface: "rgb(var(--surface) / <alpha-value>)",
                "surface-subtle": "rgb(var(--surface-subtle) / <alpha-value>)",
                "surface-elevated":
                    "rgb(var(--surface-elevated) / <alpha-value>)",
                "text-primary": "rgb(var(--text-primary) / <alpha-value>)",
                "text-secondary": "rgb(var(--text-secondary) / <alpha-value>)",
                "text-muted": "rgb(var(--text-muted) / <alpha-value>)",
                "border-color": "rgb(var(--border-color) / <alpha-value>)",
                "border-color-subtle":
                    "rgb(var(--border-color-subtle) / <alpha-value>)",
            },
        },
    },
};
