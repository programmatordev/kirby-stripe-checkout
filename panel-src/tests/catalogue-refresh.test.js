import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

// Match the existing plain-component tests; no Vue runtime or network is needed.
async function componentOptions(name) {
	const url = new URL(`../src/components/${name}.vue`, import.meta.url);
	const source = await readFile(url, "utf8");
	const script = source.split("<script>")[1].split("</script>")[0]
		.replace(/from "(\.\.\/[^\"]+)"/g, (_, path) => `from "${new URL(path, url).href}"`);
	return (await import(`data:text/javascript;base64,${Buffer.from(script).toString("base64")}`)).default;
}

const options = await componentOptions("OptionsField");
const priceDialog = await componentOptions("StripePriceDialog");

function deferred() {
	let resolve;
	let reject;
	const promise = new Promise((success, failure) => {
		resolve = success;
		reject = failure;
	});
	return { promise, resolve, reject };
}

function optionsField(get) {
	const field = {
		value: { options: [], variants: [] },
		clone: value => structuredClone(value),
		endpoints: { field: "/field" },
		priceSource: "stripe",
		pricesReadable: true,
		taxCodeEnabled: true,
		taxCodesReadable: true,
		$api: { get },
		$set: (items, id, item) => { items[id] = item; },
		$delete: (items, id) => { delete items[id]; }
	};
	Object.assign(field, options.data.call(field));
	return field;
}

const catalogues = [
	{
		name: "Tax Code",
		method: "hydrateTaxCodes",
		items: "localTaxCodeItems",
		id: "txcd_test",
		otherId: "txcd_other"
	},
	{
		name: "Stripe Price",
		method: "hydrateStripePrices",
		items: "localStripePriceItems",
		id: "price_test",
		otherId: "price_other"
	}
];

for (const { name, method, items, id, otherId } of catalogues) {
	for (const olderMissing of [false, true]) {
		test(`${name} hydration ignores older ${olderMissing ? "missing" : "metadata"} responses without dropping unrelated IDs`, async () => {
			const older = deferred();
			const newer = deferred();
			let reads = 0;
			const field = optionsField(() => (++reads === 1 ? older.promise : newer.promise));
			const oldRead = options.methods[method].call(field, [id, otherId]);
			const newRead = options.methods[method].call(field, [id]);
			const current = { id, text: "Current metadata", warning: "Current warning" };
			newer.resolve({ data: [current] });
			await newRead;
			const other = { id: otherId, text: "Other page item" };
			older.resolve({ data: olderMissing ? [other] : [{ id, text: "Old metadata" }, other] });
			await oldRead;

			assert.equal(field[items][id], current);
			assert.equal(field[items][otherId], other);
		});
	}

	test(`${name} an older response cannot restore details removed by a newer read`, async () => {
		const older = deferred();
		const newer = deferred();
		let reads = 0;
		const field = optionsField(() => (++reads === 1 ? older.promise : newer.promise));
		const oldRead = options.methods[method].call(field, [id], true);
		const newRead = options.methods[method].call(field, [id], true);
		newer.resolve({ data: [] });
		await newRead;
		older.resolve({ data: [{ id, text: "Removed details" }] });
		await oldRead;
		assert.equal(Object.hasOwn(field[items], id), false);
	});

	test(`${name} hydration removes confirmed missing details but retains them after a read failure`, async () => {
		const saved = { id, text: "Last-known details" };
		const field = optionsField(async () => { throw new Error("Cache read failed"); });
		field[items][id] = saved;
		await options.methods[method].call(field, [id], true);
		assert.equal(field[items][id], saved);
		field.$api.get = async () => ({ data: [] });
		await options.methods[method].call(field, [id], true);
		assert.equal(Object.hasOwn(field[items], id), false);
	});

	test(`${name} newer read failure still supersedes earlier successful metadata`, async () => {
		const older = deferred();
		const newer = deferred();
		let reads = 0;
		const field = optionsField(() => (++reads === 1 ? older.promise : newer.promise));
		const oldRead = options.methods[method].call(field, [id], true);
		const saved = { id, text: "Last-known details" };
		field[items][id] = saved;
		const newRead = options.methods[method].call(field, [id], true);
		newer.reject(new Error("New read failed"));
		await newRead;
		older.resolve({ data: [{ id, text: "Earlier details" }] });
		await oldRead;
		assert.equal(field[items][id], saved);
	});
}

test("saving a variant rehydrates the same Stripe Price ID while normal paging reuses its preview", async () => {
	const calls = [];
	const updated = { id: "price_test", text: "Updated product / Price" };
	const field = optionsField(async (endpoint, query) => {
		calls.push({ endpoint, query });
		return { data: [updated] };
	});
	field.localStripePriceItems.price_test = { id: "price_test", text: "Old product / Price" };
	await options.methods.hydrateStripePrices.call(field, ["price_test"]);
	assert.equal(calls.length, 0);
	const variant = { id: "variant", stripePriceId: "price_test" };
	field.localValue.variants = [variant];
	field.emit = () => {};
	let hydration;
	field.hydrateStripePrices = (...args) => {
		hydration = options.methods.hydrateStripePrices.call(field, ...args);
	};
	options.methods.updateVariantFromForm.call(field, variant, {
		enabled: true,
		stripePriceId: "price_test"
	});
	await hydration;
	assert.deepEqual(calls, [{
		endpoint: "/field/prices",
		query: { prices: "price_test", view: "selected" }
	}]);
	assert.equal(field.localStripePriceItems.price_test, updated);
});

