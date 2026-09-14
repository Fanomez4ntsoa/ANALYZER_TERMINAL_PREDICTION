/**
 * Simulation Monte-Carlo d'un match : tirages dans la matrice de scores exacte
 * du modèle (λ et ρ enregistrés avec la prédiction, 0 à 6 buts, renormalisée).
 *
 * Mouvement coupé : la matrice affiche les probabilités exactes, aucun tirage.
 * Onglet en arrière-plan : pause. Le flash n'est dessiné avec shadowBlur que si
 * le canvas porte data-glow (budget de lueur, docs/design-system.md).
 */
import { motionOn } from './motion';

const FLASH_DECAY = 0.055;
const RESET_AFTER = 26000;

function token(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

function rgb(hex) {
    const h = hex.replace('#', '');
    return [0, 2, 4].map((i) => parseInt(h.slice(i, i + 2), 16)).join(',');
}

export class MonteCarlo {
    /**
     * @param {HTMLCanvasElement} canvas
     * @param {{onTick?: Function, onRunning?: Function}} hooks
     */
    constructor(canvas, hooks = {}) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.hooks = hooks;
        this.sim = null;
        this.frame = this.frame.bind(this);
        requestAnimationFrame(this.frame);
    }

    setMatch(sim) {
        this.sim = sim;
        this.size = sim ? sim.matrix.length : 0;
        this.cumulative = [];
        this.exact = { over: 0, home: 0 };
        if (!sim) return;

        const total = sim.matrix.flat().reduce((a, b) => a + b, 0);
        this.matrix = sim.matrix.map((row) => row.map((p) => p / total));
        let acc = 0;
        this.matrix.forEach((row, h) => row.forEach((p, a) => {
            acc += p;
            this.cumulative.push([acc, h, a]);
            if (h + a > 2) this.exact.over += p;
            if (h > a) this.exact.home += p;
        }));
        this.reset();
    }

    reset() {
        this.cells = Array.from({ length: this.size }, () => new Array(this.size).fill(0));
        this.flash = Array.from({ length: this.size }, () => new Array(this.size).fill(0));
        this.draws = 0;
        this.over = 0;
        this.home = 0;
    }

    drawOne() {
        const u = Math.random();
        for (const cell of this.cumulative) if (u <= cell[0]) return cell;
        return this.cumulative[this.cumulative.length - 1];
    }

    running() {
        return this.sim !== null && motionOn() && !document.hidden;
    }

    frame() {
        const running = this.running();
        this.hooks.onRunning?.(running);

        if (this.sim) {
            if (running) {
                const batch = this.draws < 400 ? 3 : this.draws < 3000 ? 14 : 40;
                for (let i = 0; i < batch; i++) {
                    const [, h, a] = this.drawOne();
                    this.cells[h][a]++;
                    this.flash[h][a] = 1;
                    this.draws++;
                    if (h + a > 2) this.over++;
                    if (h > a) this.home++;
                }
                this.hooks.onTick?.({ draws: this.draws, over: this.over / this.draws, home: this.home / this.draws, atRest: false });
                if (this.draws > RESET_AFTER) this.reset();
            } else if (!motionOn()) {
                this.hooks.onTick?.({ draws: null, over: this.exact.over, home: this.exact.home, atRest: true });
            }
            this.draw(!motionOn());
        }

        requestAnimationFrame(this.frame);
    }

    fit() {
        const parent = this.canvas.parentElement;
        const w = parent.clientWidth;
        const h = parent.clientHeight;
        if (!w || !h) return null;
        const dpr = window.devicePixelRatio || 1;
        if (this.canvas._w !== w || this.canvas._h !== h || this.canvas._d !== dpr) {
            this.canvas.width = Math.round(w * dpr);
            this.canvas.height = Math.round(h * dpr);
            Object.assign(this.canvas, { _w: w, _h: h, _d: dpr });
            this.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        }
        return { w, h };
    }

    draw(atRest) {
        const box = this.fit();
        if (!box) return;
        const { w, h } = box;
        const n = this.size;
        const ctx = this.ctx;
        const pad = 22;
        const s = Math.min((w - pad) / n, (h - pad) / n);
        const ox = (w - s * n) / 2 + 6;
        const oy = (h - s * n) / 2 - 4;
        const values = atRest ? this.matrix : this.cells;
        const max = Math.max(...values.flat()) || 1;
        const live = rgb(token('--p-live'));
        const glow = this.canvas.hasAttribute('data-glow');

        ctx.clearRect(0, 0, w, h);
        for (let r = 0; r < n; r++) {
            for (let c = 0; c < n; c++) {
                const x = ox + c * s;
                const y = oy + r * s;
                ctx.fillStyle = `rgba(${live},${0.05 + (values[r][c] / max) * 0.6})`;
                ctx.fillRect(x + 1, y + 1, s - 2, s - 2);
                if (!atRest && this.flash[r][c] > 0) {
                    ctx.save();
                    if (glow) {
                        ctx.shadowColor = token('--p-live');
                        ctx.shadowBlur = Number(token('--glow-canvas-blur')) || 14;
                    }
                    ctx.globalAlpha = this.flash[r][c];
                    ctx.fillStyle = token('--p-hot');
                    ctx.fillRect(x + 1, y + 1, s - 2, s - 2);
                    ctx.restore();
                    this.flash[r][c] -= FLASH_DECAY;
                }
            }
        }

        // Frontière exacte de l'Under 2.5 : cases domicile + extérieur ≤ 2, en escalier
        // (la maquette traçait le carré 0-2 × 0-2, qui compte 2-1, 1-2 et 2-2)
        const stair = [[3, 0], [3, 1], [2, 1], [2, 2], [1, 2], [1, 3], [0, 3]];
        ctx.strokeStyle = token('--line-hi');
        ctx.lineWidth = 1.2;
        ctx.beginPath();
        stair.forEach(([col, row], i) => ctx[i ? 'lineTo' : 'moveTo'](ox + col * s, oy + row * s));
        ctx.stroke();

        ctx.font = `10px ${token('--mono')}`;
        ctx.fillStyle = token('--p-dim');
        for (let i = 0; i < n; i++) {
            ctx.textAlign = 'center';
            ctx.fillText(String(i), ox + i * s + s / 2, oy + n * s + 14);
            ctx.textAlign = 'right';
            ctx.fillText(String(i), ox - 6, oy + i * s + s / 2 + 3);
        }
    }
}
