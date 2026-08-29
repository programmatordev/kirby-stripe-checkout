<template>
	<k-text-field-preview
		:class="{
			'k-stripe-checkout-variant-value-preview--inherited': value.inherited
		}"
		:column="previewColumn"
		:value="value.text"
	/>
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
</style>