test("table hydration respects catalogue permissions, inactive policies and empty IDs", async () => {
	const field = optionsField(() => { throw new Error("Unexpected cache read"); });
	field.pricesReadable = false;
	field.taxCodesReadable = false;
	await options.methods.hydrateStripePrices.call(field, ["price_test"], true);
	await options.methods.hydrateTaxCodes.call(field, ["txcd_test"]);
	field.pricesReadable = true;
	field.taxCodesReadable = true;
	field.priceSource = "kirby";
	field.taxCodeEnabled = false;
	await options.methods.hydrateStripePrices.call(field, ["price_test"], true);
	await options.methods.hydrateTaxCodes.call(field, ["txcd_test"]);
	field.priceSource = "stripe";
	field.taxCodeEnabled = true;
	await options.methods.hydrateStripePrices.call(field, [null, ""]);
	await options.methods.hydrateTaxCodes.call(field, [null, ""]);
});

function dialog(get) {
	const emitted = [];
	const errors = [];
	const dialog = {
		...priceDialog.data(),
		endpoint: "/prices",
		value: "price_saved",
		product: { id: "prod_test" },
		selected: { id: "price_chosen", text: "Old chosen Price" },
		query: "filtered search",
		$api: {
			post: async () => ({ catalogue: { status: "ready" } }),
			get
		},
		$panel: {
			dialog: { isLoading: false },
			error: error => errors.push(error),
			notification: { success: () => {} }
		},
		$t: key => key,
		$emit: (...args) => emitted.push(args)
	};
	dialog.applyResponse = response => priceDialog.methods.applyResponse.call(dialog, response);
	return { dialog, emitted, errors };
}

for (const removed of [false, true]) {
	test(`Price dialog refresh ${removed ? "clears a removed selection" : "updates a selection outside the current results"}`, async () => {
		const queries = [];
		const current = { id: "price_chosen", text: "Current chosen Price" };
		const { dialog: field, emitted, errors } = dialog(async (_, query) => {
			queries.push(query);
			return { data: query.view === "selected" && !removed ? [current] : [] };
		});
		await priceDialog.methods.refresh.call(field);
		assert.equal(field.selected, removed ? null : current);
		assert.deepEqual(queries, [
			{ page: 1, product: "prod_test", search: "filtered search", view: "prices" },
			{ price: "price_chosen", view: "selected" }
		]);
		priceDialog.methods.submit.call(field);
		assert.equal(emitted.filter(([event]) => event === "submit").length, removed ? 0 : 1);
		assert.equal(errors.length, 0);
		assert.equal(field.refreshing, false);
		assert.equal(field.$panel.dialog.isLoading, false);
	});
}

test("ordinary paginated or filtered Price results do not clear an existing selection", () => {
	const { dialog: field } = dialog();
	const selected = field.selected;
	field.applyResponse({ data: [], pagination: { page: 2 } });
	assert.equal(field.selected, selected);
});

test("Price dialog preserves last-known selection and reports a failed selected-ID read", async () => {
	const { dialog: field, errors } = dialog(async (_, query) => {
		if (query.view === "selected") {
			throw new Error("Selected cache read failed");
		}

		return { data: [] };
	});
	const selected = field.selected;
	await priceDialog.methods.refresh.call(field);
	assert.equal(field.selected, selected);
	assert.equal(errors.length, 1);
	assert.equal(field.refreshing, false);
	assert.equal(field.$panel.dialog.isLoading, false);
});

test("Price dialog does not submit during refresh or overwrite a different choice made during the read", async () => {
	const pending = deferred();
	const { dialog: field, emitted } = dialog(async (_, query) => {
		return query.view === "selected" ? pending.promise : { data: [] };
	});
	const refresh = priceDialog.methods.refresh.call(field);
	priceDialog.methods.submit.call(field);
	assert.equal(emitted.some(([event]) => event === "submit"), false);
	// Let refresh begin the cache reads before changing the selection.
	await Promise.resolve();
	const different = { id: "price_different", text: "Different choice" };
	field.selected = different;
	pending.resolve({ data: [] });
	await refresh;
	assert.equal(field.selected, different);
});

test("Price dialog refresh without a choice makes no selected-ID request", async () => {
	const queries = [];
	const { dialog: field } = dialog(async (_, query) => {
		queries.push(query);
		return { data: [] };
	});
	field.selected = null;
	await priceDialog.methods.refresh.call(field);
	assert.equal(queries.length, 1);
	assert.equal(queries[0].view, "prices");
	assert.equal(field.selected, null);
});
