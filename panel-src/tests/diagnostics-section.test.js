import assert from "node:assert/strict";
import test from "node:test";
import { componentOptions } from "./support/component-options.js";

const diagnostics = await componentOptions("DiagnosticsSection");

function deferred() {
	let resolve;
	const promise = new Promise(resolvePromise => {
		resolve = resolvePromise;
	});

	return { promise, resolve };
}

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

test("diagnostic requests reload the section and report success", async () => {
	const deferredRequest = deferred();
	const postedEndpoints = [];
	const successMessages = [];
	let fetches = 0;
	const section = {
		requestingEndpoint: null,
		$api: {
			post(endpoint) {
				postedEndpoints.push(endpoint);
				return deferredRequest.promise;
			}
		},
		$panel: {
			notification: {
				success(message) {
					successMessages.push(message);
				}
			}
		},
		async fetch() {
			fetches++;
		}
	};
	const endpoint = "stripe-checkout/diagnostics/prices/refresh";
	const pendingRequest = diagnostics.methods.request.call(section, endpoint, "Refreshed");

	assert.equal(section.requestingEndpoint, endpoint);

	await diagnostics.methods.request.call(
		section,
		"stripe-checkout/diagnostics/tax-codes/refresh",
		"Also refreshed"
	);
	assert.deepEqual(postedEndpoints, [endpoint]);

	deferredRequest.resolve();
	await pendingRequest;

	assert.equal(fetches, 1);
	assert.deepEqual(successMessages, ["Refreshed"]);
	assert.equal(section.requestingEndpoint, null);
});

test("failed diagnostic requests report the error and restore the actions", async () => {
	const error = new Error("Refresh failed");
	const reportedErrors = [];
	let fetches = 0;
	let successes = 0;
	const section = {
		requestingEndpoint: null,
		$api: {
			async post() {
				throw error;
			}
		},
		$panel: {
			error(reportedError) {
				reportedErrors.push(reportedError);
			},
			notification: {
				success() {
					successes++;
				}
			}
		},
		async fetch() {
			fetches++;
		}
	};

	await diagnostics.methods.request.call(
		section,
		"stripe-checkout/diagnostics/prices/refresh",
		"Refreshed"
	);

	assert.equal(fetches, 0);
	assert.equal(successes, 0);
	assert.deepEqual(reportedErrors, [error]);
	assert.equal(section.requestingEndpoint, null);
});
