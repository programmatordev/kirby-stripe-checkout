<template>
	<k-field v-bind="$props" class="k-stripe-checkout-shipping-zones-field">
		<template
			v-if="languageTransitioning === false && technicalLocked === false && disabled === false"
			#options
		>
			<k-button icon="add" size="xs" variant="filled" @click="add">
				{{ $t("programmatordev.stripe-checkout.settings.shippingZones.add") }}
			</k-button>
		</template>

		<div
			class="k-stripe-checkout-shipping-zones-field__content"
			:aria-busy="languageTransitioning"
			:data-language-transitioning="languageTransitioning"
		>
			<k-box
				v-if="technicalLocked"
				theme="info"
				:text="$t('programmatordev.stripe-checkout.settings.shippingZones.translationHelp')"
			/>

			<k-box
				v-if="localValue.length === 0"
				theme="empty"
				:text="$t('programmatordev.stripe-checkout.settings.shippingZones.empty')"
			/>

			<k-table
				v-if="localValue.length"
				:columns="columns"
				:disabled="disabled"
				:index="1"
				:options="actions"
				:rows="rows"
				:sortable="technicalLocked === false && disabled === false && localValue.length > 1"
				@cell="openByRow($event.row)"
				@input="sort"
				@option="handleAction"
			/>
		</div>
	</k-field>
</template>

<script>
import { resolveStableId, stableId } from "../synchronized-structure.js";

const emptyValue = () => [];

