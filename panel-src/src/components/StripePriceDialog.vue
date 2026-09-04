<template>
	<k-dialog
		v-bind="$attrs"
		:disabled="product !== null && selected === null"
		:size="size"
		:submit-button="product !== null"
		class="k-models-dialog k-stripe-checkout-price-dialog"
		@cancel="$emit('cancel')"
		@submit="submit"
	>
		<header class="k-pages-dialog-navbar">
			<k-button
				:disabled="product === null"
				:title="$t('back')"
				icon="angle-left"
				@click="showProducts"
			/>
			<k-headline>
				{{ product?.text ?? $t("programmatordev.stripe-checkout.prices.dialogTitle") }}
			</k-headline>
			<k-button
				:aria-label="$t('programmatordev.stripe-checkout.prices.refresh')"
				:disabled="refreshing || $panel.dialog.isLoading"
				:title="$t('programmatordev.stripe-checkout.prices.refresh')"
				:icon="refreshing ? 'loader' : 'refresh'"
				class="k-stripe-checkout-price-dialog__refresh"
				variant="filled"
				@click="refresh"
			/>
		</header>

		<k-dialog-search :value="query" @search="setQuery" />

		<k-collection
			:empty="{
				icon: product === null ? 'box' : 'money',
				text: emptyText
			}"
			:items="items"
			:link="false"
			:pagination="{
				align: 'center',
				details: true,
				dropdown: false,
				...pagination
			}"
			:sortable="false"
			layout="list"
			@item="choose"
			@paginate="paginate"
		>
			<template #options="{ item }">
				<k-button
					v-if="product === null"
					:title="$t('open')"
					class="k-pages-dialog-option"
					icon="angle-right"
					@click.stop="choose(item)"
				/>
				<k-choice-input
					v-else
					:checked="selected?.id === item.id"
					type="radio"
					:title="$t('select')"
					@click.stop="choose(item)"
				/>
			</template>
		</k-collection>
	</k-dialog>
</template>

<script>
export default {
	name: "StripePriceDialog",
	inheritAttrs: false,
	props: {
		endpoint: String,
		size: {
			type: String,
			default: "medium"
		},
		value: {
			type: String,
			default: ""
		}
	},
	emits: ["cancel", "fetched", "refreshed", "submit"],
	data() {
		return {
			items: [],
			pagination: {
				limit: 20,
				page: 1,
				total: 0
			},
			product: null,
			query: "",
			refreshing: false,
			searchTimeout: null,
			selected: null
		};
	},
	computed: {
		emptyText() {
			if (this.$panel.dialog.isLoading) {
				return this.$t("loading");
			}

			return this.$t(this.product === null
				? "programmatordev.stripe-checkout.prices.emptyProducts"
				: "programmatordev.stripe-checkout.prices.emptyProductPrices");
		}
	},
	mounted() {
		this.fetch();
	},
	beforeDestroy() {
		clearTimeout(this.searchTimeout);
	},
	methods: {
		async choose(item) {
			if (this.product === null) {
				const response = await this.request(item, "", 1);

				if (response === null) {
					return;
				}

				this.product = item;
				this.selected = null;
				this.query = "";
				this.applyResponse(response);
				return;
			}

			this.selected = item.selected ?? item;
		},
		async fetch() {
			const response = await this.request(
				this.product,
				this.query,
				this.pagination.page
			);

			if (response !== null) {
				this.applyResponse(response);
			}
		},
		applyResponse(response) {
			this.items = response.data ?? [];
			this.pagination = response.pagination ?? this.pagination;

			if (this.product !== null && this.selected === null) {
				const current = this.items.find(item => item.id === this.value);
				this.selected = current?.selected ?? null;
			}

			this.$emit("fetched", response);
		},
		async request(product, query, page) {
			try {
				this.$panel.dialog.isLoading = true;

				return await this.$api.get(this.endpoint, {
					page,
					product: product?.id,
					search: query,
					view: product === null ? "products" : "prices"
				});
			} catch (error) {
				this.$panel.error(error);

				return null;
			} finally {
				this.$panel.dialog.isLoading = false;
			}
		},
		paginate(pagination) {
			this.pagination.page = pagination.page;
			this.pagination.limit = pagination.limit;
			this.fetch();
		},
		async refresh() {
			if (this.refreshing || this.$panel.dialog.isLoading) {
				return;
			}

			try {
				this.refreshing = true;
				this.$panel.dialog.isLoading = true;
				const refreshed = await this.$api.post(this.endpoint);
				this.$emit("refreshed", refreshed);

				const response = await this.$api.get(this.endpoint, {
					page: this.pagination.page,
					product: this.product?.id,
					search: this.query,
					view: this.product === null ? "products" : "prices"
				});
				this.applyResponse(response);

				if (refreshed.catalogue?.status === "ready") {
					this.$panel.notification.success(
						this.$t("programmatordev.stripe-checkout.prices.refreshSuccess")
					);
				}
			} catch (error) {
				this.$panel.error(error);
			} finally {
				this.$panel.dialog.isLoading = false;
				this.refreshing = false;
			}
		},
		setQuery(value) {
			this.query = value ?? "";
			clearTimeout(this.searchTimeout);
			this.searchTimeout = setTimeout(() => {
				this.pagination.page = 1;
				this.fetch();
			}, 200);
		},
		async showProducts() {
			const response = await this.request(null, "", 1);

			if (response === null) {
				return;
			}

			this.product = null;
			this.query = "";
			this.applyResponse(response);
		},
		submit() {
			if (this.selected !== null) {
				this.$emit("submit", [this.selected]);
			}
		}
	}
};
</script>

<style>
.k-stripe-checkout-price-dialog :is(.k-item-title, .k-item-info) {
	min-width: 0;
	max-width: 100%;
}

.k-stripe-checkout-price-dialog .k-pages-dialog-navbar {
	padding-inline-end: 0;
}

.k-stripe-checkout-price-dialog .k-stripe-checkout-price-dialog__refresh[aria-disabled="true"] {
	opacity: 1;
}
</style>
