<template>
	<k-field v-bind="$props" class="k-stripe-checkout-options-field">
		<template
			v-if="languageTransitioning === false && technicalLocked === false && disabled === false"
			#options
		>
			<k-button-option layout="collapsed">
				<k-button
					:responsive="true"
					icon="add"
					size="xs"
					variant="filled"
					@click="addOption"
				>
					{{ $t("programmatordev.stripe-checkout.options.addOption") }}
				</k-button>
				<k-button
					v-if="presets.length"
					icon="angle-down"
					size="xs"
					variant="filled"
					:aria-label="$t('programmatordev.stripe-checkout.options.addOptions')"
					:title="$t('programmatordev.stripe-checkout.options.addOptions')"
					@click="$refs.addOptionMenu.toggle()"
				/>
				<k-dropdown-content
					v-if="presets.length"
					ref="addOptionMenu"
					align-x="end"
					:options="addOptionActions"
				/>
			</k-button-option>
		</template>

		<div
			class="k-stripe-checkout-options-field__content"
			:aria-busy="languageTransitioning"
			:data-language-transitioning="languageTransitioning"
		>
			<k-box
				v-if="technicalLocked"
				theme="info"
				:text="$t('programmatordev.stripe-checkout.options.translationHelp')"
			/>

			<k-box
				v-if="localValue.options.length === 0"
				theme="empty"
				:text="emptyText"
			/>

			<k-table
				v-if="localValue.options.length"
				class="k-stripe-checkout-options-field__options-table"
				:columns="optionColumns"
				:disabled="disabled"
				:index="1"
				:options="optionActions"
				:rows="optionRows"
				:sortable="technicalLocked === false && localValue.options.length > 1"
				@cell="openOptionByRow($event.row)"
				@input="sortOptions"
				@option="handleOptionAction"
			/>

			<section
				v-if="technicalLocked === false && localValue.variants.length"
				class="k-stripe-checkout-options-field__matrix"
			>
				<header class="k-stripe-checkout-options-field__matrix-header">
					<h3 class="k-label">
						{{ $t("programmatordev.stripe-checkout.options.matrix") }}
					</h3>
					<span class="k-stripe-checkout-options-field__matrix-count">
						{{ variantCountText }}
					</span>
				</header>

				<k-table
					class="k-stripe-checkout-options-field__variants-table"
					:columns="variantColumns"
					:disabled="disabled"
					:fields="variantTableFields"
					:index="false"
					:options="variantActions"
					:pagination="variantPages > 1 ? variantPagination : false"
					:rows="variantRows"
					:sortable="false"
					@cell="openVariantByRow($event.row)"
					@input="updateVariantRows"
					@option="handleVariantAction"
					@paginate="variantPage = $event.page"
				/>
			</section>
		</div>
	</k-field>
</template>

<script>
import {
	formatAmount,
	importPreset,
	reconcile,
	resolveStableId,
	stableId
} from "../product-options.js";

const emptyValue = () => ({ options: [], variants: [] });

