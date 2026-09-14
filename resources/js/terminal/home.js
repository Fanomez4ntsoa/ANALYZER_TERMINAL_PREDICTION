/**
 * Page principale : état partagé entre le tableau des sélections, le combiné,
 * le Monte-Carlo et la calibration (composant Alpine « terminalHome »).
 *
 * Combiné : produit des probabilités et produit des cotes, rien d'autre.
 * Une ligne par match au plus (le produit suppose des événements indépendants,
 * deux issues d'un même match ne le sont pas), trois lignes au plus.
 */
import { MonteCarlo } from './monte-carlo';
import { count, fr, percent } from './format';

export const MAX_PICKS = 3;

export function terminalHome({ simulations, defaultMatch }) {
    return {
        market: 'winner',
        mcMatch: defaultMatch,
        mcRunning: false,
        mcDraws: '—',
        mcOver: '—',
        mcHome: '—',
        mcAtRest: false,
        picks: [],

        init() {
            const canvas = this.$refs.mcCanvas;
            if (!canvas) return;
            this.mc = new MonteCarlo(canvas, {
                onRunning: (running) => { this.mcRunning = running; },
                onTick: ({ draws, over, home, atRest }) => {
                    this.mcDraws = draws === null ? '—' : count(draws);
                    this.mcOver = fr(over * 100, 1);
                    this.mcHome = fr(home * 100, 1);
                    this.mcAtRest = atRest;
                },
            });
            this.mc.setMatch(simulations[this.mcMatch] ?? null);
            this.$watch('mcMatch', (id) => this.mc.setMatch(simulations[id] ?? null));
        },

        get sim() {
            return simulations[this.mcMatch] ?? null;
        },

        recorded(key) {
            return this.sim ? percent(this.sim[key]) : '';
        },

        isPicked(id) {
            return this.picks.some((p) => p.id === id);
        },

        canPick(id, matchId) {
            if (this.isPicked(id)) return true;
            return this.picks.length < MAX_PICKS && !this.picks.some((p) => p.matchId === matchId);
        },

        toggle(el) {
            const line = {
                id: Number(el.dataset.id),
                matchId: Number(el.dataset.match),
                label: el.dataset.label,
                probability: Number(el.dataset.probability),
                odds: Number(el.dataset.odds),
            };
            this.picks = el.checked
                ? [...this.picks.filter((p) => p.id !== line.id), line]
                : this.picks.filter((p) => p.id !== line.id);
        },

        lineValues(p) {
            return `${percent(p.probability)} % · ${fr(p.odds, 2)}`;
        },

        clearPicks() {
            this.picks = [];
        },

        get productProbability() {
            if (this.picks.length < 2) return '—';
            return fr(this.picks.reduce((acc, p) => acc * p.probability, 1) * 100, 1) + ' %';
        },

        get productOdds() {
            if (this.picks.length < 2) return '—';
            return fr(this.picks.reduce((acc, p) => acc * p.odds, 1), 2);
        },
    };
}
