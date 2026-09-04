<template>
	<k-field
		v-bind="$props"
		:input="false"
		class="k-stripe-checkout-price-field"
	>
		<template v-if="disabled === false" #options>
			<k-button
				:responsive="true"
				:text="$t('select')"
				icon="checklist"
				size="xs"
				variant="filled"
				@click="open"
			/>
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
				v-else-if="hydrating"
				icon="loader"
			>
				{{ $t("loading") }}
			</k-empty>

			<k-empty
				v-else
				:icon="disabled ? 'lock' : 'money'"
				@click="open"
			>
				{{ emptyText }}
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
		sourceInactive: Boolean,
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
		const hydrating = Boolean(
			this.value &&
			!this.selected &&
			!this.sourceInactive &&
			!this.disabled &&
			(this.endpoint ?? this.endpoints.field)
		);

		return {
			localCatalogue: { ...this.catalogue },
			localSelected: this.selected ?? (hydrating ? null : this.fallback(this.value)),
			hydrating
		};
	},
	computed: {
		apiEndpoint() {
			return this.endpoint ?? this.endpoints.field;
		},
		emptyText() {
			if (this.sourceInactive) {
				return this.$t("programmatordev.stripe-checkout.prices.emptyInactive");
			}

			return this.$t(this.disabled
				? "programmatordev.stripe-checkout.prices.denied"
				: "programmatordev.stripe-checkout.prices.emptySelection");
		},
		statusText() {
			if (this.sourceInactive) {
				return null;
			}

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
			if (value) {
				this.localSelected = value;
				this.hydrating = false;
			}
		},
		value(value) {
			if (value !== this.localSelected?.id) {
				this.hydrate(value);
			}
		}
	},
	mounted() {
		if (this.hydrating) {
			this.hydrate(this.value);
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
				icon: this.sourceInactive ? "money" : "alert",
				info: value,
				text: this.$t("programmatordev.stripe-checkout.prices.savedReference"),
				theme: this.sourceInactive ? undefined : "warning",
				unavailable: this.sourceInactive === false
			} : null;
		},
		async hydrate(value) {
			if (!value) {
				this.hydrating = false;
				this.localSelected = null;
				return;
			}

			if (this.sourceInactive || this.disabled || !this.apiEndpoint) {
				this.hydrating = false;
				this.localSelected = this.fallback(value);
				return;
			}

			try {
				this.hydrating = true;
				const response = await this.$api.get(this.apiEndpoint, {
					price: value,
					view: "selected"
				});

				if (response?.catalogue) {
					this.localCatalogue = response.catalogue;
				}

				this.localSelected = response?.data?.find(item => item.id === value)
					?? this.fallback(value);
			} catch (error) {
				this.localSelected = this.fallback(value);
			} finally {
				this.hydrating = false;
			}
		},
		open() {
			if (this.disabled || !this.apiEndpoint) {
				return;
			}

			this.$panel.dialog.open({
				component: "k-stripe-checkout-price-dialog",
				props: {
					endpoint: this.apiEndpoint,
					value: this.value
				},
				on: {
					fetched: response => this.applyResponse(response),
					refreshed: response => this.handleRefresh(response),
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
		async handleRefresh(response) {
			if (response?.catalogue) {
				this.localCatalogue = response.catalogue;
			}

			// The refresh response is paginated and therefore cannot authoritatively
			// hydrate a saved selection that may be on another result page.
			await this.hydrate(this.value);
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
