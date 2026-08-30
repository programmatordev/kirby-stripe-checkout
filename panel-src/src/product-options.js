export function stableId() {
	const alphabet = "abcdefghijklmnopqrstuvwxyz0123456789";
	let id = "";

	while (id.length < 16) {
		const bytes = new Uint8Array(16);
		globalThis.crypto.getRandomValues(bytes);

		for (const byte of bytes) {
			// 252 is the largest multiple of 36 below 256, avoiding modulo bias.
			if (byte < 252) {
				id += alphabet[byte % alphabet.length];
			}

			if (id.length === 16) {
				break;
			}
		}
	}

	return id;
}

export function resolveStableId(temporaryId, ids, createId = stableId) {
	if (typeof temporaryId === "string" && temporaryId !== "") {
		if (ids.has(temporaryId) === false) {
			ids.set(temporaryId, createId());
		}

		return ids.get(temporaryId);
	}

	return createId();
}

export function decimalString(value) {
	if (value === null || value === "") {
		return null;
	}

	// Kirby's Number field emits a JavaScript number, while canonical money
	// remains a decimal string and is validated again by the PHP boundary.
	if (typeof value === "number") {
		return Number.isFinite(value) ? String(value) : null;
	}

	return typeof value === "string" ? value : null;
}

export function formatCurrency(value, currency, locale = "en") {
	return new Intl.NumberFormat(locale, {
		currency,
		style: "currency"
	}).format(Number(value));
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

export function matchVariant(variants, selectedOptions) {
	const submitted = Object.entries(selectedOptions)
		.sort(([left], [right]) => left.localeCompare(right));

	return variants.find(
		variant => {
			const canonical = Object.entries(variant.selectedOptions)
				.sort(([left], [right]) => left.localeCompare(right));

			return variant.enabled === true &&
				canonical.length === submitted.length &&
				canonical.every(([optionId, valueId], index) =>
					optionId === submitted[index][0] && valueId === submitted[index][1]
				);
		}
	) ?? null;
}
