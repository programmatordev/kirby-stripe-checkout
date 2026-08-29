<template>
	<k-field v-bind="$props" class="k-stripe-checkout-variants-field">
		<template
			v-if="languageTransitioning === false && technicalLocked === false && disabled === false"
			#options
		>
			<k-button-group layout="collapsed">
				<k-button
					:responsive="true"
					icon="add"
					size="xs"
					variant="filled"
					@click="addGroup"
				>
					{{ $t("programmatordev.stripe-checkout.variants.addGroup") }}
				</k-button>
				<k-button
					v-if="presets.length"
					icon="angle-down"
					size="xs"
					variant="filled"
					:aria-label="$t('programmatordev.stripe-checkout.variants.addOptions')"
					:title="$t('programmatordev.stripe-checkout.variants.addOptions')"
					@click="$refs.addOptions.toggle()"
				/>
				<k-dropdown-content
					v-if="presets.length"
					ref="addOptions"
					align-x="end"
					:options="addOptions"
				/>
			</k-button-group>
		</template>

		<div
			class="k-stripe-checkout-variants-field__content"
			:aria-busy="languageTransitioning"
			:data-language-transitioning="languageTransitioning"
		>
			<k-box
				v-if="technicalLocked"
				theme="info"
				:text="$t('programmatordev.stripe-checkout.variants.translationHelp')"
			/>

			<k-box
				v-if="localValue.groups.length === 0"
				theme="empty"
				:text="emptyText"
			/>

			<k-table
				v-if="localValue.groups.length"
				class="k-stripe-checkout-variants-field__groups-table"
				:columns="groupColumns"
				:disabled="disabled"
				:index="1"
				:options="groupOptions"
				:rows="groupRows"
				:sortable="technicalLocked === false && localValue.groups.length > 1"
				@cell="openGroupByRow($event.row)"
				@input="sortGroups"
				@option="handleGroupOption"
			/>

			<section
				v-if="technicalLocked === false && localValue.variants.length"
				class="k-stripe-checkout-variants-field__matrix"
			>
				<header class="k-stripe-checkout-variants-field__matrix-header">
					<h3 class="k-label">
						{{ $t("programmatordev.stripe-checkout.variants.matrix") }}
					</h3>
					<span class="k-stripe-checkout-variants-field__matrix-count">
						{{ variantCountText }}
					</span>
				</header>

				<k-table
					class="k-stripe-checkout-variants-field__variants-table"
					:columns="variantColumns"
					:disabled="disabled"
					:fields="variantTableFields"
					:index="false"
					:options="variantOptions"
					:pagination="variantPages > 1 ? variantPagination : false"
					:rows="variantRows"
					:sortable="false"
					@cell="openVariantByRow($event.row)"
					@input="updateVariantRows"
					@option="handleVariantOption"
					@paginate="variantPage = $event.page"
				/>
			</section>
		</div>
	</k-field>
</template>

<script>
import {
	decimalString,
	importPreset,
	reconcile,
	resolveStableId,
	stableId
} from "../variants.js";

