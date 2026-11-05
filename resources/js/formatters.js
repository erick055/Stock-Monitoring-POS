const decimals = new Intl.NumberFormat('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const integers = new Intl.NumberFormat('en-PH', { maximumFractionDigits: 0 });
export function currency(amount) {
    const value = Number(String(amount).replaceAll(',', ''));
    return `${value < 0 ? '-' : ''}₱${decimals.format(Math.abs(value))}`;
}
export const money = { format: currency };
export function plural(amount, singular, pluralWord = `${singular}s`) {
    const value = Number(String(amount).replaceAll(',', ''));
    return `${integers.format(value)} ${Math.abs(value) === 1 ? singular : pluralWord}`;
}
