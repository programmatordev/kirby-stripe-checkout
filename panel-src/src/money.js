export function formatAmount(value, currency) {
	const amount = String(value);
	const match = amount.match(/^([0-9]+)(?:\.([0-9]+))?$/);

	if (match === null || typeof currency !== "string" || currency === "") {
		return amount;
	}

	const fractionDigits = new Intl.NumberFormat("en", {
		currency,
		style: "currency"
	}).resolvedOptions().minimumFractionDigits;
	const fraction = match[2] ?? "";

	// Pad the display string without converting exact money through a JS number.
	if (fraction.length > fractionDigits) {
		return amount;
	}

	if (fractionDigits === 0) {
		return match[1];
	}

	return `${match[1]}.${fraction.padEnd(fractionDigits, "0")}`;
}