const emptyValue = () => ({ groups: [], variants: [] });

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
			default: "stripe-checkout-variants"
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
		addOptions() {
			return [
				{
					click: () => this.addGroup(),
					icon: "add",
					text: this.$t("programmatordev.stripe-checkout.variants.addGroup")
				},
				"-",
				...this.presets.map(preset => ({
					click: () => this.importPreset(preset),
					icon: "download",
					text: this.$t("programmatordev.stripe-checkout.variants.importPreset", {
						label: preset.label
					})
				}))
			];
		},
		emptyText() {
			return this.$t(
				this.presets.length
					? "programmatordev.stripe-checkout.variants.emptyWithPresets"
					: "programmatordev.stripe-checkout.variants.empty"
			);
		},
		groupColumns() {
			return {
				label: {
					label: this.$t("programmatordev.stripe-checkout.variants.groupLabel"),
					mobile: true,
					type: "text",
					width: "1/3"
				},
				values: {
					label: this.$t("programmatordev.stripe-checkout.variants.valuesLabel"),
					type: "text"
				}
			};
		},
		groupDrawerId() {
			return `${this.name ?? "stripe-checkout-variants"}-group`;
		},
		groupOptions() {
			const options = [
				{
					click: "edit",
					icon: "edit",
					text: this.$t("edit")
				}
			];

			if (this.technicalLocked === false && this.disabled === false) {
				options.push(
					"-",
					{
						click: "remove",
						icon: "trash",
						text: this.$t("delete")
					}
				);
			}

			return options;
		},
		groupRows() {
			return this.localValue.groups.map(group => ({
				_id: group.id,
				label: group.label,
				values: group.values.map(value => value.label).join(", ")
			}));
		},
		variantPages() {
			return Math.max(1, Math.ceil(this.localValue.variants.length / this.variantPageSize));
		},
		variantCountText() {
			const count = this.localValue.variants.length;

			return this.$t(
				count === 1
					? "programmatordev.stripe-checkout.variants.count.one"
					: "programmatordev.stripe-checkout.variants.count.many",
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
					label: this.$t("programmatordev.stripe-checkout.variants.variantColumn"),
					mobile: true,
					type: "text"
				},
				sku: {
					label: this.$t("programmatordev.stripe-checkout.variants.sku"),
					type: "text"
				},
				price: {
					after: this.priceSource === "kirby" ? this.currency : null,
					label: this.$t("programmatordev.stripe-checkout.variants.price"),
					type: "stripe-checkout-variant-value"
				},
				shipping: {
					label: this.$t("programmatordev.stripe-checkout.variants.shipping.label"),
					type: "stripe-checkout-variant-value"
				}
			};
		},
		variantDrawerId() {
			return `${this.name ?? "stripe-checkout-variants"}-variant`;
		},
		variantOptions() {
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
						this.$t("programmatordev.stripe-checkout.variants.disabled"),
						this.$t("programmatordev.stripe-checkout.variants.enabled")
					],
					type: "toggle"
				}
			};
		},
		shippingOptions() {
			return [
				{ value: "inherit", text: this.$t("programmatordev.stripe-checkout.variants.shipping.inherit") },
				{ value: "yes", text: this.$t("programmatordev.stripe-checkout.variants.shipping.yes") },
				{ value: "no", text: this.$t("programmatordev.stripe-checkout.variants.shipping.no") }
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
				this.localValue.groups,
				this.localValue.variants
			);
			this.variantPage = Math.min(this.variantPage, this.variantPages);
			this.emit();
		},
		addGroup() {
			this.openGroup({
				id: stableId(),
				label: "",
				values: [{ id: stableId(), label: "" }]
			});
		},
		importPreset(preset) {
			this.localValue.groups.push(...importPreset(preset));
			this.reconcile();
		},
		groupFields(group) {
			const fields = {
				label: {
					label: this.$t("programmatordev.stripe-checkout.variants.groupLabel"),
					name: "label",
					required: true,
					type: "text"
				},
				values: {
					columns: {
						label: {
							label: this.$t("programmatordev.stripe-checkout.variants.valueColumn"),
							width: "1/1"
						}
					},
					empty: this.$t("programmatordev.stripe-checkout.variants.valuesEmpty"),
					fields: {
						label: {
							label: this.$t("programmatordev.stripe-checkout.variants.valueLabel"),
							name: "label",
							required: true,
							type: "text"
						}
					},
					label: this.$t("programmatordev.stripe-checkout.variants.valuesLabel"),
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
				fields.values.max = group.values.length;
				fields.values.min = group.values.length;
				fields.values.sortable = false;
				fields.values.type = "stripe-checkout-variant-translation-values";
			}

			return this.$helper.field.subfields(this, fields);
		},
		groupFormValue(group) {
			return {
				label: group.label,
				values: group.values.map(value => ({
					_id: value.id,
					label: value.label
				}))
			};
		},
		groupFromForm(group, value, valueIds) {
			return {
				...group,
				label: value.label ?? "",
				values: Array.isArray(value.values)
					? value.values.map(option => ({
						id: resolveStableId(option._id, valueIds),
						label: option.label ?? ""
					}))
					: []
			};
		},
		groupIsComplete(group) {
			return typeof group.label === "string" &&
				group.label.trim() !== "" &&
				group.values.length > 0 &&
				group.values.every(value => typeof value.label === "string" && value.label.trim() !== "");
		},
		handleGroupOption(option, row) {
			if (option === "edit") {
				this.openGroupByRow(row);
			} else if (option === "remove") {
				this.requestRemoval(row._id);
			}
		},
		openGroup(group, replace = false) {
			const index = this.localValue.groups.findIndex(item => item.id === group.id);
			const previous = index > 0 ? this.localValue.groups[index - 1] : null;
			const next = index >= 0 ? this.localValue.groups[index + 1] ?? null : null;
			const valueIds = new Map(group.values.map(value => [value.id, value.id]));

			this.$panel.drawer.open({
				component: "k-stripe-checkout-variant-drawer",
				id: this.groupDrawerId,
				on: {
					input: value => this.updateGroupFromForm(group, value, valueIds),
					next: () => this.openGroup(next, true),
					prev: () => this.openGroup(previous, true),
					remove: () => {
						if (index >= 0) {
							this.requestRemoval(group.id);
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
							fields: this.groupFields(group)
						}
					},
					title: group.label || this.$t("programmatordev.stripe-checkout.variants.addGroup"),
					value: this.groupFormValue(group)
				},
				replace
			});
		},
		openGroupByRow(row) {
			const group = this.localValue.groups.find(group => group.id === row._id);

			if (group) {
				this.openGroup(group);
			}
		},
		sortGroups(rows) {
			if (this.technicalLocked || this.disabled) {
				return;
			}

			const groups = new Map(this.localValue.groups.map(group => [group.id, group]));
			this.localValue.groups = rows
				.map(row => groups.get(row._id))
				.filter(Boolean);
			this.reconcile();
		},
		updateGroupFromForm(group, value, valueIds) {
			const updated = this.groupFromForm(group, value, valueIds);

			// Keep incomplete drawer drafts local so Kirby never receives a
			// temporarily invalid group while the merchant is still editing it.
			if (this.groupIsComplete(updated) === false) {
				return;
			}

			const index = this.localValue.groups.findIndex(item => item.id === group.id);

			if (index === -1) {
				this.localValue.groups.push(updated);
			} else {
				this.localValue.groups.splice(index, 1, updated);
			}

			this.reconcile();
		},
		handleVariantOption(option, row) {
			if (option === "edit") {
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
				component: "k-stripe-checkout-variant-drawer",
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
					? this.$t("programmatordev.stripe-checkout.variants.price.inherit")
					: value
			};
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
				current.price = decimalString(value.price);
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
					label: this.$t("programmatordev.stripe-checkout.variants.active"),
					name: "enabled",
					text: [
						this.$t("programmatordev.stripe-checkout.variants.disabled"),
						this.$t("programmatordev.stripe-checkout.variants.enabled")
					],
					type: "toggle"
				},
				sku: {
					label: this.$t("programmatordev.stripe-checkout.variants.sku"),
					name: "sku",
					type: "text"
				}
			};

			if (this.priceSource === "kirby") {
				fields.price = {
					after: this.currency,
					label: this.$t("programmatordev.stripe-checkout.variants.price"),
					min: 0,
					name: "price",
					placeholder: this.$t("programmatordev.stripe-checkout.variants.price.inherit"),
					step: "any",
					type: "number"
				};
			} else {
				fields.stripePriceId = {
					label: this.$t("programmatordev.stripe-checkout.variants.price"),
					name: "stripePriceId",
					placeholder: this.$t("programmatordev.stripe-checkout.variants.price.inherit"),
					type: "text"
				};
			}

			fields.requiresShipping = {
				empty: false,
				label: this.$t("programmatordev.stripe-checkout.variants.shipping.label"),
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
				this.localValue.groups.some(group => group.id === id) === false
			) {
				return;
			}

			this.$panel.dialog.open({
				component: "k-remove-dialog",
				props: {
					text: this.$t("programmatordev.stripe-checkout.variants.removalWarning")
				},
				on: {
					submit: () => {
						this.localValue.groups = this.localValue.groups.filter(
							group => group.id !== id
						);
						this.reconcile();
						this.$panel.dialog.close();
						this.$panel.drawer.close(this.groupDrawerId);
					}
				}
			});
		},
		variantLabel(variant) {
			return this.localValue.groups
				.map(group => group.values.find(value => value.id === variant.choices[group.id])?.label)
				.filter(Boolean)
				.join(" / ");
		}
	}
};
</script>

