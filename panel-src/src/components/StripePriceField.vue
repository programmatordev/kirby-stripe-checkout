<template>
	<k-field
		v-bind="$props"
		:input="false"
		class="k-stripe-checkout-price-field"
	>
		<template v-if="disabled === false" #options>
			<k-button-group layout="collapsed">
				<k-button
					:responsive="true"
					:text="$t('select')"
					icon="checklist"
					size="xs"
					variant="filled"
					@click="open"
				/>
				<k-button
					:aria-label="$t('programmatordev.stripe-checkout.prices.refresh')"
					:disabled="refreshing"
					:title="$t('programmatordev.stripe-checkout.prices.refresh')"
					icon="refresh"
					size="xs"
					variant="filled"
					@click="refresh"
				/>
			</k-button-group>
		</template>

		<k-input-validator :required="required" :value="value">
			<k-collection
				v-if="localSelected"
				:items="[localSelected]"
				:link="false"
				:sortable="false"
				layout="list"
				@click.native="open"
			>
				<template v-if="disabled === false" #options>
					<k-button
						:aria-label="$t('remove')"
						:title="$t('remove')"
						icon="remove"
						@click.stop="clear"
					/>
				</template>
			</k-collection>

			<k-empty
				v-else
				:icon="disabled ? 'lock' : 'money'"
				@click="open"
			>
				{{ $t(disabled
					? "programmatordev.stripe-checkout.prices.denied"
					: "programmatordev.stripe-checkout.prices.emptySelection") }}
			</k-empty>
		</k-input-validator>

		<k-box
			v-if="statusText"
			:theme="statusTheme"
			:text="statusText"
			class="k-stripe-checkout-price-field__status"
		/>
	</k-field>
</template>

<script>
export default {
	name: "StripePriceField",
	props: {
		catalogue: {
			type: Object,
			default: () => ({ status: "empty" })
		},
		currency: String,
		disabled: Boolean,
		endpoint: String,
		endpoints: {
			type: Object,
			default: () => ({})
		},
		help: String,
		icon: String,
		label: String,
		name: String,
		required: Boolean,
		selected: Object,
		type: {
			type: String,
			default: "stripe-checkout-price"
		},
		value: {
			type: String,
			default: ""
		},
		when: String
	},
	emits: ["input"],
	data() {
		return {
			localCatalogue: { ...this.catalogue },
			localSelected: this.selected ?? this.fallback(this.value),
			refreshing: false
		};
	},
	computed: {
		apiEndpoint() {
			return this.endpoint ?? this.endpoints.field;
		},
		statusText() {
			if (this.localCatalogue.status === "stale") {
				return this.$t("programmatordev.stripe-checkout.prices.stale");
			}

			if (this.localCatalogue.status === "error") {
				return this.$t(
					this.localCatalogue.error === "prices.configuration_invalid"
						? "programmatordev.stripe-checkout.prices.configurationInvalid"
						: "programmatordev.stripe-checkout.prices.error"
				);
			}

			if (this.localSelected?.unavailable) {
				return this.$t("programmatordev.stripe-checkout.prices.selectedUnavailable");
			}

			if (this.localCatalogue.refreshedAt) {
				return this.$t("programmatordev.stripe-checkout.prices.refreshed", {
					date: new Date(this.localCatalogue.refreshedAt * 1000).toLocaleString()
				});
			}

			return null;
		},
		statusTheme() {
			return ["error", "stale"].includes(this.localCatalogue.status) || this.localSelected?.unavailable
				? "warning"
				: "info";
		}
	},
	watch: {
		catalogue(value) {
			this.localCatalogue = { ...value };
		},
		selected(value) {
			this.localSelected = value ?? this.fallback(this.value);
		},
		value(value) {
			if (value !== this.localSelected?.id) {
				this.localSelected = this.fallback(value);
			}
		}
	},
	methods: {
		applyResponse(response) {
			if (response?.catalogue) {
				this.localCatalogue = response.catalogue;
			}

			const current = response?.data?.find(item => item.id === this.value);

			if (current) {
				this.localSelected = current;
			}
		},
		clear() {
			if (this.disabled) {
				return;
			}

			this.localSelected = null;
			this.$emit("input", "");
		},
		fallback(value) {
			return value ? {
				id: value,
				icon: "alert",
				info: value,
				text: this.$t("programmatordev.stripe-checkout.prices.savedReference"),
				theme: "warning",
				unavailable: true
			} : null;
		},
		open() {
			if (this.disabled || !this.apiEndpoint) {
				return;
			}

			this.$panel.dialog.open({
				component: "k-models-dialog",
				props: {
					empty: {
						icon: "money",
						text: this.$t("programmatordev.stripe-checkout.prices.emptyCatalogue")
					},
					endpoint: this.apiEndpoint,
					hasSearch: true,
					max: 1,
					multiple: false,
					value: this.value ? [this.value] : []
				},
				on: {
					fetched: response => this.applyResponse(response),
					submit: items => {
						const submitted = items[0] ?? null;
						const selected = submitted?.text
							? submitted
							: submitted?.id === this.localSelected?.id
								? this.localSelected
								: submitted;
						this.localSelected = selected;
						this.$emit("input", selected?.id ?? "");
						this.$panel.dialog.close();
					}
				}
			});
		},
		async refresh() {
			if (this.disabled || !this.apiEndpoint || this.refreshing) {
				return;
			}

			try {
				this.refreshing = true;
				const response = await this.$api.post(this.apiEndpoint);
				this.applyResponse(response);

				if (response.catalogue?.status === "ready") {
					this.$panel.notification.success(
						this.$t("programmatordev.stripe-checkout.prices.refreshSuccess")
					);
				}
			} catch (error) {
				this.$panel.error(error);
			} finally {
				this.refreshing = false;
			}
		}
	}
};
</script>

<style>
.k-stripe-checkout-price-field .k-collection {
	cursor: pointer;
}

.k-stripe-checkout-price-field__status {
	margin-top: var(--spacing-2);
}
</style>
