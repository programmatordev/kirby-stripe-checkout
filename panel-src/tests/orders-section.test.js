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

	for (const [name, computed] of Object.entries(options.computed)) {
		Object.defineProperty(section, name, {
			get: () => typeof computed === "function"
				? computed.call(section)
				: computed.get.call(section)
		});
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
			attention: [],
			checkoutStatus: ["complete"],
			dispute: [],
			payment: ["pending"],
			refundStatus: []
		},
		options: { layout: "table", search: true },
		pagination: { limit: 25, offset: 50, page: 3, pages: 4, total: 76 }
	};
	const component = section(async (endpoint, query) => {
		calls.push({ endpoint, query });
		return response;
	});
	component.filters.payment = ["pending"];
	component.pagination.page = 3;
	component.searchterm = "ORDER-1";

	await component.load();

	assert.equal(options.extends, "k-pages-section");
	assert.deepEqual(calls, [{
		endpoint: "/pages/stripe-checkout/sections/orders",
		query: {
			attention: "",
			checkoutStatus: "complete",
			dispute: "",
			payment: "pending",
			refundStatus: "",
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
	assert.equal(query.get("payment"), "pending");
	assert.equal(query.get("search"), "ORDER-1");
	assert.equal(query.get("page"), "3");
});

test("Orders section restores shareable state and keeps request failures generic", async () => {
	location("https://example.test/panel/stripe-checkout?tab=orders&checkoutStatus=&payment=unpaid,failed&attention=failed_hooks&search=ORDER-2&page=2");
	const component = section(async () => {
		throw new Error("Storage details must not be exposed");
	});

	assert.deepEqual(component.filters.checkoutStatus, []);
	assert.deepEqual(component.filters.payment, ["unpaid", "failed"]);
	assert.deepEqual(component.filters.attention, ["failed_hooks"]);
	assert.equal(component.searching, true);
	assert.equal(component.searchterm, "ORDER-2");
	assert.equal(component.pagination.page, 2);

	await component.load();

	assert.equal(component.error, "programmatordev.stripe-checkout.orders.index.error");
	assert.equal(component.isLoading, false);
});

test("Orders section resets changed filters to its completed successful default", () => {
	location("https://example.test/panel/stripe-checkout?tab=orders");
	const component = section(async () => ({}));
	let reloads = 0;
	component.reload = () => reloads++;
	component.filters.attention = ["failed_hooks"];
	component.filters.checkoutStatus = [];
	component.filters.payment = ["pending"];
	component.pagination.page = 3;

	component.resetFilters();

	assert.deepEqual(component.filters, {
		attention: [],
		checkoutStatus: ["complete"],
		dispute: [],
		payment: ["paid", "no_payment_required"],
		refundStatus: []
	});
	assert.equal(component.pagination.page, 1);
	assert.equal(reloads, 1);
});

test("Orders section exposes complete multiselect filters on its native controls", () => {
	location("https://example.test/panel/stripe-checkout?tab=orders");
	const component = section(async () => ({}));

	assert.equal(component.activeFilterCount, 2);
	assert.deepEqual(component.buttons.map(button => button.text), [
		"programmatordev.stripe-checkout.orders.index.search.label",
		"programmatordev.stripe-checkout.orders.index.filters"
	]);
	assert.deepEqual(component.buttons[1].badge, { text: 2, theme: "notice" });
	assert.equal(component.buttons.length, 2);
	assert.equal(component.filterFields.checkoutStatus.type, "multiselect");
	assert.equal(component.filterFields.payment.type, "multiselect");
	assert.equal(component.filterFields.refundStatus.type, "multiselect");
	assert.equal(component.filterFields.dispute.type, "multiselect");
	assert.equal(component.filterFields.attention.type, "multiselect");
	assert.equal(
		component.filterFields.payment.options.some(option => option.value === "unpaid"),
		true
	);
	assert.equal(
		component.filterFields.payment.options.some(option => option.value === "successful"),
		false
	);
	assert.equal(component.filterFields.refundStatus.options.length, 5);
	assert.equal(component.filterFields.dispute.options.length, 6);

	component.filters.payment = [];
	component.filters.refundStatus = ["partial", "full"];

	assert.equal(component.activeFilterCount, 2);
	assert.deepEqual(component.buttons[1].badge, { text: 2, theme: "notice" });
	assert.equal(component.buttons[2].icon, "cancel-small");
	assert.equal(
		component.buttons[2].title,
		"programmatordev.stripe-checkout.orders.index.reset"
	);
	assert.equal(component.buttons[2].text, undefined);
});
