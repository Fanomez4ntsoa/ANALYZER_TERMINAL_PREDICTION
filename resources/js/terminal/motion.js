/**
 * Mouvement : html[data-motion="on|off"] gouverne toutes les animations.
 * prefers-reduced-motion ne fournit que la valeur initiale, posée avant le
 * premier rendu par le script en ligne du layout ; le choix est ensuite mémorisé.
 */

export const STORAGE_KEY = 'terminal-motion';

export function motionOn() {
    return document.documentElement.dataset.motion === 'on';
}

export function setMotion(on) {
    document.documentElement.dataset.motion = on ? 'on' : 'off';
    try {
        localStorage.setItem(STORAGE_KEY, on ? 'on' : 'off');
    } catch {
        // stockage indisponible (navigation privée) : le choix vaut pour la page
    }
    document.dispatchEvent(new CustomEvent('terminal:motion', { detail: { on } }));
}

/** Boutons [data-motion-toggle] : aria-pressed et libellé [data-motion-label]. */
export function bindMotionToggles() {
    const render = () => {
        for (const button of document.querySelectorAll('[data-motion-toggle]')) {
            button.setAttribute('aria-pressed', motionOn() ? 'true' : 'false');
            const label = button.querySelector('[data-motion-label]');
            if (label) label.textContent = motionOn() ? 'Actif' : 'Coupé';
        }
    };

    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-motion-toggle]')) setMotion(!motionOn());
    });
    document.addEventListener('terminal:motion', render);
    render();
}
