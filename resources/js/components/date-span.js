/**
 * A date worked out from another date.
 *
 * Opt in with `data-follows="<name of the controlling input>"` and
 * `data-span-days="<n>"` on a date input: it shows the controlling date plus
 * n − 1 days, capped at its own `max`. <x-field follows=… span-days=…> writes
 * both.
 *
 * WHY. The stock recon centre takes a from date and runs the week to date
 * from it (ZRP, 7 Oct 2026: "select a from date, and it auto calculates what
 * the week to date would be") — seven days, or up to today if that week is not
 * over. The person should see the end before pressing Preview, without a
 * round trip that throws away what they have typed.
 *
 * NO JAVASCRIPT IS STILL CORRECT. The end is display only: the server works it
 * out again from the from date (BalancePreviewRequest::to()), so without this
 * the field shows the default week's end and the run is still the right week.
 *
 * Dates as ISO strings in UTC throughout: a local-time Date would move a day
 * either side of midnight in any timezone but the one it was written in.
 */
export default function dateSpan() {
    document.querySelectorAll('input[data-follows]').forEach((target) => {
        const source = target.form?.querySelector(`[name="${target.dataset.follows}"]`);

        if (!source) return;

        const days = Math.max(1, Number(target.dataset.spanDays) || 1);

        const apply = () => {
            if (!/^\d{4}-\d{2}-\d{2}$/.test(source.value)) {
                target.value = '';
                return;
            }

            const end = new Date(`${source.value}T00:00:00Z`);
            end.setUTCDate(end.getUTCDate() + days - 1);

            let value = end.toISOString().slice(0, 10);

            // Never past the cap, and never before the day it follows.
            if (target.max && value > target.max) value = target.max < source.value ? source.value : target.max;

            target.value = value;
        };

        source.addEventListener('input', apply);
        source.addEventListener('change', apply);
        apply();
    });
}
