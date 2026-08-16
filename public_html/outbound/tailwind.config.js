/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ["./**/*.php", "./js/app.js"],
  darkMode: 'class',
  safelist: [
    'bg-emerald-400', 'bg-amber-400', 'bg-rose-500', 'text-emerald-400',
    'text-amber-400', 'text-rose-400', 'animate-pulse', 'border-emerald-500/30',
    'border-amber-500/30', 'border-rose-500/30', 'border-emerald-400',
    'border-rose-400', 'bg-emerald-500/20', 'bg-amber-500/20', 'bg-rose-500/20',
    'bg-blue-500/20', 'bg-blue-500/30', 'text-blue-400', 'border-blue-500/30',
    'border-l-2', 'border-l-amber-400', 'bg-amber-500/10'
  ],
  theme: {
    extend: {
      colors: {
        slate: { 950: '#0a0f1a' }
      }
    }
  }
};