((root, factory) => {
    const calculator = factory();
    if (typeof module === 'object' && module.exports) module.exports = calculator;
    if (root) root.SideHouseBookingPriceCalculator = calculator;
})(typeof window !== 'undefined' ? window : globalThis, () => {
    const round = (value) => Math.round((value + Number.EPSILON) * 100) / 100;
    const minutes = (time) => {
        const [hour, minute] = String(time).split(':').map(Number);
        return hour * 60 + minute;
    };
    const datePlus = (date, offset) => {
        const value = new Date(`${date}T00:00:00Z`);
        value.setUTCDate(value.getUTCDate() + offset);
        return value.toISOString().slice(0, 10);
    };
    const dateTime = (date, time) => Date.parse(`${date}T${time}:00Z`);
    const hasOpenPlay = (rules, courtId, date, start, end) => (rules.open_play || []).some((event) => {
        if (event.court_id !== null && Number(event.court_id) !== Number(courtId)) return false;
        let eventStart = dateTime(event.date, event.start);
        let eventEnd = dateTime(event.date, event.end);
        if (eventEnd <= eventStart) eventEnd += 86400000;
        const slotStart = dateTime(date, start);
        let slotEnd = dateTime(date, end);
        if (slotEnd <= slotStart) slotEnd += 86400000;
        return slotStart < eventEnd && slotEnd > eventStart;
    });
    const interval = (rules, courtId, date, start, end) => {
        const court = (rules.courts || []).find((row) => Number(row.id) === Number(courtId));
        if (!court || !rules.step_minutes) return null;
        const startMinute = minutes(start);
        let endMinute = minutes(end);
        if (endMinute <= startMinute) endMinute += 1440;
        const slotPrices = [];
        for (let cursor = startMinute; cursor < endMinute; cursor += Number(rules.step_minutes)) {
            const slotEnd = Math.min(endMinute, cursor + Number(rules.step_minutes));
            const slotDate = datePlus(date, Math.floor(cursor / 1440));
            const slotStartTime = `${String(Math.floor((cursor % 1440) / 60)).padStart(2, '0')}:${String(cursor % 60).padStart(2, '0')}`;
            const slotEndTime = slotEnd >= 1440 ? '00:00' : `${String(Math.floor((slotEnd % 1440) / 60)).padStart(2, '0')}:${String(slotEnd % 60).padStart(2, '0')}`;
            if (hasOpenPlay(rules, courtId, slotDate, slotStartTime, slotEndTime)) return null;
            const hour = Math.floor((cursor % 1440) / 60);
            const peakStart = rules.peak_start_hour;
            const peakEnd = rules.peak_end_hour;
            const value = Number(rules.peak_adjustment_value || 0);
            const validPeak = peakStart !== null && peakStart !== undefined && peakEnd !== null && peakEnd !== undefined
                && Number(peakStart) !== Number(peakEnd) && value > 0
                && ['flat', 'percent'].includes(rules.peak_adjustment_type);
            const peak = validPeak && (Number(peakEnd) > Number(peakStart)
                ? hour >= Number(peakStart) && hour < Number(peakEnd)
                : hour >= Number(peakStart) || hour < Number(peakEnd));
            let rate = Number(court.hourly_rate);
            if (peak) rate = rules.peak_adjustment_type === 'percent'
                ? round(rate * (1 + value / 100)) : round(rate + value);
            slotPrices.push(round(rate * ((slotEnd - cursor) / 60)));
        }
        return { total: round(slotPrices.reduce((sum, value) => sum + value, 0)), slotPrices };
    };
    const calculateMove = (rules, booking, target) => {
        if (!booking || !Array.isArray(booking.slots) || booking.slots.length === 0) return null;
        const oldSlotTotal = round(booking.slots.reduce((sum, slot) => sum + Number(slot.price || 0), 0));
        const equipmentTotal = round((booking.equipment || []).reduce((sum, line) => sum + Number(line.price_each || 0) * Number(line.quantity || 0), 0));
        const currentAmount = Number(booking.amount);
        if (!Number.isFinite(currentAmount) || Math.abs(round(oldSlotTotal + equipmentTotal) - currentAmount) >= 0.005) return null;
        for (const slot of booking.slots) {
            if (hasOpenPlay(rules, booking.court_id, slot.date || booking.date, slot.start, slot.end)) return null;
            const prior = interval(rules, booking.court_id, slot.date || booking.date, slot.start, slot.end);
            if (!prior || Math.abs(prior.total - Number(slot.price || 0)) >= 0.005) return null;
        }
        const priced = interval(rules, target.court_id ?? booking.court_id, target.date, target.start, target.end);
        if (!priced) return null;
        return round(currentAmount - oldSlotTotal + priced.total);
    };
    return { interval, calculateMove };
});
