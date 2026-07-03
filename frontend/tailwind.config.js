/** @type {import('tailwindcss').Config} */

// Tokens are defined once as CSS custom properties in src/index.css and mapped
// here so Tailwind utilities (bg-brand, text-fg-muted, shadow-card, ...) resolve
// to the same runtime-themeable values. Dark mode flips via [data-theme='dark'].
//
// Colour tokens are stored in index.css as SPACE-SEPARATED RGB CHANNEL TRIPLES
// (e.g. `--color-primary: 255 59 95`) and wrapped here as
// `rgb(var(--token) / <alpha-value>)`. That `<alpha-value>` placeholder is what
// lets Tailwind's alpha modifiers (bg-primary/95, text-fg/70, ring-primary/40)
// emit valid rgb() and render opaque as intended — a bare `var(--token)` mapping
// silently produced transparent surfaces for every /opacity utility. The two
// `--color-*-bg` tint tokens stay bare because they are complete colour values,
// consumed whole and never alpha-modified.
const rgb = (v) => `rgb(var(${v}) / <alpha-value>)`;

export default {
  darkMode: ['selector', "[data-theme='dark']"],
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        brand: {
          50: rgb('--brand-50'),
          100: rgb('--brand-100'),
          200: rgb('--brand-200'),
          300: rgb('--brand-300'),
          400: rgb('--brand-400'),
          500: rgb('--brand-500'),
          600: rgb('--brand-600'),
          700: rgb('--brand-700'),
          800: rgb('--brand-800'),
          900: rgb('--brand-900'),
          DEFAULT: rgb('--color-primary'),
        },
        accent: {
          50: rgb('--accent-50'),
          100: rgb('--accent-100'),
          200: rgb('--accent-200'),
          300: rgb('--accent-300'),
          400: rgb('--accent-400'),
          500: rgb('--accent-500'),
          600: rgb('--accent-600'),
          700: rgb('--accent-700'),
          800: rgb('--accent-800'),
          900: rgb('--accent-900'),
          DEFAULT: rgb('--accent-500'),
        },
        ink: {
          0: rgb('--ink-0'),
          50: rgb('--ink-50'),
          100: rgb('--ink-100'),
          200: rgb('--ink-200'),
          300: rgb('--ink-300'),
          400: rgb('--ink-400'),
          500: rgb('--ink-500'),
          600: rgb('--ink-600'),
          700: rgb('--ink-700'),
          800: rgb('--ink-800'),
          900: rgb('--ink-900'),
        },
        // Semantic aliases — prefer these in app code.
        bg: rgb('--color-bg'),
        surface: {
          DEFAULT: rgb('--color-surface'),
          2: rgb('--color-surface-2'),
        },
        border: {
          DEFAULT: rgb('--color-border'),
          strong: rgb('--color-border-strong'),
        },
        fg: {
          DEFAULT: rgb('--color-fg'),
          muted: rgb('--color-fg-muted'),
          subtle: rgb('--color-fg-subtle'),
          onbrand: rgb('--color-fg-onbrand'),
        },
        primary: {
          DEFAULT: rgb('--color-primary'),
          hover: rgb('--color-primary-hover'),
          fg: rgb('--color-primary-fg'),
        },
        success: {
          DEFAULT: rgb('--color-success'),
          // Complete tint colour (may be rgba in dark) — consumed whole.
          bg: 'var(--color-success-bg)',
        },
        danger: {
          DEFAULT: rgb('--color-danger'),
          bg: 'var(--color-danger-bg)',
        },
        warning: {
          DEFAULT: rgb('--color-warning'),
          bg: 'var(--color-warning-bg)',
        },
        info: {
          DEFAULT: rgb('--color-info'),
          bg: 'var(--color-info-bg)',
        },
        ring: rgb('--color-ring'),
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
