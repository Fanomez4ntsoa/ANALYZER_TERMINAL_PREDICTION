/** Horloges [data-clock] : heure UTC, rafraîchie chaque seconde. */
export function startClocks() {
    const clocks = document.querySelectorAll('[data-clock]');
    if (!clocks.length) return;

    const tick = () => {
        const text = new Date().toISOString().slice(11, 19);
        for (const clock of clocks) clock.textContent = text;
    };
    tick();
    setInterval(tick, 1000);
}
