/**
 * The ARCS plane seat map, shared by reservation, check-in and gate.
 * Seat data: SeatService::seatMap() → {id, seat_no, row, letter, cabin, taken, exit?}.
 * Gate may also pass passenger states via data-seat-states / applySeatStates().
 *
 * Horizontal layout (V2): nose left, rows left→right, letters A–F on the left.
 *
 * drawSeatMap(map, seats, cellFor):
 *   cellFor(seat) => ({ style, disabled, onPick, label?, title?, blocked? })
 *   style is a key of seatStyles
 *
 * Class name strings keep bg-violet-200 / cabin keys for B12Sprint2BaseTest.
 */
export const seatStyles = {
    business: 'bg-amber-200 text-amber-950 arcs-seat arcs-seat-business',
    premium: 'bg-violet-200 text-violet-950 arcs-seat arcs-seat-premium',
    economy: 'bg-white text-navy arcs-seat arcs-seat-free',
    taken: 'cursor-not-allowed bg-slate-600 text-slate-400 arcs-seat arcs-seat-taken',
    selected: 'bg-emerald-400 text-emerald-950 arcs-seat arcs-seat-party',
    mine: 'bg-emerald-400 text-emerald-950 ring-2 ring-white seat-pulse arcs-seat arcs-seat-selected',

    booked: 'bg-sky-300 text-sky-950 arcs-seat arcs-seat-booked',
    checked_in: 'bg-amber-300 text-amber-950 arcs-seat arcs-seat-checked',
    boarded: 'bg-emerald-400 text-emerald-950 arcs-seat arcs-seat-boarded',
    no_show: 'bg-rose-400 text-rose-950 arcs-seat',
    legroom: 'bg-white text-navy arcs-seat arcs-seat-free arcs-seat-legroom',
    blocked: 'arcs-seat arcs-seat-blocked',
    party: 'bg-teal-400 text-white arcs-seat arcs-seat-party',
};

const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F'];
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

function cabinTone(cabin) {
    return { Business: 'business', Premium: 'premium' }[cabin] ?? 'economy';
}

function isExitRow(rowNo, seatsInRow) {
    if (EXIT_ROWS.has(rowNo)) {
        return true;
    }

    return seatsInRow.some((seat) => seat.exit);
}

function defaultTitle(seat, style, isExit, isLegroom) {
    if (seat.taken || style === 'taken') {
        return `${seat.seat_no} · taken`;
    }

    const bits = [seat.seat_no, seat.cabin || 'Economy'];
    if (isExit) {
        bits.push('exit row');
    }
    if (isLegroom || style === 'legroom') {
        bits.push('extra legroom');
    }
    if (style === 'blocked') {
        bits.push('not allowed');
    }

    return bits.join(' · ');
}

/**
 * Horizontal whole-cabin map: row numbers across the top, letters A–F on the left.
 */
