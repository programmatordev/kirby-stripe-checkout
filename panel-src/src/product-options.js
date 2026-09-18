import { stableId } from "./synchronized-structure.js";

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
			requiresShipping: "inherit",
			taxCode: null
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
