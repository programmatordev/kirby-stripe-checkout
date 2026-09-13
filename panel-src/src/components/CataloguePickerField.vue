<template>
	<k-field
		v-bind="$props"
		:input="false"
		class="k-stripe-checkout-catalogue-picker-field"
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

		<k-input-validator
			:required="required"
			:value="JSON.stringify(value ? [value] : [])"
		>
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
				:icon="disabled ? 'lock' : referenceIcon"
				@click="open"
			>
				{{ emptyText }}
			</k-empty>
		</k-input-validator>

		<k-box
			v-if="statusText"
			:theme="statusTheme"
			:text="statusText"
			class="k-stripe-checkout-catalogue-picker-field__status"
		/>
	</k-field>
</template>

<script>
export default {
	name: "CataloguePickerField",
	props: {
		translationPrefix: { type: String, required: true },
		queryParameter: { type: String, required: true },
		dialogComponent: { type: String, required: true },
		referenceIcon: { type: String, required: true },
		configurationErrorCode: String,
		catalogue: {
			type: Object,
			default: () => ({ status: "empty" })
		},
		catalogueReadable: { type: Boolean, default: true },
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
		type: { type: String, required: true },
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
			this.catalogueReadable &&
			(this.endpoint ?? this.endpoints.field)
		);

		return {
			localCatalogue: { ...this.catalogue },
			localSelected: this.selected ?? (hydrating ? null : this.fallback(this.value)),
			hydrating,
			hydrationRequestId: 0
		};
	},
	computed: {
		apiEndpoint() {
			return this.endpoint ?? this.endpoints.field;
		},
		emptyText() {
			if (this.sourceInactive) {
				return this.$t(`${this.translationPrefix}.emptyInactive`);
			}

			return this.$t(this.catalogueReadable === false
				? `${this.translationPrefix}.denied`
				: `${this.translationPrefix}.emptySelection`);
		},
		statusText() {
			if (this.sourceInactive) {
				return null;
			}

			if (this.localCatalogue.status === "stale") {
				return this.$t(`${this.translationPrefix}.stale`);
			}

			if (this.localCatalogue.status === "error") {
				return this.$t(
					this.configurationErrorCode && this.localCatalogue.error === this.configurationErrorCode
						? `${this.translationPrefix}.configurationInvalid`
						: `${this.translationPrefix}.error`
				);
			}

			if (this.localSelected?.unavailable) {
				return this.$t(`${this.translationPrefix}.selectedUnavailable`);
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
				// Server-selected props can arrive with value and bypass hydration.
				// Supersede any earlier read before accepting their current facts.
				this.hydrationRequestId++;
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
				icon: this.sourceInactive ? this.referenceIcon : "alert",
				info: value,
				text: this.$t(`${this.translationPrefix}.savedReference`),
				theme: this.sourceInactive ? undefined : "warning",
				unavailable: this.sourceInactive === false
			} : null;
		},
		async hydrate(value) {
			const requestId = ++this.hydrationRequestId;

			if (!value) {
				this.hydrating = false;
				this.localSelected = null;
				return;
			}

			if (this.catalogueReadable === false || !this.apiEndpoint) {
				this.hydrating = false;
				this.localSelected = this.selected?.id === value ? this.selected : this.fallback(value);
				return;
			}

			try {
				this.hydrating = true;
				// Read-only translated fields can still show permitted cached facts.
				const response = await this.$api.get(this.apiEndpoint, {
					[this.queryParameter]: value,
					view: "selected"
				});

				// Drawers can switch variants before an earlier cache read finishes.
				if (requestId !== this.hydrationRequestId) {
					return;
				}

				if (response?.catalogue) {
					this.localCatalogue = response.catalogue;
				}

				this.localSelected = response?.data?.find(item => item.id === value)
					?? this.fallback(value);
			} catch (error) {
				if (requestId === this.hydrationRequestId) {
					this.localSelected = this.fallback(value);
				}
			} finally {
				if (requestId === this.hydrationRequestId) {
					this.hydrating = false;
				}
			}
		},
		open() {
			if (this.disabled || !this.apiEndpoint) {
				return;
			}

			this.$panel.dialog.open({
				component: this.dialogComponent,
				props: {
					endpoint: this.apiEndpoint,
					value: this.value
				},
				on: {
					fetched: response => this.applyResponse(response),
					refreshed: response => this.handleRefresh(response),
					submit: async items => {
						const submitted = items[0] ?? null;
						let selected = null;

						if (submitted?.id) {
							try {
								// Native Models dialogs can return ID-only or previously
								// selected items. Hydrate current cache facts before display.
								const response = await this.$api.get(this.apiEndpoint, {
									[this.queryParameter]: submitted.id,
									view: "selected"
								});
								this.localCatalogue = response.catalogue ?? this.localCatalogue;
								selected = response.data?.find(item => item.id === submitted.id)
									?? this.fallback(submitted.id);
							} catch (error) {
								selected = this.fallback(submitted.id);
							}
						}

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
.k-stripe-checkout-catalogue-picker-field .k-collection {
	cursor: pointer;
}

.k-stripe-checkout-catalogue-picker-field__status {
	margin-top: var(--spacing-2);
}
</style>
