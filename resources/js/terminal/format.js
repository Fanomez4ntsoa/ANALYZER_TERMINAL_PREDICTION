/** Format français, miroir de App\Support\Terminal\Fmt : virgule, signe moins U+2212. */
export function fr(value, decimals) {
    const rounded = Number(value.toFixed(decimals));
    const text = Math.abs(rounded).toFixed(decimals).replace('.', ',');
    return (rounded < 0 ? '−' : '') + text;
}

export function percent(probability, decimals = 1) {
    return probability === null || probability === undefined ? '' : fr(probability * 100, decimals);
}

export function count(n) {
    return n.toLocaleString('fr-FR');
}
