<template>
	<k-field v-bind="$props" class="k-stripe-checkout-custom-fields-field">
		<template
			v-if="languageTransitioning === false && technicalLocked === false && disabled === false && localValue.length < 3"
			#options
		>
			<k-button
				icon="add"
				size="xs"
				variant="filled"
				@click="add"
			>
				{{ $t("programmatordev.stripe-checkout.customFields.add") }}
			</k-button>
		</template>

		<div
			class="k-stripe-checkout-custom-fields-field__content"
			:aria-busy="languageTransitioning"
			:data-language-transitioning="languageTransitioning"
		>
			<k-box
				v-if="technicalLocked"
				theme="info"
				:text="$t('programmatordev.stripe-checkout.customFields.translationHelp')"
			/>

			<k-box
				v-if="localValue.length === 0"
				theme="empty"
				:text="$t('programmatordev.stripe-checkout.customFields.empty')"
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
			default: "stripe-checkout-custom-fields"
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
		columns() {
			return {
				key: {
					label: this.$t("programmatordev.stripe-checkout.customFields.key.label"),
					mobile: true,
					type: "text"
				},
				label: {
					label: this.$t("programmatordev.stripe-checkout.customFields.label.label"),
					mobile: true,
					type: "text"
				},
				type: {
					label: this.$t("programmatordev.stripe-checkout.customFields.type.label"),
					type: "text"
				},
				required: {
					label: this.$t("programmatordev.stripe-checkout.customFields.required.label"),
					type: "text"
				}
			};
		},
		drawerId() {
			return `${this.name ?? "stripe-checkout-custom-fields"}-row`;
		},
		languageTransitioning() {
			// Kirby changes global language state before refreshed field props
			// arrive; hide the stale language briefly instead of exposing it.
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
				key: row.key,
				label: row.label,
				required: row.required
					? this.$t("yes")
					: this.$t("no"),
				type: this.typeLabel(row.type)
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
			if (this.localValue.length >= 3 || this.technicalLocked || this.disabled) {
				return;
			}

			this.open({
				defaultValue: null,
				id: stableId(),
				key: "",
				label: "",
				maximumLength: null,
				minimumLength: null,
				options: [],
				required: false,
				type: "text"
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
			const dropdownOptions = {
				columns: {
					value: {
						label: this.$t("programmatordev.stripe-checkout.customFields.option.value.label"),
						mobile: true,
						type: "text",
						width: "1/2"
					},
					label: {
						label: this.$t("programmatordev.stripe-checkout.customFields.option.label.label"),
						mobile: true,
						type: "text",
						width: "1/2"
					}
				},
				duplicate: false,
				empty: this.$t("programmatordev.stripe-checkout.customFields.options.empty"),
				fields: {
					value: {
						disabled: technicalDisabled,
						help: this.$t("programmatordev.stripe-checkout.customFields.option.value.help"),
						label: this.$t("programmatordev.stripe-checkout.customFields.option.value.label"),
						maxlength: 100,
						name: "value",
						pattern: "[a-z0-9]{1,100}",
						required: true,
						type: "text"
					},
					label: {
						label: this.$t("programmatordev.stripe-checkout.customFields.option.label.label"),
						maxlength: 100,
						name: "label",
						required: this.technicalLocked === false,
						type: "text"
					}
				},
				label: this.$t("programmatordev.stripe-checkout.customFields.options.label"),
				max: this.technicalLocked ? row.options.length : 200,
				min: this.technicalLocked ? row.options.length : 1,
				name: "options",
				required: true,
				sortable: this.technicalLocked === false,
				type: this.technicalLocked
					? "stripe-checkout-synchronized-structure-rows"
					: "structure",
				when: {
					type: "dropdown"
				}
			};
			const fields = {
				key: {
					disabled: technicalDisabled,
					help: this.$t("programmatordev.stripe-checkout.customFields.key.help"),
					label: this.$t("programmatordev.stripe-checkout.customFields.key.label"),
					maxlength: 200,
					name: "key",
					pattern: "[a-z0-9]{1,200}",
					required: true,
					type: "text"
				},
				label: {
					label: this.$t("programmatordev.stripe-checkout.customFields.label.label"),
					maxlength: 50,
					name: "label",
					required: this.technicalLocked === false,
					type: "text"
				},
				type: {
					disabled: technicalDisabled,
					label: this.$t("programmatordev.stripe-checkout.customFields.type.label"),
					name: "type",
					options: ["text", "numeric", "dropdown"].map(type => ({
						text: this.typeLabel(type),
						value: type
					})),
					required: true,
					type: "select"
				},
				required: {
					disabled: technicalDisabled,
					label: this.$t("programmatordev.stripe-checkout.customFields.required.label"),
					name: "required",
					type: "toggle"
				},
				...this.lengthFields("text", technicalDisabled),
				...this.lengthFields("numeric", technicalDisabled),
				defaultValue: {
					disabled: technicalDisabled,
					label: this.$t("programmatordev.stripe-checkout.customFields.defaultValue.label"),
					maxlength: 255,
					name: "defaultValue",
					type: "text"
				},
				options: dropdownOptions
			};

			return this.$helper.field.subfields(this, fields);
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

			return /^[a-z0-9]{1,200}$/.test(row.key) &&
				typeof row.label === "string" &&
				row.label.trim() !== "" &&
				["text", "numeric", "dropdown"].includes(row.type) &&
				(row.type !== "dropdown" || (
					row.options.length > 0 &&
					row.options.every(option =>
						/^[a-z0-9]{1,100}$/.test(option.value) &&
						typeof option.label === "string" &&
						option.label.trim() !== ""
					)
				));
		},
		lengthFields(type, disabled) {
			const suffix = type.charAt(0).toUpperCase() + type.slice(1);

			return {
				[`minimumLength${suffix}`]: {
					disabled,
					label: this.$t(`programmatordev.stripe-checkout.customFields.minimumLength.${type}`),
					max: 255,
					min: 1,
					name: `minimumLength${suffix}`,
					type: "number",
					when: {
						type
					},
					width: "1/2"
				},
				[`maximumLength${suffix}`]: {
					disabled,
					label: this.$t(`programmatordev.stripe-checkout.customFields.maximumLength.${type}`),
					max: 255,
					min: 1,
					name: `maximumLength${suffix}`,
					type: "number",
					when: {
						type
					},
					width: "1/2"
				}
			};
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
					input: value => {
						const updated = this.rowFromForm(row, value, optionIds);

						this.updateFromForm(row, updated);
					},
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
					icon: "list-bullet",
					next: next !== null,
					prev: previous !== null,
					removable: index >= 0 && this.technicalLocked === false && this.disabled === false,
					tabs: {
						content: {
							fields: this.fields(row)
						}
					},
					title: row.label || this.$t("programmatordev.stripe-checkout.customFields.add"),
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
					text: this.$t("programmatordev.stripe-checkout.customFields.removalWarning")
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
				defaultValue: row.defaultValue,
				key: row.key,
				label: row.label,
				maximumLengthNumeric: row.maximumLength,
				maximumLengthText: row.maximumLength,
				minimumLengthNumeric: row.minimumLength,
				minimumLengthText: row.minimumLength,
				options: row.options.map(option => ({
					_id: option.id,
					label: option.label,
					value: option.value
				})),
				required: row.required,
				type: row.type
			};
		},
		typeLabel(type) {
			return this.$t(`programmatordev.stripe-checkout.customFields.type.${type}`);
		},
		rowFromForm(row, value, optionIds) {
			const submittedOptions = Array.isArray(value.options) ? value.options : [];
			const type = value.type ?? row.type;
			const lengthSuffix = type.charAt(0).toUpperCase() + type.slice(1);
			const options = submittedOptions.map(option => ({
				id: resolveStableId(option._id, optionIds),
				label: option.label ?? "",
				value: option.value ?? ""
			}));

			return this.technicalLocked
				? {
					...row,
					label: value.label ?? "",
					options: row.options.map(option => ({
						...option,
						label: options.find(candidate => candidate.id === option.id)?.label ?? option.label
					}))
				}
				: {
					...row,
					defaultValue: value.defaultValue || null,
					key: value.key ?? "",
					label: value.label ?? "",
					maximumLength: type === "dropdown"
						? null
						: value[`maximumLength${lengthSuffix}`] || null,
					minimumLength: type === "dropdown"
						? null
						: value[`minimumLength${lengthSuffix}`] || null,
					options: type === "dropdown" ? options : [],
					required: value.required === true,
					type
				};
		},
		updateFromForm(row, updated) {
			// Avoid sending incomplete new drawer rows into Kirby's live form.
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
.k-stripe-checkout-custom-fields-field__content {
	display: grid;
	gap: var(--spacing-2);
}

.k-stripe-checkout-custom-fields-field__content[data-language-transitioning="true"] {
	visibility: hidden;
}
</style>
