/**
 * The ARCS plane seat map, shared by reservation, check-in and gate.
 * Seat data: SeatService::seatMap() → {id, seat_no, row, letter, cabin, taken}.
 * Gate may also pass passenger states via data-seat-states / applySeatStates().
 *
 * drawSeatMap(map, seats, cellFor):
 *   cellFor(seat) => ({ style, disabled, onPick }) where style is a key of seatStyles
 *
 * Class name strings keep bg-violet-200 / cabin keys for B12Sprint2BaseTest.
 */
export const seatStyles = {
    business: 'bg-amber-200 text-amber-950 arcs-seat arcs-seat-business',
    premium: 'bg-violet-200 text-violet-950 arcs-seat arcs-seat-premium',
    economy: 'bg-white text-navy arcs-seat arcs-seat-free',
    taken: 'cursor-not-allowed bg-slate-600 text-slate-400 arcs-seat arcs-seat-taken',
    selected: 'bg-emerald-400 text-emerald-950 arcs-seat arcs-seat-selected',
    mine: 'bg-emerald-400 text-emerald-950 ring-2 ring-white seat-pulse arcs-seat arcs-seat-selected',
    booked: 'bg-sky-300 text-sky-950 arcs-seat arcs-seat-booked',
    checked_in: 'bg-amber-300 text-amber-950 arcs-seat arcs-seat-checked',
    boarded: 'bg-emerald-400 text-emerald-950 arcs-seat arcs-seat-boarded',
    no_show: 'bg-rose-400 text-rose-950 arcs-seat',
    legroom: 'bg-white text-navy arcs-seat arcs-seat-free arcs-seat-legroom',
};

const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F'];
const GRID = 'arcs-seat-grid';
const WING_AFTER_ROW = 12;
const EXIT_ROWS = new Set([14, 15]);

const statusStyle = {
    Booked: 'booked',
    CheckedIn: 'checked_in',
    Boarded: 'boarded',
    NoShow: 'no_show',
};

/**
 * Default look of a seat: its cabin colour, or blocked if taken.
 */
export function cabinStyle(seat) {
    if (seat.taken) {
        return 'taken';
    }

    return { Business: 'business', Premium: 'premium' }[seat.cabin] ?? 'economy';
}

/**
 * Gate colour from passenger status string (Booked / CheckedIn / Boarded / NoShow).
 */
export function statusToStyle(status) {
    return statusStyle[status] ?? 'taken';
}

function cabinLabelFor(cabin) {
    return { Business: 'BUSINESS', Premium: 'PREMIUM' }[cabin] ?? 'ECONOMY';
}

function appendEdge(line, text, extraClass = '') {
    const cell = document.createElement('span');
    cell.className = `arcs-seat-rownum ${extraClass}`.trim();
    cell.textContent = text;

    return cell;
}

export function drawSeatMap(map, seats, cellFor) {
    map.replaceChildren();

    const byRow = new Map();
    seats.forEach((seat) => {
        if (! byRow.has(seat.row)) {
            byRow.set(seat.row, []);
        }

        byRow.get(seat.row).push(seat);
    });

    const header = document.createElement('div');
    header.className = `${GRID} arcs-seat-header sticky top-0 z-10 mb-2`;
    ['', 'A', 'B', 'C', '', 'D', 'E', 'F', ''].forEach((letter) => {
        const cell = document.createElement('span');
        cell.className = letter ? 'arcs-seat-col-letter' : '';
        cell.textContent = letter;
        header.appendChild(cell);
    });
    map.appendChild(header);

    let lastCabin = null;
    let wingsDrawn = false;

    [...byRow.keys()].sort((left, right) => left - right).forEach((rowNo) => {
        const rowSeats = byRow.get(rowNo);
        const rowCabin = rowSeats.find((seat) => seat.cabin)?.cabin ?? 'Economy';

        if (rowCabin !== lastCabin) {
            const cabin = document.createElement('p');
            cabin.className = 'arcs-seat-cabin-label';
            cabin.textContent = cabinLabelFor(rowCabin);
            map.appendChild(cabin);
            lastCabin = rowCabin;
        }

        if (! wingsDrawn && rowNo > WING_AFTER_ROW) {
            const wing = document.createElement('div');
            wing.className = 'arcs-seat-wings';
            wing.setAttribute('aria-hidden', 'true');
            map.appendChild(wing);
            wingsDrawn = true;
        }

        const line = document.createElement('div');
        const isExit = EXIT_ROWS.has(rowNo);
        line.className = `${GRID} arcs-seat-row${isExit ? ' arcs-seat-row-exit' : ''}${isExit ? ' arcs-seat-row-legroom' : ''}`;

        if (isExit) {
            line.appendChild(appendEdge(line, 'EXIT', 'arcs-seat-exit'));
        } else {
            line.appendChild(appendEdge(line, String(rowNo)));
        }

        LETTERS.forEach((letter, position) => {
            if (position === 3) {
                const aisle = document.createElement('span');
                aisle.className = 'arcs-seat-aisle';
                line.appendChild(aisle);
            }

            const seat = rowSeats.find((item) => item.letter === letter);
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = letter;

            if (! seat) {
                button.disabled = true;
                button.className = 'arcs-seat opacity-0';
                line.appendChild(button);

                return;
            }

            const cell = cellFor(seat);
            let style = cell.style;
            if ((style === 'economy' || style === 'legroom') && (seat.exit || EXIT_ROWS.has(rowNo)) && ! seat.taken) {
                style = 'legroom';
            }
            button.disabled = Boolean(cell.disabled);
            button.className = `transition-transform ${seatStyles[style] ?? seatStyles.taken}`;
            button.title = seat.taken ? `${seat.seat_no} taken` : seat.seat_no;
            button.dataset.seatNo = seat.seat_no;
            button.dataset.style = style;
            button.dataset.freeStyle = cabinStyle({ ...seat, taken: false });

            if (cell.onPick) {
                button.addEventListener('click', () => cell.onPick(seat));
            }

            line.appendChild(button);
        });

        if (isExit) {
            line.appendChild(appendEdge(line, 'EXIT', 'arcs-seat-exit'));
        } else {
            line.appendChild(appendEdge(line, String(rowNo)));
        }

        map.appendChild(line);
    });
}

/**
 * Apply gate seat states onto an already-drawn map (polled every 10 s).
 * states: { "14C": "Boarded", ... }
 */
export function applySeatStates(map, states) {
    map.querySelectorAll('button[data-seat-no]').forEach((button) => {
        const status = states[button.dataset.seatNo];
        const style = status ? statusToStyle(status) : (button.dataset.freeStyle || 'economy');
        const previous = button.dataset.style;
        button.dataset.style = style;
        button.className = `transition-transform ${seatStyles[style] ?? seatStyles.taken}`;
        if (previous !== style && style === 'boarded') {
            button.classList.add('seat-pulse');
        }
    });
}
