<template>
	<section class="k-stripe-checkout-orders-section">
		<header class="k-section-header">
			<k-headline>{{ $t("programmatordev.stripe-checkout.orders.title") }}</k-headline>
		</header>

		<form class="k-stripe-checkout-orders-section__filters" @submit.prevent="applyFilters">
			<k-field
				:help="$t('programmatordev.stripe-checkout.orders.index.search.help')"
				:label="$t('programmatordev.stripe-checkout.orders.index.search.label')"
			>
				<k-input
					:disabled="isLoading"
					:placeholder="$t('programmatordev.stripe-checkout.orders.index.search.placeholder')"
					:value="filters.search"
					icon="search"
					type="search"
					@input="filters.search = $event"
				/>
			</k-field>

			<k-field :label="$t('programmatordev.stripe-checkout.orders.fields.checkoutStatus')">
				<k-input
					:disabled="isLoading"
					:empty="false"
					:options="checkoutOptions"
					:value="filters.checkoutStatus"
					type="select"
					@input="filters.checkoutStatus = $event"
				/>
			</k-field>

			<k-field :label="$t('programmatordev.stripe-checkout.orders.fields.paymentStatus')">
				<k-input
					:disabled="isLoading"
					:empty="false"
					:options="paymentOptions"
					:value="filters.paymentStatus"
					type="select"
					@input="filters.paymentStatus = $event"
				/>
			</k-field>

			<k-field :label="$t('programmatordev.stripe-checkout.orders.fields.refundStatus')">
				<k-input
					:disabled="isLoading"
					:empty="false"
					:options="refundOptions"
					:value="filters.refundStatus"
					type="select"
					@input="filters.refundStatus = $event"
				/>
			</k-field>

			<k-field :label="$t('programmatordev.stripe-checkout.orders.index.dispute.label')">
				<k-input
					:disabled="isLoading"
					:empty="false"
					:options="disputeOptions"
					:value="filters.dispute"
					type="select"
					@input="filters.dispute = $event"
				/>
			</k-field>

			<k-field :label="$t('programmatordev.stripe-checkout.orders.index.attention.label')">
				<k-input
					:disabled="isLoading"
					:empty="false"
					:options="attentionOptions"
					:value="filters.attention"
					type="select"
					@input="filters.attention = $event"
				/>
			</k-field>

			<div class="k-stripe-checkout-orders-section__filter-actions">
				<k-button
					:disabled="isLoading"
					:icon="isLoading ? 'loader' : 'filter'"
					type="submit"
					variant="filled"
				>
					{{ $t("programmatordev.stripe-checkout.orders.index.apply") }}
				</k-button>
				<k-button
					v-if="hasNonDefaultFilters"
					:disabled="isLoading"
					icon="cancel-small"
					variant="dimmed"
					@click="resetFilters"
				>
					{{ $t("programmatordev.stripe-checkout.orders.index.reset") }}
				</k-button>
			</div>
		</form>

		<k-box
			v-if="isLoading"
			:aria-label="$t('programmatordev.stripe-checkout.orders.index.loading')"
			:text="$t('programmatordev.stripe-checkout.orders.index.loading')"
			aria-live="polite"
			icon="loader"
			role="status"
			theme="info"
		/>
		<k-box
			v-else-if="error"
			:text="error"
			icon="alert"
			role="alert"
			theme="negative"
		/>
		<k-collection
			v-else
			:columns="columns"
			:empty="{ icon: 'cart', text: emptyText }"
			:items="orders"
			:pagination="collectionPagination"
			layout="table"
			@paginate="fetchOrders"
		/>
	</section>
</template>

<script>
const defaultFilters = () => ({
	attention: "all",
	checkoutStatus: "complete",
	dispute: "all",
	paymentStatus: "all",
	refundStatus: "all",
	search: ""
});

const queryKeys = [
	"attention",
	"checkoutStatus",
	"dispute",
	"page",
	"paymentStatus",
	"refundStatus",
	"search"
];

