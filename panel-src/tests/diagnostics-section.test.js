import assert from "node:assert/strict";
import test from "node:test";
import { componentOptions } from "./support/component-options.js";

const diagnostics = await componentOptions("DiagnosticsSection");

test("diagnostic actions expose the active request with Kirby's native button state", () => {
	const endpoint = "stripe-checkout/diagnostics/prices/refresh";
	const section = {
		requestingEndpoint: null,
		sections: [{
			name: "catalogues",
			items: [{
				buttons: [{
					endpoint,
					icon: "refresh",
					successMessage: "Refreshed",
					title: "Refresh"
				}]
			}]
		}],
		itemButtons(item) {
			return diagnostics.methods.itemButtons.call(this, item);
		},
		request() {}
	};

	let [button] = diagnostics.computed.displaySections.call(section)[0].items[0].buttons;
	assert.equal(button.disabled, false);
	assert.equal(button.icon, "refresh");

	section.requestingEndpoint = endpoint;
	[button] = diagnostics.computed.displaySections.call(section)[0].items[0].buttons;
	assert.equal(button.disabled, true);
	assert.equal(button.icon, "loader");

	section.requestingEndpoint = "stripe-checkout/diagnostics/tax-codes/refresh";
	[button] = diagnostics.computed.displaySections.call(section)[0].items[0].buttons;
	assert.equal(button.disabled, true);
	assert.equal(button.icon, "refresh");
});
