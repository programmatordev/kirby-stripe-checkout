import assert from "node:assert/strict";
import test from "node:test";

import {
	optionCombinationKey,
	combinations,
	decimalString,
	formatCurrency,
	importPreset,
	matchVariant,
	reconcile,
	resolveStableId,
	stableId
} from "../src/product-options.js";

const options = [
	{
		id: "colour",
		label: "Colour",
		values: [
			{ id: "red", label: "Red" },
			{ id: "blue", label: "Blue" }
		]
	},
	{
		id: "size",
		label: "Size",
		values: [
			{ id: "small", label: "Small" },
			{ id: "large", label: "Large" }
		]
	}
];

test("generates compact stable identifiers without collisions", () => {
	const ids = Array.from({ length: 100 }, stableId);

	assert.ok(ids.every(id => /^[a-z0-9]{16}$/.test(id)));
	assert.equal(new Set(ids).size, ids.length);
});

test("maps Kirby Structure helper IDs to stable plugin IDs", () => {
	const ids = new Map([["existing", "existing"]]);
	let generated = 0;
	const createId = () => `generated-${++generated}`;

	assert.equal(resolveStableId("existing", ids, createId), "existing");
	assert.equal(resolveStableId("temporary-uuid", ids, createId), "generated-1");
	assert.equal(resolveStableId("temporary-uuid", ids, createId), "generated-1");
	assert.equal(generated, 1);
});

test("normalizes Kirby number-field output without losing zero", () => {
	assert.equal(decimalString(16.5), "16.5");
	assert.equal(decimalString(0), "0");
	assert.equal(decimalString("16.50"), "16.50");
	assert.equal(decimalString(""), null);
	assert.equal(decimalString(null), null);
	assert.equal(decimalString(Number.NaN), null);
});

test("formats storefront prices with the currency's standard decimal digits", () => {
	assert.equal(formatCurrency("16", "EUR", "en-US"), "€16.00");
	assert.equal(formatCurrency("16", "JPY", "ja-JP"), "￥16");
	assert.equal(formatCurrency("16", "KWD", "en-US"), "KWD\u00a016.000");
});

test("generates the Cartesian product of option values", () => {
	assert.deepEqual(combinations(options), [
		{ colour: "red", size: "small" },
		{ colour: "red", size: "large" },
		{ colour: "blue", size: "small" },
		{ colour: "blue", size: "large" }
	]);
});

test("generates unique combinations across representative matrix shapes", () => {
	const cases = [
		{ sizes: [], expected: 0 },
		{ sizes: [1], expected: 1 },
		{ sizes: [3], expected: 3 },
		{ sizes: [2, 3], expected: 6 },
		{ sizes: [2, 3, 4], expected: 24 },
		{ sizes: [10, 5], expected: 50 },
		{ sizes: [3, 0, 2], expected: 0 }
	];

	for (const { sizes, expected } of cases) {
		const matrixOptions = sizes.map((size, optionIndex) => ({
			id: `option-${optionIndex}`,
			label: `Option ${optionIndex}`,
			values: Array.from({ length: size }, (_, valueIndex) => ({
				id: `value-${optionIndex}-${valueIndex}`,
				label: `Value ${valueIndex}`
			}))
		}));
		const generated = combinations(matrixOptions);

		assert.equal(generated.length, expected, sizes.join("×") || "empty");
		assert.equal(new Set(generated.map(optionCombinationKey)).size, expected);
	}
});

test("reconciliation preserves matching merchant data and creates only missing variants", () => {
	const existing = [{
		id: "kept-variant",
		selectedOptions: { size: "small", colour: "red" },
		enabled: false,
		sku: "RED-S",
		price: "20.00",
		stripePriceId: null,
		requiresShipping: "yes"
	}];
	let nextId = 0;
	const variants = reconcile(options, existing, () => `new-${++nextId}`);

	assert.equal(variants.length, 4);
	assert.deepEqual(variants[0], existing[0]);
	assert.deepEqual(variants.slice(1).map(variant => variant.id), ["new-1", "new-2", "new-3"]);

	for (const variant of variants.slice(1)) {
		assert.equal(variant.enabled, true);
		assert.equal(variant.price, null);
		assert.equal(variant.stripePriceId, null);
		assert.equal(variant.requiresShipping, "inherit");
	}
});

test("reconciliation preserves identities through reorder, addition, and removal", () => {
	let nextId = 0;
	const initial = reconcile(options, [], () => `variant-${++nextId}`);
	const initialIds = new Map(initial.map(variant => [optionCombinationKey(variant.selectedOptions), variant.id]));
	const reorderedOptions = [
		{ ...options[1], values: [...options[1].values].reverse() },
		{ ...options[0], values: [...options[0].values].reverse() }
	];
	const reordered = reconcile(reorderedOptions, initial, () => {
		throw new Error("Reordering must not create variants");
	});

	assert.deepEqual(
		new Map(reordered.map(variant => [optionCombinationKey(variant.selectedOptions), variant.id])),
		initialIds
	);

	const expandedOptions = [
		{
			...options[0],
			values: [...options[0].values, { id: "green", label: "Green" }]
		},
		options[1]
	];
	const expanded = reconcile(expandedOptions, initial, () => `variant-${++nextId}`);

	assert.equal(expanded.length, 6);
	assert.equal(expanded.filter(variant => initialIds.has(optionCombinationKey(variant.selectedOptions))).length, 4);

	const reducedOptions = [
		{ ...options[0], values: [options[0].values[0]] },
		options[1]
	];
	const reduced = reconcile(reducedOptions, expanded, () => {
		throw new Error("Removing values must not create variants");
	});

	assert.equal(reduced.length, 2);
	assert.ok(reduced.every(variant => variant.selectedOptions.colour === "red"));
	assert.ok(reduced.every(variant => initialIds.get(optionCombinationKey(variant.selectedOptions)) === variant.id));
});

test("option matching ignores object key order and rejects disabled variants", () => {
	const variants = [
		{ id: "enabled", selectedOptions: { colour: "red", size: "small" }, enabled: true },
		{ id: "disabled", selectedOptions: { colour: "blue", size: "large" }, enabled: false }
	];

	assert.equal(optionCombinationKey({ size: "small", colour: "red" }), "colour:red|size:small");
	assert.equal(matchVariant(variants, { size: "small", colour: "red" })?.id, "enabled");
	assert.equal(matchVariant(variants, { size: "large", colour: "blue" }), null);
});

test("option matching rejects delimiter collisions and incomplete selections", () => {
	const variants = [{
		id: "canonical",
		selectedOptions: { colour: "red", size: "small" },
		enabled: true
	}];

	assert.equal(matchVariant(variants, { colour: "red|size:small" }), null);
	assert.equal(matchVariant(variants, { colour: "red" }), null);
});

test("preset imports are independent copies with fresh local IDs", () => {
	const preset = {
		label: "T-shirt",
		options: [{ label: "Size", values: ["Small", "Large"] }]
	};
	let id = 0;
	const first = importPreset(preset, () => `first-${++id}`);
	id = 0;
	const second = importPreset(preset, () => `second-${++id}`);

	assert.deepEqual(first[0].values.map(value => value.label), ["Small", "Large"]);
	assert.notEqual(first[0].id, second[0].id);
	assert.notEqual(first[0].values[0].id, second[0].values[0].id);
});
