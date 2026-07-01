import type { Config } from 'tailwindcss';

const config: Config = {
  content: [
    './resources/**/*.{blade.php,js,jsx,ts,tsx}',
  ],
  theme: {
    extend: {
      colors: {
        // من التصميم الأصلي
        primary: {
          DEFAULT: '#0E5C9C',
          700: '#0A487C',
        },
        secondary: {
          DEFAULT: '#13314F', // ink
        },
        cyan: {
          DEFAULT: '#11A0C8',
          bright: '#46C0E4',
        },
        success: {
          DEFAULT: '#1E9D6B',
          bg: '#E7F6EF',
        },
        warning: {
          DEFAULT: '#C0832B', // amber
          bg: '#FBF1E0',
        },
        danger: {
          DEFAULT: '#C0392B', // red
          bg: '#FBEAE8',
        },
        neutral: {
          bg: '#EAEEF1',
          paper: '#FFFFFF',
          paper2: '#F6F8FA',
          ink: '#13314F',
          muted: '#607689',
          faint: '#90A2B2',
          deep: '#0A2A55',
          line: '#E1E8EE',
          lineSoft: '#EDF2F6',
        },
      },
      backgroundColor: {
        DEFAULT: '#FFFFFF',
      },
      textColor: {
        DEFAULT: '#13314F',
      },
      borderColor: {
        DEFAULT: '#E1E8EE',
      },
      borderRadius: {
        DEFAULT: '16px',
        sm: '11px',
      },
      boxShadow: {
        DEFAULT: '0 1px 2px rgba(10,42,85,.05),0 8px 28px -14px rgba(10,42,85,.16)',
        lg: '0 24px 60px -24px rgba(10,42,85,.35)',
      },
      spacing: {
        'sidebar': '256px',
      },
      backgroundImage: {
        brand: 'linear-gradient(135deg,#0A2A55 0%,#0E5C9C 52%,#13A2C9 100%)',
      },
      fontFamily: {
        sans: ['-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'sans-serif'],
        arabic: ['Tajawal', 'sans-serif'],
      },
    },
  },
  plugins: [],
  corePlugins: {
    preflight: true,
  },
};

export default config;