export default {
	props: {
		countryOptions: {
			type: Array,
			default: () => []
		},
		currency: {
			type: String,
			default: ""
		},
		disabled: Boolean,
		help: String,
		icon: String,
		label: String,
		name: String,
		required: Boolean,
		serverTechnicalLocked: {
			type: Boolean,
			default: false
		},
		type: {
			type: String,
			default: "stripe-checkout-shipping-zones"
		},
		value: {
			type: Array,
			default: emptyValue
		},
		when: [Object, Array]
	},
	data() {
		return {
			localValue: this.clone(this.value)
		};
	},
	computed: {
		actions() {
			const actions = [{
				click: "edit",
				icon: "edit",
				text: this.$t("edit")
			}];

			if (this.technicalLocked === false && this.disabled === false) {
				actions.push("-", {
					click: "remove",
					icon: "trash",
					text: this.$t("delete")
				});
			}

			return actions;
		},
		columns() {
			return {
				name: {
					label: this.$t("programmatordev.stripe-checkout.settings.shippingZones.name.label"),
					mobile: true,
					type: "text"
				},
				destinations: {
					label: this.$t("programmatordev.stripe-checkout.settings.shippingZones.scope.label"),
					type: "text"
				},
				shippingOptions: {
					label: this.$t("programmatordev.stripe-checkout.settings.shippingZones.options.label"),
					type: "structure"
				}
			};
		},
		drawerId() {
			return `${this.name ?? "stripe-checkout-shipping-zones"}-row`;
		},
		languageTransitioning() {
			return this.$panel.languages.length > 1 &&
				this.serverTechnicalLocked !== this.panelTechnicalLocked;
		},
		panelTechnicalLocked() {
			return this.$panel.languages.length > 1 &&
				this.$panel.language.isDefault === false;
		},
		rows() {
			return this.localValue.map(row => ({
				_id: row.id,
				destinations: row.scope === "fallback"
					? this.$t("programmatordev.stripe-checkout.settings.shippingZones.scope.fallback")
					: row.countries.join(", "),
				name: row.name,
				shippingOptions: row.options
			}));
		},
		technicalLocked() {
			return this.serverTechnicalLocked || this.panelTechnicalLocked;
		}
	},
	watch: {
		value: {
			deep: true,
			handler(value) {
				this.localValue = this.clone(value);
			}
		}
	},
	methods: {
		add() {
			if (this.technicalLocked || this.disabled) {
				return;
			}

			this.open({
				countries: [],
				id: stableId(),
				name: "",
				options: [],
				scope: "selected_countries"
			});
		},
		clone(value) {
			return JSON.parse(JSON.stringify(Array.isArray(value) ? value : []));
		},
		emit() {
			this.$emit("input", this.clone(this.localValue));
		},
		fields(row) {
			const technicalDisabled = this.technicalLocked || this.disabled;
			const optionFields = {
				label: {
					label: this.$t("programmatordev.stripe-checkout.settings.shippingZones.option.label.label"),
					maxlength: 100,
					name: "label",
					required: this.technicalLocked === false,
					type: "text"
				},
				amount: {
					after: this.currency || null,
					disabled: technicalDisabled,
					help: this.$t("programmatordev.stripe-checkout.settings.shippingZones.option.amount.help"),
					label: this.$t("programmatordev.stripe-checkout.settings.shippingZones.option.amount.label"),
					name: "amount",
					pattern: "[0-9]+(?:\\.[0-9]+)?",
					required: true,
					type: "text"
				},
				deliveryEstimateMinimum: {
					disabled: technicalDisabled,
					label: this.$t("programmatordev.stripe-checkout.settings.shippingZones.option.deliveryEstimate.minimum"),
					min: 1,
					name: "deliveryEstimateMinimum",
					step: 1,
					type: "number",
					width: "1/3"
				},
				deliveryEstimateMaximum: {
					disabled: technicalDisabled,
					label: this.$t("programmatordev.stripe-checkout.settings.shippingZones.option.deliveryEstimate.maximum"),
					min: 1,
					name: "deliveryEstimateMaximum",
					step: 1,
					type: "number",
					width: "1/3"
				},
				deliveryEstimateUnit: {
					default: "business_day",
					disabled: technicalDisabled,
					label: this.$t("programmatordev.stripe-checkout.settings.shippingZones.option.deliveryEstimate.unit"),
					name: "deliveryEstimateUnit",
					options: ["business_day", "day", "hour", "week", "month"].map(unit => ({
						text: this.$t(`programmatordev.stripe-checkout.settings.shippingZones.option.deliveryEstimate.${unit}`),
						value: unit
					})),
					type: "select",
					width: "1/3"
				}
			};
			const fields = {
				name: {
					disabled: technicalDisabled,
					label: this.$t("programmatordev.stripe-checkout.settings.shippingZones.name.label"),
					maxlength: 100,
					name: "name",
					required: true,
					type: "text"
				},
				scope: {
					disabled: technicalDisabled,
					label: this.$t("programmatordev.stripe-checkout.settings.shippingZones.scope.label"),
					name: "scope",
					options: this.availableScopes(row).map(scope => ({
						text: this.$t(`programmatordev.stripe-checkout.settings.shippingZones.scope.${scope}`),
						value: scope
					})),
					required: true,
					type: "select"
				},
				countries: {
					disabled: technicalDisabled,
					label: this.$t("programmatordev.stripe-checkout.settings.shippingZones.countries.label"),
					name: "countries",
					options: this.availableCountryOptions(row),
					required: true,
					type: "multiselect",
					when: {
						scope: "selected_countries"
					}
				},
				options: {
					columns: {
						label: {
							label: this.$t("programmatordev.stripe-checkout.settings.shippingZones.option.label.label"),
							mobile: true,
							type: "text"
						},
						amount: {
							after: this.currency || null,
							label: this.$t("programmatordev.stripe-checkout.settings.shippingZones.option.amount.label"),
							type: "stripe-checkout-money"
						}
					},
					empty: this.$t("programmatordev.stripe-checkout.settings.shippingZones.options.empty"),
					fields: optionFields,
					label: this.$t("programmatordev.stripe-checkout.settings.shippingZones.options.label"),
					max: 5,
					min: this.technicalLocked ? row.options.length : 1,
					name: "options",
					required: true,
					sortable: this.technicalLocked === false,
					type: this.technicalLocked
						? "stripe-checkout-synchronized-structure-rows"
						: "structure"
				}
			};

			return this.$helper.field.subfields(this, fields);
		},
		availableCountryOptions(row) {
			const assigned = new Set();

			for (const zone of this.localValue) {
				if (zone.id === row.id) {
					continue;
				}

				for (const country of zone.countries) {
					assigned.add(country);
				}
			}

			return this.countryOptions.filter(option => assigned.has(option.value) === false);
		},
		availableScopes(row) {
			const fallbackExists = this.localValue.some(zone =>
				zone.id !== row.id && zone.scope === "fallback"
			);

			return fallbackExists && row.scope !== "fallback"
				? ["selected_countries"]
				: ["selected_countries", "fallback"];
		},
		handleAction(action, row) {
			if (action === "edit") {
				this.openByRow(row);
			} else if (action === "remove") {
				this.requestRemoval(row._id);
			}
		},
		isComplete(row) {
			if (this.technicalLocked) {
				return true;
			}

			return typeof row.name === "string" && row.name.trim() !== "" &&
				["selected_countries", "fallback"].includes(row.scope) &&
				(row.scope !== "selected_countries" || row.countries.length > 0) &&
				row.options.length > 0 && row.options.length <= 5 &&
				row.options.every(option =>
					/^[a-z0-9_-]{1,64}$/.test(option.key) &&
					typeof option.label === "string" && option.label.trim() !== "" &&
					option.amount !== null && option.amount !== ""
				);
		},
		open(row, replace = false) {
			if (row === null) {
				return;
			}

			const index = this.localValue.findIndex(item => item.id === row.id);
			const previous = index > 0 ? this.localValue[index - 1] : null;
			const next = index >= 0 ? this.localValue[index + 1] ?? null : null;
			const optionIds = new Map(row.options.map(option => [option.id, option.id]));

			this.$panel.drawer.open({
				component: "k-stripe-checkout-synchronized-structure-drawer",
				id: this.drawerId,
				on: {
					input: value => this.updateFromForm(row, this.rowFromForm(row, value, optionIds)),
					next: () => this.open(next, true),
					prev: () => this.open(previous, true),
					remove: () => {
						if (index >= 0) {
							this.requestRemoval(row.id);
						}
					}
				},
				props: {
					disabled: this.disabled,
					icon: "globe",
					next: next !== null,
					prev: previous !== null,
					removable: index >= 0 && this.technicalLocked === false && this.disabled === false,
					tabs: {
						content: {
							fields: this.fields(row)
						}
					},
					title: row.name || this.$t("programmatordev.stripe-checkout.settings.shippingZones.add"),
					value: this.toFormValue(row)
				},
				replace
			});
		},
		openByRow(tableRow) {
			const row = this.localValue.find(item => item.id === tableRow._id);

			if (row) {
				this.open(row);
			}
		},
		requestRemoval(id) {
			if (this.technicalLocked || this.disabled) {
				return;
			}

			this.$panel.dialog.open({
				component: "k-remove-dialog",
				props: {
					text: this.$t("programmatordev.stripe-checkout.settings.shippingZones.removalWarning")
				},
				on: {
					submit: () => {
						this.localValue = this.localValue.filter(row => row.id !== id);
						this.emit();
						this.$panel.dialog.close();
						this.$panel.drawer.close(this.drawerId);
					}
				}
			});
		},
		rowFromForm(row, value, optionIds) {
			const submittedOptions = Array.isArray(value.options) ? value.options : [];
			const options = submittedOptions.map(option => {
				const id = resolveStableId(option._id, optionIds);
				const existing = row.options.find(candidate => candidate.id === id) ?? {};

				return {
					...existing,
					id,
					// The immutable synchronization ID is also a stable internal key for Panel-created options.
					key: existing.key ?? id,
					label: option.label ?? "",
					amount: option.amount ?? null,
					deliveryEstimate: this.deliveryEstimateFromForm(option)
				};
			});

			if (this.technicalLocked) {
				return {
					...row,
					options: row.options.map(option => ({
						...option,
						label: options.find(candidate => candidate.id === option.id)?.label ?? option.label
					}))
				};
			}

			return {
				...row,
				countries: value.scope === "selected_countries" && Array.isArray(value.countries)
					? value.countries
					: [],
				name: value.name ?? "",
				options,
				scope: value.scope ?? "selected_countries"
			};
		},
		deliveryEstimateFromForm(option) {
			const minimum = option.deliveryEstimateMinimum || null;
			const maximum = option.deliveryEstimateMaximum || null;

			if (minimum === null && maximum === null) {
				return null;
			}

			return {
				minimum,
				maximum,
				unit: option.deliveryEstimateUnit || "business_day"
			};
		},
		sort(rows) {
			if (this.technicalLocked || this.disabled) {
				return;
			}

			const current = new Map(this.localValue.map(row => [row.id, row]));
			this.localValue = rows.map(row => current.get(row._id)).filter(Boolean);
			this.emit();
		},
		toFormValue(row) {
			return {
				countries: row.countries,
				name: row.name,
				options: row.options.map(option => {
					const deliveryEstimate = option.deliveryEstimate ?? {};

					return {
						_id: option.id,
						amount: option.amount,
						deliveryEstimateMaximum: deliveryEstimate.maximum ?? null,
						deliveryEstimateMinimum: deliveryEstimate.minimum ?? null,
						deliveryEstimateUnit: deliveryEstimate.unit ?? "business_day",
						label: option.label
					};
				}),
				scope: row.scope
			};
		},
		updateFromForm(row, updated) {
			if (this.isComplete(updated) === false) {
				return;
			}

			const index = this.localValue.findIndex(item => item.id === row.id);

			if (index === -1) {
				this.localValue.push(updated);
			} else {
				this.localValue.splice(index, 1, updated);
			}

			this.emit();
		}
	}
};
</script>

<style>
.k-stripe-checkout-shipping-zones-field__content {
	display: grid;
	gap: var(--spacing-2);
}

.k-stripe-checkout-shipping-zones-field__content[data-language-transitioning="true"] {
	visibility: hidden;
}
</style>
