<script>
const defaultFilters = () => ({
	attention: "all",
	checkoutStatus: "complete",
	dispute: "all",
	paymentStatus: "all",
	refundStatus: "all"
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

const locationState = () => {
	const query = new URL(window.location.href).searchParams;
	const filters = defaultFilters();

	for (const key of Object.keys(filters)) {
		filters[key] = query.get(key) ?? filters[key];
	}

	const page = Number.parseInt(query.get("page"), 10);

	return {
		filters,
		page: Number.isInteger(page) && page > 0 ? page : 1,
		searchterm: query.get("search")
	};
};

export default {
	extends: "k-pages-section",
	data() {
		const state = locationState();

		return {
			filters: state.filters,
			pagination: { page: state.page },
			searchterm: state.searchterm
		};
	},
	computed: {
		buttons() {
			const buttons = [{
				click: this.onSearchToggle,
				icon: "search",
				responsive: true,
				text: this.$t("programmatordev.stripe-checkout.orders.index.search.label")
			}, {
				click: this.openFilters,
				icon: "filter",
				responsive: true,
				text: this.$t("programmatordev.stripe-checkout.orders.index.filters"),
				theme: this.hasNonDefaultFilters ? "notice" : undefined
			}];

			if (this.hasNonDefaultFilters) {
				buttons.push({
					click: this.resetFilters,
					icon: "cancel-small",
					responsive: true,
					text: this.$t("programmatordev.stripe-checkout.orders.index.reset")
				});
			}

			return buttons;
		},
		filterFields() {
			return {
				checkoutStatus: {
					empty: false,
					label: this.$t("programmatordev.stripe-checkout.orders.fields.checkoutStatus"),
					options: this.filterOptions("checkout", [
						"all",
						"complete",
						"open",
						"creating",
						"creation_uncertain",
						"creation_failed",
						"expired"
					]),
					type: "select"
				},
				paymentStatus: {
					empty: false,
					label: this.$t("programmatordev.stripe-checkout.orders.fields.paymentStatus"),
					options: this.filterOptions("payment", [
						"all",
						"pending",
						"paid",
						"failed",
						"no_payment_required"
					]),
					type: "select"
				},
				refundStatus: {
					empty: false,
					label: this.$t("programmatordev.stripe-checkout.orders.fields.refundStatus"),
					options: this.filterOptions("refundFilter", ["all", "partial", "full"]),
					type: "select"
				},
				dispute: {
					empty: false,
					label: this.$t("programmatordev.stripe-checkout.orders.index.dispute.label"),
					options: this.filterOptions("disputeFilter", [
						"all",
						"any",
						"requires_response"
					]),
					type: "select"
				},
				attention: {
					empty: false,
					label: this.$t("programmatordev.stripe-checkout.orders.index.attention.label"),
					options: this.filterOptions("attention", [
						"all",
						"failed_events",
						"failed_hooks"
					]),
					type: "select"
				}
			};
		},
		hasNonDefaultFilters() {
			return JSON.stringify(this.filters) !== JSON.stringify(defaultFilters());
		}
	},
	methods: {
		applyFilters(filters) {
			this.filters = { ...defaultFilters(), ...filters };
			this.pagination.page = 1;
			this.$panel.drawer.close();
			this.reload();
		},
		filterOptions(group, values) {
			return values.map(value => ({
				text: this.$t(`programmatordev.stripe-checkout.orders.index.option.${group}.${value}`),
				value
			}));
		},
		async load(silent = false) {
			// Extend the native Pages request with the commerce filters it does not know about.
			this.isProcessing = true;
			this.error = null;

			if (silent === false) {
				this.isLoading = true;
			}

			try {
				const response = await this.$api.get(`${this.parent}/sections/${this.name}`, {
					...this.filters,
					page: this.pagination.page,
					searchterm: this.searchterm
				});
				this.options = response.options;
				this.pagination = response.pagination;
				this.filters = response.filters;

				if (response.error) {
					this.data = [];
					this.error = response.error;
					return;
				}

				this.data = response.data;
				this.updateLocation(response.pagination.page);
			} catch (error) {
				this.error = this.$t("programmatordev.stripe-checkout.orders.index.error");
			} finally {
				this.isProcessing = false;
				this.isLoading = false;
			}
		},
		openFilters() {
			this.$panel.drawer.open({
				component: "k-form-drawer",
				on: {
					input: filters => {
						this.$panel.drawer.props.value = filters;
					},
					submit: this.applyFilters
				},
				props: {
					fields: this.filterFields,
					icon: "filter",
					title: this.$t("programmatordev.stripe-checkout.orders.index.filters"),
					value: { ...this.filters }
				}
			});
		},
		resetFilters() {
			this.filters = defaultFilters();
			this.pagination.page = 1;
			this.reload();
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

			if (this.searchterm) {
				url.searchParams.set("search", this.searchterm);
			}

			if (page > 1) {
				url.searchParams.set("page", page);
			}

			// Keep shared URLs aligned with the native section's asynchronous state.
			window.history.replaceState(window.history.state, "", url);
		}
	}
};
</script>
