import { stableId } from "./synchronized-structure.js";

export function formatAmount(value, currency) {
	const amount = String(value);
	const match = amount.match(/^([0-9]+)(?:\.([0-9]+))?$/);

	if (match === null) {
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

export function optionCombinationKey(selectedOptions) {
	return Object.entries(selectedOptions)
		.sort(([left], [right]) => left.localeCompare(right))
		.map(([optionId, valueId]) => `${optionId}:${valueId}`)
		.join("|");
}

export function combinations(options) {
	if (options.length === 0) {
		return [];
	}

	return options.reduce(
		(result, option) =>
			result.flatMap(combination =>
				option.values.map(value => ({
					...combination,
					[option.id]: value.id
				}))
			),
		[{}]
	);
}

export function reconcile(options, variants, createId = stableId) {
	const existing = new Map(
		variants.map(variant => [optionCombinationKey(variant.selectedOptions), variant])
	);

	return combinations(options).map(selectedOptions => {
		const variant = existing.get(optionCombinationKey(selectedOptions));

		return variant ?? {
			id: createId(),
			selectedOptions,
			enabled: true,
			sku: null,
			price: null,
			stripePriceId: null,
			requiresShipping: "inherit"
		};
	});
}

export function importPreset(preset, createId = stableId) {
	return (preset.options ?? []).map(option => ({
		id: createId(),
		label: option.label,
		values: (option.values ?? []).map(label => ({ id: createId(), label }))
	}));
}
