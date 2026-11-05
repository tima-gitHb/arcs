/**
 * The ARCS seat map, shared by reservation (R1) and check-in (C3).
 * Seat data comes from App\Services\SeatService::seatMap(): {id, seat_no, row, letter, cabin, taken}.
 * The panel around it is the <x-seat-map> Blade component.
 *
 * drawSeatMap(map, seats, cellFor):
 *   map     – the element to draw into (emptied first)
 *   seats   – the seat list, already in row order
 *   cellFor – (seat) => ({ style, disabled, onPick }) where style is a key of seatStyles
 */
export const seatStyles = {
    business: 'bg-amber-200 text-amber-950 hover:bg-amber-100',
    premium: 'bg-violet-200 text-violet-950 hover:bg-violet-100',
    economy: 'bg-white text-navy hover:bg-sky-100',
    taken: 'cursor-not-allowed bg-slate-600 text-slate-400',
    selected: 'bg-emerald-400 text-emerald-950',
    mine: 'bg-emerald-400 text-emerald-950 ring-2 ring-white',
};

const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F'];
const GRID = 'grid-cols-[1.5rem_repeat(3,1fr)_1rem_repeat(3,1fr)]';
const WING_AFTER_ROW = 12;

/**
 * Default look of a seat: its cabin colour, or blocked if taken.
 */
export function cabinStyle(seat) {
    if (seat.taken) {
        return 'taken';
    }

    return { Business: 'business', Premium: 'premium' }[seat.cabin] ?? 'economy';
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
            button.className = `h-7 rounded text-[11px] font-semibold ${seatStyles[cell.style]}`;
            button.title = seat.taken ? `${seat.seat_no} taken` : seat.seat_no;
            button.dataset.seatNo = seat.seat_no;

            if (cell.onPick) {
                button.addEventListener('click', () => cell.onPick(seat));
            }

            line.appendChild(button);
        });

        map.appendChild(line);

        if (rowNo === WING_AFTER_ROW) {
            const wing = document.createElement('div');
            wing.className = 'my-2 h-2 rounded-full bg-white/20';
            map.appendChild(wing);
        }
    });
}
