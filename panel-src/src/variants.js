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

export function choiceKey(choices) {
	return Object.entries(choices)
		.sort(([left], [right]) => left.localeCompare(right))
		.map(([groupId, valueId]) => `${groupId}:${valueId}`)
		.join("|");
}

export function combinations(groups) {
	if (groups.length === 0) {
		return [];
	}

	return groups.reduce(
		(result, group) =>
			result.flatMap(combination =>
				group.values.map(value => ({
					...combination,
					[group.id]: value.id
				}))
			),
		[{}]
	);
}

export function reconcile(groups, variants, createId = stableId) {
	const existing = new Map(
		variants.map(variant => [choiceKey(variant.choices), variant])
	);

	return combinations(groups).map(choices => {
		const variant = existing.get(choiceKey(choices));

		return variant ?? {
			id: createId(),
			choices,
			enabled: true,
			sku: null,
			price: null,
			stripePriceId: null,
			requiresShipping: "inherit"
		};
	});
}

export function importPreset(preset, createId = stableId) {
	return (preset.groups ?? []).map(group => ({
		id: createId(),
		label: group.label,
		values: (group.values ?? []).map(label => ({ id: createId(), label }))
	}));
}

export function matchVariant(variants, choices) {
	const submitted = Object.entries(choices)
		.sort(([left], [right]) => left.localeCompare(right));

	return variants.find(
		variant => {
			const canonical = Object.entries(variant.choices)
				.sort(([left], [right]) => left.localeCompare(right));

			return variant.enabled === true &&
				canonical.length === submitted.length &&
				canonical.every(([groupId, valueId], index) =>
					groupId === submitted[index][0] && valueId === submitted[index][1]
				);
		}
	) ?? null;
}
