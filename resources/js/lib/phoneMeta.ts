import isoCallingCodes from '../data/isoCallingCodes.json';

const ISO_TO_DIAL = isoCallingCodes as Record<string, string>;

const NANP_PRIORITY = [
    'US',
    'CA',
    'PR',
    'JM',
    'BB',
    'BS',
    'AG',
    'AI',
    'BM',
    'DM',
    'GD',
    'KY',
    'KN',
    'LC',
    'MS',
    'TC',
    'TT',
    'VC',
    'VG',
    'VI',
    'GU',
    'AS',
    'MP',
];

const DIAL_TO_ISOS = new Map<string, string[]>();

for (const [iso, dial] of Object.entries(ISO_TO_DIAL)) {
    if (!DIAL_TO_ISOS.has(dial)) {
        DIAL_TO_ISOS.set(dial, []);
    }
    DIAL_TO_ISOS.get(dial)!.push(iso);
}

for (const [dial, isos] of DIAL_TO_ISOS) {
    if (dial === '1') {
        isos.sort((a, b) => {
            const ia = NANP_PRIORITY.indexOf(a);
            const ib = NANP_PRIORITY.indexOf(b);
            if (ia === -1 && ib === -1) {
                return a.localeCompare(b);
            }
            if (ia === -1) {
                return 1;
            }
            if (ib === -1) {
                return -1;
            }

            return ia - ib;
        });
    } else {
        isos.sort();
    }
}

const DIALS_LONGEST_FIRST = [...new Set(Object.values(ISO_TO_DIAL))].sort((a, b) => b.length - a.length);

export function countryFlag(iso2: string): string {
    const u = iso2.toUpperCase();
    if (u.length !== 2) {
        return '';
    }

    return String.fromCodePoint(...[...u].map((c) => 127397 + c.charCodeAt(0)));
}

export function getDialForIso(iso: string): string {
    return ISO_TO_DIAL[iso.toUpperCase()] ?? '';
}

export function allIsoCodesSorted(preferred: readonly string[]): string[] {
    const all = Object.keys(ISO_TO_DIAL);
    const pref = preferred.filter((p) => all.includes(p));
    const rest = all.filter((c) => !pref.includes(c)).sort();

    return [...pref, ...rest];
}

/**
 * Parse full international number digits (no +), e.g. "27821234567".
 */
export function parseE164Digits(fullDigits: string): { iso: string; nationalDigits: string } | null {
    const d = fullDigits.replace(/\D/g, '');
    if (!d) {
        return null;
    }
    for (const dial of DIALS_LONGEST_FIRST) {
        if (d.startsWith(dial)) {
            const isos = DIAL_TO_ISOS.get(dial);
            if (!isos?.length) {
                continue;
            }
            const nationalDigits = d.slice(dial.length);

            return { iso: isos[0], nationalDigits };
        }
    }

    return null;
}

export function buildE164(iso: string, nationalInput: string): string {
    const dial = getDialForIso(iso);
    if (!dial) {
        return '';
    }
    let nat = nationalInput.replace(/\D/g, '');
    if (nat.startsWith('0')) {
        nat = nat.slice(1);
    }
    if (!nat) {
        return '';
    }

    return `+${dial}${nat}`;
}
