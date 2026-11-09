import './bootstrap';

import Alpine from 'alpinejs';
import { applySeatStates, cabinStyle, drawSeatMap, seatStyles, statusToStyle } from './seat-map';

window.Alpine = Alpine;

// Shared seat map (R1 / C3 / gate plane): see resources/js/seat-map.js.
window.ArcsSeatMap = { draw: drawSeatMap, styles: seatStyles, cabinStyle, applySeatStates, statusToStyle };

Alpine.start();
