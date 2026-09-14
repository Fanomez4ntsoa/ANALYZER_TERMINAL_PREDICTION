/**
 * Page principale : état partagé entre le tableau des sélections, le combiné,
 * le Monte-Carlo et la calibration (composant Alpine « terminalHome »).
 *
 * Tri : heure (défaut), championnat, marché ; filtre par marché. Critères de
 * navigation seulement : aucun tri par écart, probabilité ou cote, jamais
 * (docs/design-system.md, principe 1). En ajouter un est une régression.
 *
 * Combiné : produit des probabilités et produit des cotes, rien d'autre.
 * Une ligne par match au plus (le produit suppose des événements indépendants,
 * deux issues d'un même match ne le sont pas), trois lignes au plus.
 */
import { MonteCarlo } from './monte-carlo';
import { count, fr, percent } from './format';

export const MAX_PICKS = 3;

const SORT_KEYS = ['time', 'competition', 'market'];

export function terminalHome({ simulations, defaultMatch, marketOrder = [] }) {
    return {
        market: 'winner',
        mcMatch: defaultMatch,
        mcRunning: false,
        mcDraws: '—',
        mcOver: '—',
        mcHome: '—',
        mcAtRest: false,
        picks: [],
        sortBy: 'time',
        marketFilter: 'all',
        hiddenUnpriced: 0,

        init() {
            this.$watch('sortBy', () => this.arrange());
            this.$watch('marketFilter', () => this.arrange());

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

        /** Réordonne les lignes existantes (liaisons Alpine conservées) et masque les répétitions. */
        arrange() {
            const body = this.$refs.selBody;
            if (!body || !SORT_KEYS.includes(this.sortBy)) return;
            const rows = [...body.querySelectorAll('tr[data-row]')];
            const rank = (market) => (market === '' ? marketOrder.length : marketOrder.indexOf(market));
            const key = {
                time: (r) => [Number(r.dataset.index)],
                competition: (r) => [r.dataset.competition, Number(r.dataset.index)],
                market: (r) => [rank(r.dataset.market), Number(r.dataset.index)],
            }[this.sortBy];
            const compare = (a, b) => {
                const ka = key(a);
                const kb = key(b);
                for (let i = 0; i < ka.length; i++) {
                    const c = typeof ka[i] === 'string' ? ka[i].localeCompare(kb[i], 'fr') : ka[i] - kb[i];
                    if (c !== 0) return c;
                }
                return 0;
            };

            rows.sort(compare).forEach((r) => body.appendChild(r));

            let previous = null;
            let hidden = 0;
            for (const r of rows) {
                const visible = this.marketFilter === 'all' || r.dataset.market === this.marketFilter;
                r.hidden = !visible;
                if (!visible) {
                    if (r.dataset.market === '') hidden++;
                    continue;
                }
                const start = r.dataset.match !== previous;
                r.classList.toggle('group-start', start);
                r.classList.toggle('is-repeat', !start);
                previous = r.dataset.match;
            }
            this.hiddenUnpriced = hidden;
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
