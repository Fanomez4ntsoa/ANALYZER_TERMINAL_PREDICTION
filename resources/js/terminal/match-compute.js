/**
 * Calcul à la demande des probabilités (page Matchs). Le serveur refuse tout
 * match commencé (409) : le bouton n'est qu'une commodité, pas la garde.
 */
export function matchCompute({ matches, computeUrl }) {
    return {
        matches,
        running: false,
        done: false,
        progress: 0,
        total: 0,
        current: '',
        errors: [],

        get pending() {
            return this.matches.filter((m) => !m.computed);
        },

        async computeOne(id) {
            const match = this.matches.find((m) => m.id === id);
            await this.run(match ? [match] : [{ id, name: `#${id}` }]);
        },

        async computeAll(recompute) {
            const list = recompute ? this.matches : this.pending;
            if (list.length === 0) return;
            if (recompute && !confirm(`Recalculer ${list.length} matchs ? Les probabilités actuelles seront remplacées.`)) return;
            await this.run(list);
        },

        async run(list) {
            this.running = true;
            this.done = false;
            this.errors = [];
            this.progress = 0;
            this.total = list.length;
            const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

            for (const match of list) {
                this.current = match.name;
                try {
                    const response = await fetch(`${computeUrl}/${match.id}`, {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' },
                    });
                    const data = await response.json().catch(() => ({}));
                    if (!response.ok || !data.success) {
                        this.errors.push(`${match.name} : ${data.error ?? `réponse HTTP ${response.status}`}`);
                    }
                } catch (e) {
                    this.errors.push(`${match.name} : ${e.message}`);
                }
                this.progress++;
            }

            this.running = false;
            this.done = true;
            this.current = '';
            if (this.errors.length === 0) window.location.reload();
        },
    };
}