export default {
	props: {
		disabled: Boolean,
		endpoints: Object,
		help: String,
		icon: String,
		label: String,
		name: String,
		required: Boolean,
		type: {
			type: String,
			default: "stripe-checkout-options"
		},
		when: String,
		value: {
			type: Object,
			default: emptyValue
		},
		priceSource: {
			type: String,
			default: "kirby"
		},
		currency: {
			type: String,
			default: "EUR"
		},
		serverTechnicalLocked: {
			type: Boolean,
			default: false
		},
		presets: {
			type: Array,
			default: () => []
		},
		pricesReadable: {
			type: Boolean,
			default: false
		}
	},
	data() {
		return {
			localValue: this.clone(this.value),
			variantPage: 1,
			variantPageSize: 10
		};
	},
	computed: {
		addOptionActions() {
			return [
				{
					click: () => this.addOption(),
					icon: "add",
					text: this.$t("programmatordev.stripe-checkout.options.addOption")
				},
				"-",
				...this.presets.map(preset => ({
					click: () => this.importPreset(preset),
					icon: "download",
					text: this.$t("programmatordev.stripe-checkout.options.importPreset", {
						label: preset.label
					})
				}))
			];
		},
		emptyText() {
			return this.$t(
				this.presets.length
					? "programmatordev.stripe-checkout.options.emptyWithPresets"
					: "programmatordev.stripe-checkout.options.empty"
			);
		},
		optionColumns() {
			return {
				label: {
					label: this.$t("programmatordev.stripe-checkout.options.optionLabel"),
					mobile: true,
					type: "text",
					width: "1/3"
				},
				values: {
					label: this.$t("programmatordev.stripe-checkout.options.valuesLabel"),
					type: "text"
				}
			};
		},
		optionDrawerId() {
			return `${this.name ?? "stripe-checkout-options"}-option`;
		},
		optionActions() {
			const actions = [
				{
					click: "edit",
					icon: "edit",
					text: this.$t("edit")
				}
			];

			if (this.technicalLocked === false && this.disabled === false) {
				actions.push(
					"-",
					{
						click: "remove",
						icon: "trash",
						text: this.$t("delete")
					}
				);
			}

			return actions;
		},
		optionRows() {
			return this.localValue.options.map(option => ({
				_id: option.id,
				label: option.label,
				values: option.values.map(value => value.label).join(", ")
			}));
		},
		variantPages() {
			return Math.max(1, Math.ceil(this.localValue.variants.length / this.variantPageSize));
		},
		variantCountText() {
			const count = this.localValue.variants.length;

			return this.$t(
				count === 1
					? "programmatordev.stripe-checkout.options.count.one"
					: "programmatordev.stripe-checkout.options.count.many",
				{ count }
			);
		},
		variantColumns() {
			return {
				enabled: {
					align: "center",
					label: "",
					mobile: true,
					type: "toggle",
					width: "4rem"
				},
				combination: {
					label: this.$t("programmatordev.stripe-checkout.options.variantColumn"),
					mobile: true,
					type: "text"
				},
				sku: {
					label: this.$t("programmatordev.stripe-checkout.options.sku"),
					type: "text"
				},
				price: {
					after: this.currency,
					label: this.$t("programmatordev.stripe-checkout.options.price"),
					type: "stripe-checkout-variant-value"
				},
				shipping: {
					label: this.$t("programmatordev.stripe-checkout.options.shipping.label"),
					type: "stripe-checkout-variant-value"
				}
			};
		},
		variantDrawerId() {
			return `${this.name ?? "stripe-checkout-options"}-variant`;
		},
		variantActions() {
			return [
				{
					click: "edit",
					icon: "edit",
					text: this.$t("edit")
				}
			];
		},
		variantPagination() {
			const offset = (this.variantPage - 1) * this.variantPageSize;

			return {
				align: "center",
				details: true,
				limit: this.variantPageSize,
				offset,
				page: this.variantPage,
				total: this.localValue.variants.length
			};
		},
		variantRows() {
			const offset = this.variantPagination.offset;

			return this.localValue.variants
				.slice(offset, offset + this.variantPageSize)
				.map(variant => ({
					_id: variant.id,
					combination: this.variantLabel(variant),
					enabled: variant.enabled,
					price: this.pricePreview(variant),
					shipping: this.shippingPreview(variant.requiresShipping),
					sku: variant.sku
				}));
		},
		variantTableFields() {
			return {
				enabled: {
					disabled: this.disabled,
					text: [
						this.$t("programmatordev.stripe-checkout.options.disabled"),
						this.$t("programmatordev.stripe-checkout.options.enabled")
					],
					type: "toggle"
				}
			};
		},
		shippingOptions() {
			return [
				{ value: "inherit", text: this.$t("programmatordev.stripe-checkout.options.shipping.inherit") },
				{ value: "yes", text: this.$t("programmatordev.stripe-checkout.options.shipping.yes") },
				{ value: "no", text: this.$t("programmatordev.stripe-checkout.options.shipping.no") }
			];
		},
		technicalLocked() {
			// Kirby changes its global language state before refreshed field props
			// arrive, so either signal must lock technical controls immediately.
			return this.serverTechnicalLocked || this.panelTechnicalLocked;
		},
		languageTransitioning() {
			return this.$panel.languages.length > 1 &&
				this.serverTechnicalLocked !== this.panelTechnicalLocked;
		},
		panelTechnicalLocked() {
			return this.$panel.languages.length > 1 &&
				this.$panel.language.isDefault === false;
		}
	},
	watch: {
		value: {
			deep: true,
			handler(value) {
				this.localValue = this.clone(value);
				this.variantPage = 1;
			}
		}
	},
	methods: {
		clone(value) {
			return JSON.parse(JSON.stringify(value ?? emptyValue()));
		},
		emit() {
			this.$emit("input", this.clone(this.localValue));
		},
		reconcile() {
			this.localValue.variants = reconcile(
				this.localValue.options,
				this.localValue.variants
			);
			this.variantPage = Math.min(this.variantPage, this.variantPages);
			this.emit();
		},
		addOption() {
			this.openOption({
				id: stableId(),
				label: "",
				values: [{ id: stableId(), label: "" }]
			});
		},
		importPreset(preset) {
			this.localValue.options.push(...importPreset(preset));
			this.reconcile();
		},
		optionFields(option) {
			const fields = {
				label: {
					label: this.$t("programmatordev.stripe-checkout.options.optionLabel"),
					name: "label",
					required: true,
					type: "text"
				},
				values: {
					columns: {
						label: {
							label: this.$t("programmatordev.stripe-checkout.options.valueColumn"),
							width: "1/1"
						}
					},
					empty: this.$t("programmatordev.stripe-checkout.options.valuesEmpty"),
					fields: {
						label: {
							label: this.$t("programmatordev.stripe-checkout.options.valueLabel"),
							name: "label",
							required: true,
							type: "text"
						}
					},
					label: this.$t("programmatordev.stripe-checkout.options.valuesLabel"),
					min: 1,
					name: "values",
					required: true,
					sortable: true,
					type: "structure"
				}
			};

			if (this.technicalLocked) {
				// Keep the familiar Structure editor while fixing its membership and
				// order to the canonical default-language values.
				fields.values.duplicate = false;
				fields.values.max = option.values.length;
				fields.values.min = option.values.length;
				fields.values.sortable = false;
				fields.values.type = "stripe-checkout-option-translation-values";
			}

			return this.$helper.field.subfields(this, fields);
		},
		optionFormValue(option) {
			return {
				label: option.label,
				values: option.values.map(value => ({
					_id: value.id,
					label: value.label
				}))
			};
		},
		optionFromForm(option, value, valueIds) {
			return {
				...option,
				label: value.label ?? "",
				values: Array.isArray(value.values)
					? value.values.map(submittedValue => ({
						id: resolveStableId(submittedValue._id, valueIds),
						label: submittedValue.label ?? ""
					}))
					: []
			};
		},
		optionIsComplete(option) {
			return typeof option.label === "string" &&
				option.label.trim() !== "" &&
				option.values.length > 0 &&
				option.values.every(value => typeof value.label === "string" && value.label.trim() !== "");
		},
		handleOptionAction(action, row) {
			if (action === "edit") {
				this.openOptionByRow(row);
			} else if (action === "remove") {
				this.requestRemoval(row._id);
			}
		},
		openOption(option, replace = false) {
			const index = this.localValue.options.findIndex(item => item.id === option.id);
			const previous = index > 0 ? this.localValue.options[index - 1] : null;
			const next = index >= 0 ? this.localValue.options[index + 1] ?? null : null;
			const valueIds = new Map(option.values.map(value => [value.id, value.id]));

			this.$panel.drawer.open({
				component: "k-stripe-checkout-options-drawer",
				id: this.optionDrawerId,
				on: {
					input: value => this.updateOptionFromForm(option, value, valueIds),
					next: () => this.openOption(next, true),
					prev: () => this.openOption(previous, true),
					remove: () => {
						if (index >= 0) {
							this.requestRemoval(option.id);
						}
					}
				},
				props: {
					disabled: this.disabled,
					icon: "list-bullet",
					next: next !== null,
					prev: previous !== null,
					removable: index >= 0 && this.technicalLocked === false && this.disabled === false,
					tabs: {
						content: {
							fields: this.optionFields(option)
						}
					},
					title: option.label || this.$t("programmatordev.stripe-checkout.options.addOption"),
					value: this.optionFormValue(option)
				},
				replace
			});
		},
		openOptionByRow(row) {
			const option = this.localValue.options.find(option => option.id === row._id);

			if (option) {
				this.openOption(option);
			}
		},
		sortOptions(rows) {
			if (this.technicalLocked || this.disabled) {
				return;
			}

			const options = new Map(this.localValue.options.map(option => [option.id, option]));
			this.localValue.options = rows
				.map(row => options.get(row._id))
				.filter(Boolean);
			this.reconcile();
		},
		updateOptionFromForm(option, value, valueIds) {
			const updated = this.optionFromForm(option, value, valueIds);

			// Keep incomplete drawer drafts local so Kirby never receives a
			// temporarily invalid option while the merchant is still editing it.
			if (this.optionIsComplete(updated) === false) {
				return;
			}

			const index = this.localValue.options.findIndex(item => item.id === option.id);

			if (index === -1) {
				this.localValue.options.push(updated);
			} else {
				this.localValue.options.splice(index, 1, updated);
			}

			this.reconcile();
		},
		handleVariantAction(action, row) {
			if (action === "edit") {
				this.openVariantByRow(row);
			}
		},
		openVariant(variant, replace = false) {
			if (variant === null) {
				return;
			}

			const index = this.localValue.variants.findIndex(item => item.id === variant.id);
			const previous = index > 0 ? this.localValue.variants[index - 1] : null;
			const next = index >= 0 ? this.localValue.variants[index + 1] ?? null : null;

			this.$panel.drawer.open({
				component: "k-stripe-checkout-options-drawer",
				id: this.variantDrawerId,
				on: {
					input: value => this.updateVariantFromForm(variant, value),
					next: () => this.openVariant(next, true),
					prev: () => this.openVariant(previous, true)
				},
				props: {
					disabled: this.disabled,
					icon: "list-bullet",
					next: next !== null,
					prev: previous !== null,
					tabs: {
						content: {
							fields: this.variantFields()
						}
					},
					title: this.variantLabel(variant),
					value: this.variantFormValue(variant)
				},
				replace
			});
		},
		openVariantByRow(row) {
			const variant = this.localValue.variants.find(variant => variant.id === row._id);

			if (variant) {
				this.openVariant(variant);
			}
		},
		pricePreview(variant) {
			const value = this.priceSource === "kirby" ? variant.price : variant.stripePriceId;
			const inherited = value === null || value === "";

			return {
				inherited,
				text: inherited
					? this.$t("programmatordev.stripe-checkout.options.price.inherit")
					: this.formatPrice(value)
			};
		},
		formatPrice(value) {
			if (this.priceSource !== "kirby") {
				return value;
			}

			return formatAmount(value, this.currency);
		},
		shippingLabel(value) {
			return this.shippingOptions.find(option => option.value === value)?.text ?? "";
		},
		shippingPreview(value) {
			return {
				inherited: value === "inherit",
				text: this.shippingLabel(value)
			};
		},
		updateVariantFromForm(variant, value) {
			const current = this.localValue.variants.find(item => item.id === variant.id);

			if (current === undefined) {
				return;
			}

			current.enabled = value.enabled === true;
			current.sku = value.sku || null;
			current.requiresShipping = ["inherit", "yes", "no"].includes(value.requiresShipping)
				? value.requiresShipping
				: "inherit";

			if (this.priceSource === "kirby") {
				current.price = typeof value.price === "string" && value.price !== ""
					? value.price
					: null;
			} else {
				current.stripePriceId = value.stripePriceId || null;
			}

			this.emit();
		},
		updateVariantRows(rows) {
			const variants = new Map(this.localValue.variants.map(variant => [variant.id, variant]));

			for (const row of rows) {
				const variant = variants.get(row._id);

				if (variant) {
					variant.enabled = row.enabled === true;
				}
			}

			this.emit();
		},
		variantFields() {
			const fields = {
				enabled: {
					label: this.$t("programmatordev.stripe-checkout.options.active"),
					name: "enabled",
					text: [
						this.$t("programmatordev.stripe-checkout.options.disabled"),
						this.$t("programmatordev.stripe-checkout.options.enabled")
					],
					type: "toggle"
				},
				sku: {
					label: this.$t("programmatordev.stripe-checkout.options.sku"),
					name: "sku",
					type: "text"
				}
			};

			if (this.priceSource === "kirby") {
				fields.price = {
					after: this.currency,
					label: this.$t("programmatordev.stripe-checkout.options.price"),
					name: "price",
					pattern: "[0-9]+(?:\\.[0-9]+)?",
					placeholder: this.$t("programmatordev.stripe-checkout.options.price.inherit"),
					type: "text"
				};
			} else {
				fields.stripePriceId = {
					disabled: this.pricesReadable === false,
					endpoint: `${this.endpoints.field}/prices`,
					label: this.$t("programmatordev.stripe-checkout.options.price"),
					name: "stripePriceId",
					placeholder: this.$t("programmatordev.stripe-checkout.options.price.inherit"),
					type: "stripe-checkout-price"
				};
			}

			fields.requiresShipping = {
				empty: false,
				label: this.$t("programmatordev.stripe-checkout.options.shipping.label"),
				name: "requiresShipping",
				options: this.shippingOptions,
				type: "select"
			};

			return this.$helper.field.subfields(this, fields);
		},
		variantFormValue(variant) {
			return {
				enabled: variant.enabled,
				price: variant.price,
				requiresShipping: variant.requiresShipping,
				sku: variant.sku,
				stripePriceId: variant.stripePriceId
			};
		},
		requestRemoval(id) {
			if (
				this.technicalLocked ||
				this.disabled ||
				this.localValue.options.some(option => option.id === id) === false
			) {
				return;
			}

			this.$panel.dialog.open({
				component: "k-remove-dialog",
				props: {
					text: this.$t("programmatordev.stripe-checkout.options.removalWarning")
				},
				on: {
					submit: () => {
						this.localValue.options = this.localValue.options.filter(
							option => option.id !== id
						);
						this.reconcile();
						this.$panel.dialog.close();
						this.$panel.drawer.close(this.optionDrawerId);
					}
				}
			});
		},
		variantLabel(variant) {
			return this.localValue.options
				.map(option => option.values.find(value => value.id === variant.selectedOptions[option.id])?.label)
				.filter(Boolean)
				.join(" / ");
		}
	}
};
</script>

