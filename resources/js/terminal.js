/**
 * Terminal : point d'entrée JS. Remplace les CDN de l'ancien layout
 * (Tailwind, Alpine, Chart.js) par le build Vite.
 */
import Alpine from 'alpinejs';
import { bindMotionToggles } from './terminal/motion';
import { startClocks } from './terminal/clock';
import { interceptCanvasGlow, startDesignAudit } from './terminal/design-audit';

// Avant tout dessin de canvas, pour que l'audit voie les lueurs peintes
if (import.meta.env.DEV) interceptCanvasGlow();

window.Alpine = Alpine;
Alpine.start();

bindMotionToggles();
startClocks();

if (import.meta.env.DEV) startDesignAudit();
