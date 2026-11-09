/**
 * The ARCS plane seat map, shared by reservation, check-in and gate.
 * Seat data: SeatService::seatMap() → {id, seat_no, row, letter, cabin, taken}.
 * Gate may also pass passenger states via data-seat-states / applySeatStates().
 *
 * drawSeatMap(map, seats, cellFor):
 *   cellFor(seat) => ({ style, disabled, onPick }) where style is a key of seatStyles
 */
export const seatStyles = {
    business: 'bg-amber-200 text-amber-950 hover:bg-amber-100',
    premium: 'bg-violet-200 text-violet-950 hover:bg-violet-100',
    economy: 'bg-white text-navy hover:bg-sky-100',
    taken: 'cursor-not-allowed bg-slate-600 text-slate-400',
    selected: 'bg-emerald-400 text-emerald-950',
    mine: 'bg-emerald-400 text-emerald-950 ring-2 ring-white seat-pulse',
    booked: 'bg-sky-300 text-sky-950',
    checked_in: 'bg-amber-300 text-amber-950',
    boarded: 'bg-emerald-400 text-emerald-950',
    no_show: 'bg-rose-400 text-rose-950',
};

const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F'];
const GRID = 'grid-cols-[1.5rem_repeat(3,1fr)_1rem_repeat(3,1fr)]';
const WING_AFTER_ROW = 12;

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

export function drawSeatMap(map, seats, cellFor) {
    map.replaceChildren();

    const byRow = new Map();
    seats.forEach((seat) => {
        if (!byRow.has(seat.row)) {
            byRow.set(seat.row, []);
        }

        byRow.get(seat.row).push(seat);
    });

    const header = document.createElement('div');
    header.className = `mb-2 grid ${GRID} gap-1 text-center text-[10px] uppercase text-white/50`;
    ['', 'A', 'B', 'C', '', 'D', 'E', 'F'].forEach((letter) => {
        const cell = document.createElement('span');
        cell.textContent = letter;
        header.appendChild(cell);
    });
    map.appendChild(header);

    [...byRow.keys()].sort((left, right) => left - right).forEach((rowNo) => {
        const line = document.createElement('div');
        line.className = `mb-1 grid ${GRID} items-center gap-1`;

        const number = document.createElement('span');
        number.className = 'text-center text-[10px] text-white/50';
        number.textContent = String(rowNo);
        line.appendChild(number);

        LETTERS.forEach((letter, position) => {
            if (position === 3) {
                line.appendChild(document.createElement('span'));
            }

            const seat = byRow.get(rowNo).find((item) => item.letter === letter);
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = letter;

            if (!seat) {
                button.disabled = true;
                button.className = 'h-7 rounded bg-white/5';
                line.appendChild(button);

                return;
            }

            const cell = cellFor(seat);
            button.disabled = Boolean(cell.disabled);
            button.className = `h-7 rounded text-[11px] font-semibold transition-transform ${seatStyles[cell.style] ?? seatStyles.taken}`;
            button.title = seat.taken ? `${seat.seat_no} taken` : seat.seat_no;
            button.dataset.seatNo = seat.seat_no;
            button.dataset.style = cell.style;
            button.dataset.freeStyle = cabinStyle({ ...seat, taken: false });

            if (cell.onPick) {
                button.addEventListener('click', () => cell.onPick(seat));
            }

            line.appendChild(button);
        });

        map.appendChild(line);

        if (rowNo === WING_AFTER_ROW) {
            const wing = document.createElement('div');
            wing.className = 'my-2 text-center text-[10px] uppercase tracking-widest text-white/30';
            wing.textContent = '········ wings ········';
            map.appendChild(wing);
        }
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
        button.className = `h-7 rounded text-[11px] font-semibold transition-transform ${seatStyles[style] ?? seatStyles.taken}`;
        if (previous !== style && style === 'boarded') {
            button.classList.add('seat-pulse');
        }
    });
}
