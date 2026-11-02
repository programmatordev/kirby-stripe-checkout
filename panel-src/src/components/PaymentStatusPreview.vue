<template>
	<ul class="k-stripe-checkout-payment-status-preview k-tags-field-preview k-tags">
		<li>
			<k-tag
				element="div"
				:text="value.text"
				:theme="value.theme"
			/>
		</li>
	</ul>
</template>

<script>
export default {
	props: {
		value: {
			type: Object,
			required: true
		}
	}
};
</script>

<style>
.k-stripe-checkout-payment-status-preview .k-tag[data-theme] {
	--tag-color-back: var(--theme-color-back);
	--tag-color-text: var(--theme-color-text-highlight);
}
</style>
