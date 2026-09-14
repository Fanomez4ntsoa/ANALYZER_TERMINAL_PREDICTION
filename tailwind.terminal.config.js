/**
 * Tailwind du terminal. La palette, les tailles et les espacements REMPLACENT
 * ceux de Tailwind : une classe hors jetons (text-gray-500, p-4…) ne génère
 * rien. Ombres, anneaux et flous sont désactivés : la seule lueur passe par
 * [data-glow] (docs/design-system.md).
 *
 * Les anciennes pages (layouts.pro, CDN) et Breeze (tailwind.config.js) ne
 * sont pas concernées tant qu'elles n'ont pas migré.
 */
const v = (name) => `var(--${name})`;

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/views/layouts/terminal.blade.php',
        './resources/views/components/terminal/**/*.blade.php',
        './resources/views/terminal/**/*.blade.php',
        './resources/js/terminal/**/*.js',
    ],

    corePlugins: {
        boxShadow: false,
        dropShadow: false,
        ringWidth: false,
        ringColor: false,
        ringOffsetWidth: false,
        ringOffsetColor: false,
        ringOpacity: false,
        blur: false,
        backdropBlur: false,
        borderRadius: false,
    },

    theme: {
        colors: {
            transparent: 'transparent',
            current: 'currentColor',
            bg: v('bg'),
            panel: v('panel'),
            'panel-2': v('panel-2'),
            line: v('line'),
            'line-hi': v('line-hi'),
            'p-dead': v('p-dead'),
            'p-dim': v('p-dim'),
            'p-mid': v('p-mid'),
            'p-live': v('p-live'),
            'p-hot': v('p-hot'),
            red: v('red'),
        },
        fontFamily: {
            mono: v('mono'),
            crt: v('crt'),
        },
        fontSize: {
            lab: v('fs-lab'),
            cell: v('fs-cell'),
            body: v('fs-body'),
            title: v('fs-title'),
            'crt-s': v('fs-crt-s'),
            'crt-m': v('fs-crt-m'),
            'crt-l': v('fs-crt-l'),
            'crt-xl': v('fs-crt-xl'),
        },
        fontWeight: {
            normal: '400',
            medium: '500',
            bold: '700',
        },
        spacing: {
            0: '0',
            px: '1px',
            row: v('row'),
            'hd-y': v('hd-y'),
            gap: v('gap'),
            page: v('page'),
            'hd-x': v('hd-x'),
            bd: v('bd'),
            'cell-x': v('cell-x'),
            group: v('group'),
        },
        extend: {},
    },

    plugins: [],
};
