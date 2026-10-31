import assert from "node:assert/strict";
import test from "node:test";
import { componentOptions } from "./support/component-options.js";

const options = await componentOptions("OrdersSection");

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
	const drawer = {
		close() {},
		open(config) {
			this.config = config;
		},
		props: {}
	};
	const section = {
		...options.data(),
		$api: { get },
		$panel: { drawer },
		$t: key => key,
		data: [],
		error: null,
		isLoading: false,
		isProcessing: false,
		name: "orders",
		options: {},
		parent: "/pages/stripe-checkout",
		reload() {}
	};

	for (const [name, method] of Object.entries(options.methods)) {
		section[name] = (...args) => method.call(section, ...args);
	}

	return section;
}

test("Orders section extends Kirby's native Pages table and sends its additional filters", async () => {
	location("https://example.test/panel/stripe-checkout?tab=orders");
	const calls = [];
	const response = {
		data: [{ id: "order-1" }],
		error: null,
		filters: {
			attention: "all",
			checkoutStatus: "complete",
			dispute: "all",
			paymentStatus: "pending",
			refundStatus: "all"
		},
		options: { layout: "table", search: true },
		pagination: { limit: 25, offset: 50, page: 3, pages: 4, total: 76 }
	};
	const component = section(async (endpoint, query) => {
		calls.push({ endpoint, query });
		return response;
	});
	component.filters.paymentStatus = "pending";
	component.pagination.page = 3;
	component.searchterm = "ORDER-1";

	await component.load();

	assert.equal(options.extends, "k-pages-section");
	assert.deepEqual(calls, [{
		endpoint: "/pages/stripe-checkout/sections/orders",
		query: {
			...component.filters,
			page: 3,
			searchterm: "ORDER-1"
		}
	}]);
	assert.deepEqual(component.data, response.data);
	assert.deepEqual(component.options, response.options);
	assert.equal(component.isLoading, false);
	const query = new URL(window.location.href).searchParams;
	assert.equal(query.get("tab"), "orders");
	assert.equal(query.get("checkoutStatus"), null);
	assert.equal(query.get("paymentStatus"), "pending");
	assert.equal(query.get("search"), "ORDER-1");
	assert.equal(query.get("page"), "3");
});

test("Orders section restores shareable state and keeps request failures generic", async () => {
	location("https://example.test/panel/stripe-checkout?tab=orders&checkoutStatus=all&attention=failed_hooks&search=ORDER-2&page=2");
	const component = section(async () => {
		throw new Error("Storage details must not be exposed");
	});

	assert.equal(component.filters.checkoutStatus, "all");
	assert.equal(component.filters.attention, "failed_hooks");
	assert.equal(component.searchterm, "ORDER-2");
	assert.equal(component.pagination.page, 2);

	await component.load();

	assert.equal(component.error, "programmatordev.stripe-checkout.orders.index.error");
	assert.equal(component.isLoading, false);
});
