/**
 * Audit du système de design, en développement seulement.
 *
 * Mesure l'effet rendu, pas les attributs (docs/design-system.md, Lueur) :
 * styles calculés de chaque élément et de ses pseudo-éléments (text-shadow là
 * où il apparaît et s'il porte du texte, box-shadow, filter, dégradé radial),
 * et shadowBlur intercepté sur les canvas. Avertit en console si le budget de
 * lueur est dépassé, si un effet n'a pas de data-glow, si un data-glow ne rend
 * rien, si plus d'un bloc est en vidéo inverse, ou si une lettre grecque minuscule
 * est mise en capitales (ρ devient Ρ et se lit P, λ devient Λ).
 */

export const GLOW_BUDGET = 2;
const MAX_INVERSE = 1;

const painted = new Set();

/** À appeler avant tout dessin de canvas. */
export function interceptCanvasGlow() {
    const proto = CanvasRenderingContext2D.prototype;
    const descriptor = Object.getOwnPropertyDescriptor(proto, 'shadowBlur');
    if (!descriptor || descriptor.set.__audited) return;

    const set = function (value) {
        if (value > 0) painted.add(this.canvas);
        descriptor.set.call(this, value);
    };
    set.__audited = true;
    Object.defineProperty(proto, 'shadowBlur', { configurable: true, get: descriptor.get, set });
}

function describe(el, pseudo = '') {
    const classes = el.classList.length ? '.' + [...el.classList].join('.') : '';
    return el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + classes + pseudo;
}

export function glowSources(root = document) {
    const sources = [];

    for (const el of root.querySelectorAll('body, body *')) {
        for (const pseudo of [null, '::before', '::after']) {
            const cs = getComputedStyle(el, pseudo);
            if (pseudo && (cs.content === 'none' || cs.content === 'normal')) continue;

            const kinds = [];
            // text-shadow s'hérite : seul l'élément où il apparaît compte, et
            // seulement s'il porte du texte (sur un canvas ou un bloc vide, rien)
            const parent = pseudo ? getComputedStyle(el) : el.parentElement && getComputedStyle(el.parentElement);
            const hasText = pseudo ? /^["'].+["']$/.test(cs.content) : el.textContent.trim() !== '';
            if (cs.textShadow !== 'none' && hasText && (!parent || parent.textShadow !== cs.textShadow)) kinds.push('text-shadow');
            if (cs.boxShadow !== 'none') kinds.push('box-shadow');
            if (cs.filter !== 'none') kinds.push('filter');
            if (/radial-gradient/.test(cs.backgroundImage)) kinds.push('halo radial');

            if (kinds.length) {
                sources.push({ el, label: describe(el, pseudo ?? ''), kinds, declared: !pseudo && el.hasAttribute('data-glow') });
            }
        }
    }

    for (const canvas of painted) {
        if (!canvas.isConnected) continue;
        const same = sources.find((s) => s.el === canvas);
        if (same) same.kinds.push('canvas shadowBlur');
        else sources.push({ el: canvas, label: describe(canvas), kinds: ['canvas shadowBlur'], declared: canvas.hasAttribute('data-glow') });
    }

    return sources;
}

/** Éléments dont le texte propre contient une lettre grecque minuscule rendue en capitales. */
export function uppercasedGreek(root = document) {
    const found = [];
    for (const el of root.querySelectorAll('body *')) {
        const own = [...el.childNodes].filter((n) => n.nodeType === Node.TEXT_NODE).map((n) => n.textContent).join('');
        if (/[\u03B1-\u03C9]/.test(own) && getComputedStyle(el).textTransform === 'uppercase') {
            found.push(`${describe(el)} « ${own.trim().slice(0, 40)} »`);
        }
    }
    return found;
}

export function audit() {
    const sources = glowSources();
    const problems = [];
    const motionOn = document.documentElement.dataset.motion === 'on';

    for (const s of sources) {
        if (!s.declared) problems.push(`lueur sans data-glow : ${s.label} (${s.kinds.join(', ')})`);
    }
    for (const el of document.querySelectorAll('[data-glow]')) {
        if (sources.some((s) => s.el === el)) continue;
        // Un canvas au repos (mouvement coupé) ne flashe pas : ce n'est pas une erreur
        if (el.tagName === 'CANVAS' && !motionOn) continue;
        problems.push(`data-glow sans effet rendu : ${describe(el)}`);
    }
    if (sources.length > GLOW_BUDGET) {
        problems.push(`${sources.length} lueurs rendues, budget ${GLOW_BUDGET} : ${sources.map((s) => s.label).join(', ')}`);
    }
    const inverse = document.querySelectorAll('.state-inverse').length;
    if (inverse > MAX_INVERSE) problems.push(`${inverse} blocs en vidéo inverse, maximum ${MAX_INVERSE}`);
    for (const g of uppercasedGreek()) problems.push(`lettre grecque en capitales : ${g}`);

    return { sources, inverse, problems };
}

/** Relevé périodique ; n'avertit qu'au changement pour ne pas inonder la console. */
export function startDesignAudit({ intervalMs = 1000, firstDelayMs = 1200 } = {}) {
    let last = '';
    const run = () => {
        const { problems } = audit();
        const key = problems.join('|');
        if (key && key !== last) console.warn('Système de design :\n- ' + problems.join('\n- '));
        last = key;
    };
    // Premier relevé après quelques images : un flash de canvas doit avoir eu lieu
    setTimeout(() => {
        run();
        setInterval(run, intervalMs);
    }, firstDelayMs);
}