export default {
	name: "OrdersSection",
	data() {
		return {
			error: null,
			filters: defaultFilters(),
			isLoading: true,
			orders: [],
			pagination: { limit: 25, offset: 0, page: 0, pages: 0, total: 0 }
		};
	},
	computed: {
		attentionOptions() {
			return this.options("attention", ["all", "failed_events", "failed_hooks"]);
		},
		checkoutOptions() {
			return this.options("checkout", [
				"all",
				"complete",
				"open",
				"creating",
				"creation_uncertain",
				"creation_failed",
				"expired"
			]);
		},
		collectionPagination() {
			return { ...this.pagination, details: true, keys: true };
		},
		columns() {
			const type = "stripe-checkout-order-table";

			return {
				order: {
					label: this.$t("programmatordev.stripe-checkout.orders.fields.orderNumber"),
					mobile: true,
					type,
					width: "1/6"
				},
				createdAt: {
					label: this.$t("programmatordev.stripe-checkout.orders.fields.createdAt"),
					type,
					width: "1/6"
				},
				customer: {
					label: this.$t("programmatordev.stripe-checkout.orders.fields.customer"),
					type,
					width: "1/4"
				},
				paymentStatus: {
					label: this.$t("programmatordev.stripe-checkout.orders.fields.paymentStatus"),
					type,
					width: "1/8"
				},
				total: {
					align: "end",
					label: this.$t("programmatordev.stripe-checkout.orders.fields.total"),
					width: "1/8"
				},
				attention: {
					label: this.$t("programmatordev.stripe-checkout.orders.index.attention.label"),
					type,
					width: "1/4"
				}
			};
		},
		disputeOptions() {
			return this.options("disputeFilter", ["all", "any", "requires_response"]);
		},
		emptyText() {
			return this.hasNonDefaultFilters
				? this.$t("programmatordev.stripe-checkout.orders.index.emptyFiltered")
				: this.$t("programmatordev.stripe-checkout.orders.index.empty");
		},
		hasNonDefaultFilters() {
			return JSON.stringify(this.filters) !== JSON.stringify(defaultFilters());
		},
		paymentOptions() {
			return this.options("payment", ["all", "pending", "paid", "failed", "no_payment_required"]);
		},
		refundOptions() {
			return this.options("refundFilter", ["all", "partial", "full"]);
		}
	},
	mounted() {
		this.filters = this.filtersFromLocation();
		this.fetchOrders(this.pageFromLocation());
	},
	methods: {
		applyFilters() {
			this.fetchOrders(1);
		},
		async fetchOrders(page = 1) {
			// The initial request starts in the loading state; later requests remain single-flight.
			if (this.isLoading && this.orders.length > 0) {
				return;
			}

			this.error = null;
			this.isLoading = true;

			try {
				const response = await this.$api.get(
					`${this.parent}/sections/${this.name}`,
					this.requestQuery(page)
				);
				this.error = response.error;
				this.filters = response.filters;
				this.orders = response.orders;
				this.pagination = response.pagination;
				this.updateLocation(response.pagination.page || page);
			} catch (error) {
				this.error = this.$t("programmatordev.stripe-checkout.orders.index.error");
			} finally {
				this.isLoading = false;
			}
		},
		filtersFromLocation() {
			const query = new URL(window.location.href).searchParams;
			const filters = defaultFilters();

			for (const key of Object.keys(filters)) {
				filters[key] = query.get(key) ?? filters[key];
			}

			return filters;
		},
		options(group, values) {
			return values.map(value => ({
				text: this.$t(`programmatordev.stripe-checkout.orders.index.option.${group}.${value}`),
				value
			}));
		},
		pageFromLocation() {
			const page = Number.parseInt(new URL(window.location.href).searchParams.get("page"), 10);

			return Number.isInteger(page) && page > 0 ? page : 1;
		},
		requestQuery(page) {
			return { ...this.filters, page };
		},
		resetFilters() {
			this.filters = defaultFilters();
			this.fetchOrders(1);
		},
		updateLocation(page) {
			const url = new URL(window.location.href);
			const defaults = defaultFilters();

			for (const key of queryKeys) {
				url.searchParams.delete(key);
			}

			url.searchParams.set("tab", "orders");

			for (const [key, value] of Object.entries(this.filters)) {
				if (value !== defaults[key]) {
					url.searchParams.set(key, value);
				}
			}

			if (page > 1) {
				url.searchParams.set("page", page);
			}

			// Keep reloads and browser history aligned with the asynchronously loaded section state.
			window.history.replaceState(window.history.state, "", url);
		}
	}
};
</script>

<style>
.k-stripe-checkout-orders-section {
	display: grid;
	gap: var(--spacing-6);
}

.k-stripe-checkout-orders-section__filters {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
	gap: var(--spacing-4);
	align-items: start;
}

.k-stripe-checkout-orders-section__filters > :first-child {
	grid-column: span 2;
}

.k-stripe-checkout-orders-section__filter-actions {
	display: flex;
	gap: var(--spacing-2);
	align-items: center;
	align-self: end;
	min-height: var(--input-height);
}

@media (max-width: 40rem) {
	.k-stripe-checkout-orders-section__filters > :first-child {
		grid-column: auto;
	}
}
</style>