<style>
.k-stripe-checkout-options-field__content {
	display: grid;
	gap: var(--spacing-4);
}

.k-stripe-checkout-options-field__content[data-language-transitioning="true"] {
	visibility: hidden;
}

.k-stripe-checkout-options-field__matrix {
	display: grid;
	gap: var(--spacing-2);
}

.k-stripe-checkout-options-field__matrix-header {
	display: flex;
	align-items: center;
	gap: var(--spacing-2);
}

.k-stripe-checkout-options-field__matrix-count {
	color: var(--color-text-dimmed);
	font-size: var(--text-xs);
}

.k-stripe-checkout-options-field__options-table td.k-table-column,
.k-stripe-checkout-options-field__variants-table td.k-table-column {
	cursor: pointer;
}

.k-stripe-checkout-options-field__variants-table :is(th, td)[data-column-id="enabled"] {
	width: 4rem !important;
}

.k-stripe-checkout-options-field__variants-table td[data-column-id="enabled"] {
	cursor: default;
}

.k-stripe-checkout-options-field__variants-table td[data-column-id="enabled"] .k-toggle-input {
	justify-content: center;
}

.k-stripe-checkout-options-field__variants-table td[data-column-id="enabled"] .k-choice-input-label {
	/* Preserve the toggle's accessible text without adding visual table noise. */
	position: absolute;
	width: 1px;
	height: 1px;
	padding: 0;
	margin: -1px;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	white-space: nowrap;
	border-width: 0;
}
</style>
