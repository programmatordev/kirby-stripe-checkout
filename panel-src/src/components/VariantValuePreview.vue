<template>
	<div
		class="k-stripe-checkout-variant-value-preview"
		:class="{
			'k-stripe-checkout-variant-value-preview--item': value.item
		}"
	>
		<k-item
			v-if="value.item"
			v-bind="value.item"
			:link="false"
			class="k-stripe-checkout-variant-value-preview__item"
		/>
		<k-text-field-preview
			v-else
			:class="{
				'k-stripe-checkout-variant-value-preview--inherited': value.inherited
			}"
			:column="previewColumn"
			:value="value.text"
		/>
	</div>
</template>

<script>
export default {
	props: {
		column: {
			type: Object,
			default: () => ({})
		},
		value: {
			type: Object,
			required: true
		}
	},
	computed: {
		previewColumn() {
			return {
				...this.column,
				after: this.value.inherited ? null : this.column.after
			};
		}
	}
};
</script>

<style>
.k-stripe-checkout-variant-value-preview--inherited {
	color: var(--color-text-dimmed);
}

.k-stripe-checkout-variant-value-preview,
.k-stripe-checkout-variant-value-preview__item {
	min-width: 0;
}

.k-stripe-checkout-variant-value-preview__item :is(.k-item-title, .k-item-info) {
	max-width: 100%;
}

.k-stripe-checkout-variant-value-preview--item {
	padding: 0.325rem var(--table-cell-padding);
}

.k-stripe-checkout-variant-value-preview__item {
	--item-color-back: var(--panel-color-back);
}
</style>
