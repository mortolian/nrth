export type TripMapFields = {
    start_latitude?: number | null;
    start_longitude?: number | null;
    end_latitude?: number | null;
    end_longitude?: number | null;
    from_location?: string | null;
    to_location?: string | null;
};

/**
 * Google Maps directions (or place search) URL for a trip.
 * Prefers coordinates; falls back to from/to addresses.
 */
export function tripGoogleMapsUrl(trip: TripMapFields): string | null {
    const hasStart = trip.start_latitude != null && trip.start_longitude != null;
    const hasEnd = trip.end_latitude != null && trip.end_longitude != null;

    if (hasStart && hasEnd) {
        return (
            'https://www.google.com/maps/dir/?api=1'
            + `&origin=${trip.start_latitude},${trip.start_longitude}`
            + `&destination=${trip.end_latitude},${trip.end_longitude}`
            + '&travelmode=driving'
        );
    }

    if (hasStart) {
        return `https://www.google.com/maps/search/?api=1&query=${trip.start_latitude},${trip.start_longitude}`;
    }

    if (hasEnd) {
        return `https://www.google.com/maps/search/?api=1&query=${trip.end_latitude},${trip.end_longitude}`;
    }

    const from = trip.from_location?.trim() || '';
    const to = trip.to_location?.trim() || '';

    if (from !== '' && to !== '') {
        return (
            'https://www.google.com/maps/dir/?api=1'
            + `&origin=${encodeURIComponent(from)}`
            + `&destination=${encodeURIComponent(to)}`
            + '&travelmode=driving'
        );
    }

    const query = from || to;
    if (query !== '') {
        return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(query)}`;
    }

    return null;
}

export function openTripOnGoogleMaps(trip: TripMapFields): boolean {
    const url = tripGoogleMapsUrl(trip);
    if (url === null) {
        return false;
    }
    window.open(url, '_blank', 'noopener,noreferrer');

    return true;
}

export function tripHasMapLink(trip: TripMapFields): boolean {
    return tripGoogleMapsUrl(trip) !== null;
}