<style>
.k-stripe-checkout-variants-field__content {
	display: grid;
	gap: var(--spacing-4);
}

.k-stripe-checkout-variants-field__content[data-language-transitioning="true"] {
	visibility: hidden;
}

.k-stripe-checkout-variants-field__matrix {
	display: grid;
	gap: var(--spacing-2);
}

.k-stripe-checkout-variants-field__matrix-header {
	display: flex;
	align-items: center;
	gap: var(--spacing-2);
}

.k-stripe-checkout-variants-field__matrix-count {
	color: var(--color-text-dimmed);
	font-size: var(--text-xs);
}

.k-stripe-checkout-variants-field__groups-table td.k-table-column,
.k-stripe-checkout-variants-field__variants-table td.k-table-column {
	cursor: pointer;
}

.k-stripe-checkout-variants-field__variants-table :is(th, td)[data-column-id="enabled"] {
	width: 4rem !important;
}

.k-stripe-checkout-variants-field__variants-table td[data-column-id="enabled"] {
	cursor: default;
}

.k-stripe-checkout-variants-field__variants-table td[data-column-id="enabled"] .k-toggle-input {
	justify-content: center;
}

.k-stripe-checkout-variants-field__variants-table td[data-column-id="enabled"] .k-choice-input-label {
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
