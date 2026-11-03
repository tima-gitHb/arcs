import './bootstrap';

import Alpine from 'alpinejs';
import { cabinStyle, drawSeatMap, seatStyles } from './seat-map';

window.Alpine = Alpine;

// Shared seat map (R1 reservation, C3 check-in): see resources/js/seat-map.js.
window.ArcsSeatMap = { draw: drawSeatMap, styles: seatStyles, cabinStyle };

Alpine.start();
