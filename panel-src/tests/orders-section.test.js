import assert from "node:assert/strict";
import test from "node:test";
import { componentOptions } from "./support/component-options.js";

const options = await componentOptions("OrdersSection");
const preview = await componentOptions("OrderTableValuePreview");

function location(href) {
	global.window = {
		history: {
			state: null,
			replaceState(state, title, url) {
				window.location.href = url.toString();
			}
		},
		location: { href }
	};
}

function section(get) {
	const section = {
		...options.data(),
		$api: { get },
		$t: key => key,
		name: "orders",
		parent: "/pages/stripe-checkout"
	};

	for (const [name, method] of Object.entries(options.methods)) {
		section[name] = (...args) => method.call(section, ...args);
	}

	return section;
}

test("Orders section loads filters and pagination through Kirby's section endpoint", async () => {
	location("https://example.test/panel/stripe-checkout?tab=orders");
	const calls = [];
	const response = {
		error: null,
		filters: {
			attention: "all",
			checkoutStatus: "complete",
			dispute: "all",
			paymentStatus: "pending",
			refundStatus: "all",
			search: "ORDER-1"
		},
		orders: [{ id: "order-1" }],
		pagination: { limit: 25, offset: 50, page: 3, pages: 4, total: 76 }
	};
	const component = section(async (endpoint, query) => {
		calls.push({ endpoint, query });
		return response;
	});
	component.isLoading = false;
	component.filters.paymentStatus = "pending";
	component.filters.search = "ORDER-1";

	await component.fetchOrders(3);

	assert.deepEqual(calls, [{
		endpoint: "/pages/stripe-checkout/sections/orders",
		query: { ...component.filters, page: 3 }
	}]);
	assert.deepEqual(component.orders, response.orders);
	assert.equal(component.isLoading, false);
	const query = new URL(window.location.href).searchParams;
	assert.equal(query.get("tab"), "orders");
	assert.equal(query.get("checkoutStatus"), null);
	assert.equal(query.get("paymentStatus"), "pending");
	assert.equal(query.get("search"), "ORDER-1");
	assert.equal(query.get("page"), "3");
});

test("Orders section restores shareable URL state and exposes a local failure state", async () => {
	location("https://example.test/panel/stripe-checkout?tab=orders&checkoutStatus=all&attention=failed_hooks&page=2");
	const component = section(async () => {
		throw new Error("Storage details must not be exposed");
	});
	component.filters = component.filtersFromLocation();

	assert.equal(component.filters.checkoutStatus, "all");
	assert.equal(component.filters.attention, "failed_hooks");
	assert.equal(component.pageFromLocation(), 2);

	await component.fetchOrders(2);

	assert.equal(component.error, "programmatordev.stripe-checkout.orders.index.error");
	assert.equal(component.isLoading, false);
});

test("Order table previews translate semantic statuses and preserve invalid timestamps", () => {
	const component = {
		$t: key => key,
		$panel: { translation: { code: "en" } }
	};
	const label = preview.methods.statusLabel.call(component, {
		group: "payment",
		status: "paid"
	});

	assert.equal(label, "programmatordev.stripe-checkout.orders.index.status.payment.paid");
	assert.equal(preview.methods.formatDateTime.call(component, "not-a-date"), "not-a-date");
});
