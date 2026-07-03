/** @type {import('tailwindcss').Config} */

// Tokens are defined once as CSS custom properties in src/index.css and mapped
// here so Tailwind utilities (bg-brand, text-fg-muted, shadow-card, ...) resolve
// to the same runtime-themeable values. Dark mode flips via [data-theme='dark'].
export default {
  darkMode: ['selector', "[data-theme='dark']"],
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        brand: {
          50: 'var(--brand-50)',
          100: 'var(--brand-100)',
          200: 'var(--brand-200)',
          300: 'var(--brand-300)',
          400: 'var(--brand-400)',
          500: 'var(--brand-500)',
          600: 'var(--brand-600)',
          700: 'var(--brand-700)',
          800: 'var(--brand-800)',
          900: 'var(--brand-900)',
          DEFAULT: 'var(--color-primary)',
        },
        accent: {
          50: 'var(--accent-50)',
          100: 'var(--accent-100)',
          200: 'var(--accent-200)',
          300: 'var(--accent-300)',
          400: 'var(--accent-400)',
          500: 'var(--accent-500)',
          600: 'var(--accent-600)',
          700: 'var(--accent-700)',
          800: 'var(--accent-800)',
          900: 'var(--accent-900)',
          DEFAULT: 'var(--accent-500)',
        },
        ink: {
          0: 'var(--ink-0)',
          50: 'var(--ink-50)',
          100: 'var(--ink-100)',
          200: 'var(--ink-200)',
          300: 'var(--ink-300)',
          400: 'var(--ink-400)',
          500: 'var(--ink-500)',
          600: 'var(--ink-600)',
          700: 'var(--ink-700)',
          800: 'var(--ink-800)',
          900: 'var(--ink-900)',
        },
        // Semantic aliases — prefer these in app code.
        bg: 'var(--color-bg)',
        surface: {
          DEFAULT: 'var(--color-surface)',
          2: 'var(--color-surface-2)',
        },
        border: {
          DEFAULT: 'var(--color-border)',
          strong: 'var(--color-border-strong)',
        },
        fg: {
          DEFAULT: 'var(--color-fg)',
          muted: 'var(--color-fg-muted)',
          subtle: 'var(--color-fg-subtle)',
          onbrand: 'var(--color-fg-onbrand)',
        },
        primary: {
          DEFAULT: 'var(--color-primary)',
          hover: 'var(--color-primary-hover)',
          fg: 'var(--color-primary-fg)',
        },
        success: {
          DEFAULT: 'var(--color-success)',
          bg: 'var(--color-success-bg)',
        },
        danger: {
          DEFAULT: 'var(--color-danger)',
          bg: 'var(--color-danger-bg)',
        },
        warning: {
          DEFAULT: 'var(--color-warning)',
          bg: 'var(--color-warning-bg)',
        },
        info: {
          DEFAULT: 'var(--color-info)',
          bg: 'var(--color-info-bg)',
        },
        ring: 'var(--color-ring)',
      },
      fontFamily: {
        display: 'var(--font-display)',
        text: 'var(--font-text)',
        mono: 'var(--font-mono)',
      },
      fontSize: {
        // Text scale (1.20 minor-third-ish, tuned for UI density).
        '2xs': ['0.6875rem', { lineHeight: '1rem' }],
        xs: ['0.75rem', { lineHeight: '1.1rem' }],
        sm: ['0.8125rem', { lineHeight: '1.25rem' }],
        base: ['0.9375rem', { lineHeight: '1.55rem' }],
        lg: ['1.0625rem', { lineHeight: '1.6rem' }],
        xl: ['1.25rem', { lineHeight: '1.5rem' }],
        '2xl': ['1.5rem', { lineHeight: '1.25' }],
        '3xl': ['1.875rem', { lineHeight: '1.15' }],
        '4xl': ['2.375rem', { lineHeight: '1.1', letterSpacing: '-0.02em' }],
        '5xl': ['3.125rem', { lineHeight: '1.05', letterSpacing: '-0.02em' }],
        '6xl': ['3.875rem', { lineHeight: '1', letterSpacing: '-0.025em' }],
      },
      spacing: {
        // 4px base rhythm; add a few larger editorial steps.
        18: '4.5rem',
        22: '5.5rem',
        30: '7.5rem',
      },
      borderRadius: {
        xs: 'var(--radius-xs)',
        sm: 'var(--radius-sm)',
        md: 'var(--radius-md)',
        lg: 'var(--radius-lg)',
        xl: 'var(--radius-xl)',
        '2xl': 'var(--radius-2xl)',
        full: 'var(--radius-full)',
      },
      boxShadow: {
        xs: 'var(--shadow-xs)',
        sm: 'var(--shadow-sm)',
        card: 'var(--shadow-card)',
        md: 'var(--shadow-md)',
        lg: 'var(--shadow-lg)',
        focus: 'var(--shadow-focus)',
      },
      zIndex: {
        base: '0',
        raised: '10',
        sticky: '100',
        header: '200',
        dropdown: '300',
        overlay: '400',
        modal: '500',
        toast: '600',
        tooltip: '700',
      },
      transitionTimingFunction: {
        standard: 'var(--ease-standard)',
        emphasized: 'var(--ease-emphasized)',
        out: 'var(--ease-out)',
        'in-out': 'var(--ease-in-out)',
      },
      transitionDuration: {
        instant: '80ms',
        fast: '140ms',
        base: '220ms',
        slow: '360ms',
        slower: '560ms',
      },
      maxWidth: {
        content: '1120px',
      },
      keyframes: {
        shimmer: {
          '100%': { transform: 'translateX(100%)' },
        },
        drawerIn: {
          from: { transform: 'translateX(100%)' },
          to: { transform: 'translateX(0)' },
        },
        fadeIn: {
          from: { opacity: '0' },
          to: { opacity: '1' },
        },
      },
      animation: {
        shimmer: 'shimmer 1.6s var(--ease-in-out) infinite',
        drawerIn: 'drawerIn 240ms var(--ease-out) both',
        fadeIn: 'fadeIn 200ms var(--ease-out) both',
      },
    },
  },
  plugins: [],
};