export function drawSeatMap(map, seats, cellFor) {
    map.replaceChildren();
    map.classList.add('arcs-seat-map-h');

    const byRow = new Map();
    seats.forEach((seat) => {
        if (! byRow.has(seat.row)) {
            byRow.set(seat.row, []);
        }
        byRow.get(seat.row).push(seat);
    });

    const rowNos = [...byRow.keys()].sort((left, right) => left - right);
    if (rowNos.length === 0) {
        return;
    }

    // Cabin spans for the band above the row numbers.
    const cabinSpans = [];
    let spanStart = 0;
    for (let i = 0; i < rowNos.length; i++) {
        const cabin = byRow.get(rowNos[i]).find((s) => s.cabin)?.cabin ?? 'Economy';
        const nextCabin = i + 1 < rowNos.length
            ? (byRow.get(rowNos[i + 1]).find((s) => s.cabin)?.cabin ?? 'Economy')
            : null;
        if (cabin !== nextCabin) {
            cabinSpans.push({ cabin, from: spanStart, to: i });
            spanStart = i + 1;
        }
    }

    const shell = document.createElement('div');
    shell.className = 'arcs-seat-h-shell';

    const letterCol = document.createElement('div');
    letterCol.className = 'arcs-seat-h-letters';
    letterCol.appendChild(document.createElement('div')); // align with cabin band
    letterCol.appendChild(document.createElement('div')); // align with row numbers
    LETTERS.forEach((letter, index) => {
        if (index === 3) {
            const aisle = document.createElement('div');
            aisle.className = 'arcs-seat-h-letter-aisle';
            letterCol.appendChild(aisle);
        }
        const lab = document.createElement('div');
        lab.className = 'arcs-seat-h-letter';
        lab.textContent = letter;
        letterCol.appendChild(lab);
    });
    shell.appendChild(letterCol);

    const scroll = document.createElement('div');
    scroll.className = 'arcs-seat-h-scroll';

    const bands = document.createElement('div');
    bands.className = 'arcs-seat-h-bands';
    cabinSpans.forEach((span, index) => {
        const band = document.createElement('div');
        band.className = `arcs-seat-h-band arcs-seat-h-band-${cabinTone(span.cabin)}`;
        band.style.gridColumn = `${span.from + 1} / ${span.to + 2}`;
        if (index > 0) {
            band.style.marginLeft = '14px';
        }
        const label = document.createElement('span');
        label.className = 'arcs-seat-cabin-label';
        label.textContent = cabinLabelFor(span.cabin);
        band.appendChild(label);
        const line = document.createElement('span');
        line.className = 'arcs-seat-h-band-line';
        band.appendChild(line);
        bands.appendChild(band);
    });
    bands.style.gridTemplateColumns = `repeat(${rowNos.length}, var(--arcs-seat-col))`;
    scroll.appendChild(bands);

    const header = document.createElement('div');
    header.className = 'arcs-seat-h-rows arcs-seat-header';
    header.style.gridTemplateColumns = `repeat(${rowNos.length}, var(--arcs-seat-col))`;
    rowNos.forEach((rowNo) => {
        const cell = document.createElement('span');
        const exit = isExitRow(rowNo, byRow.get(rowNo));
        cell.className = `arcs-seat-rownum${exit ? ' arcs-seat-exit' : ''}`;
        cell.textContent = String(rowNo);
        header.appendChild(cell);
    });
    scroll.appendChild(header);

    const grid = document.createElement('div');
    grid.className = 'arcs-seat-h-grid';
    grid.style.gridTemplateColumns = `repeat(${rowNos.length}, var(--arcs-seat-col))`;

    LETTERS.forEach((letter, letterIndex) => {
        if (letterIndex === 3) {
            rowNos.forEach((rowNo) => {
                const aisle = document.createElement('div');
                aisle.className = 'arcs-seat-aisle';
                if (isExitRow(rowNo, byRow.get(rowNo))) {
                    aisle.classList.add('arcs-seat-exit');
                    aisle.textContent = 'EXIT';
                }
                grid.appendChild(aisle);
            });
        }

        rowNos.forEach((rowNo) => {
            const rowSeats = byRow.get(rowNo);
            const seat = rowSeats.find((item) => item.letter === letter);
            const cellWrap = document.createElement('div');
            cellWrap.className = 'arcs-seat-h-cell';

            const button = document.createElement('button');
            button.type = 'button';

            if (! seat) {
                button.disabled = true;
                button.className = 'arcs-seat opacity-0';
                cellWrap.appendChild(button);
                grid.appendChild(cellWrap);

                return;
            }

            const cell = cellFor(seat) || {};
            let style = cell.style || cabinStyle(seat);
            const exit = isExitRow(rowNo, rowSeats) || Boolean(seat.exit);
            const wantLegroom = (style === 'economy' || style === 'legroom')
                && exit
                && ! seat.taken
                && style !== 'blocked'
                && style !== 'mine'
                && style !== 'selected'
                && style !== 'party';

            if (wantLegroom) {
                style = 'legroom';
            }
            if (cell.blocked) {
                style = 'blocked';
            }

            // Keep clickable when onPick handles taken / not-allowed messages (disabled blocks clicks).
            button.disabled = Boolean(cell.disabled) && ! cell.onPick;
            if (cell.disabled) {
                button.setAttribute('aria-disabled', 'true');
            }
            button.className = `transition-transform ${seatStyles[style] ?? seatStyles.taken}`;
            button.textContent = cell.label ?? (style === 'taken' || style === 'blocked' ? '' : letter);
            if (style === 'boarded' && ! cell.label) {
                button.textContent = '✓';
            }
            button.title = cell.title || defaultTitle(seat, style, exit, style === 'legroom');
            button.dataset.seatNo = seat.seat_no;
            button.dataset.style = style;
            button.dataset.freeStyle = cabinStyle({ ...seat, taken: false });

            if (cell.onPick) {
                button.addEventListener('click', () => cell.onPick(seat));
            }

            cellWrap.appendChild(button);
            grid.appendChild(cellWrap);
        });
    });

    scroll.appendChild(grid);
    shell.appendChild(scroll);
    map.appendChild(shell);
}

/**
 * Apply gate seat states onto an already-drawn map (polled every 10 s).
 * states: { "14C": "Boarded", ... }
 */
export function applySeatStates(map, states) {
    map.querySelectorAll('button[data-seat-no]').forEach((button) => {
        const status = states[button.dataset.seatNo];
        let style = status ? statusToStyle(status) : (button.dataset.freeStyle || 'economy');
        if (status === 'Boarded') {
            style = 'boarded';
            button.textContent = '✓';
        }
        const previous = button.dataset.style;
        button.dataset.style = style;
        button.className = `transition-transform ${seatStyles[style] ?? seatStyles.taken}`;
        if (previous !== style && style === 'boarded') {
            button.classList.add('seat-pulse');
        }
    });
}
