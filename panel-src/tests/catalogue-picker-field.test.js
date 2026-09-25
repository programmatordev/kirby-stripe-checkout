import assert from "node:assert/strict";
import test from "node:test";
import { componentOptions } from "./support/component-options.js";

const component = await componentOptions("CataloguePickerField");

test("selection warnings remain visible in read-only translations and hide for an inactive source", () => {
	const field = {
		localSelected: { warning: "A performance location is required." },
		sourceInactive: false,
		disabled: true
	};
	assert.equal(component.computed.selectionWarning.call(field), field.localSelected.warning);
	field.sourceInactive = true;
	assert.equal(component.computed.selectionWarning.call(field), null);
	field.sourceInactive = false;
	field.localSelected = null;
	assert.equal(component.computed.selectionWarning.call(field), null);
});

for (const fails of [false, true]) {
	test(`server-selected props supersede an earlier hydration ${fails ? "failure" : "response"}`, async () => {
		let resolve;
		let reject;
		const pendingResponse = new Promise((success, failure) => {
			resolve = success;
			reject = failure;
		});
		const current = { id: "txcd_current", text: "Current classification" };
		const field = {
			catalogueReadable: true,
			apiEndpoint: "/field",
			hydrationRequestId: 0,
			hydrating: false,
			localCatalogue: { status: "ready" },
			localSelected: null,
			$api: { get: () => pendingResponse },
			fallback: () => ({ id: "txcd_old", text: "Old fallback" })
		};
		field.hydrate = value => component.methods.hydrate.call(field, value);
		const hydration = field.hydrate("txcd_old");
		component.watch.selected.call(field, current);
		component.watch.value.call(field, current.id);

		if (fails) {
			reject(new Error("Earlier read failed"));
		} else {
			resolve({ catalogue: { status: "stale" }, data: [{ id: "txcd_old", text: "Old classification" }] });
		}

		await hydration;
		assert.equal(field.localSelected, current);
		assert.deepEqual(field.localCatalogue, { status: "ready" });
		assert.equal(field.hydrating, false);
	});
}
