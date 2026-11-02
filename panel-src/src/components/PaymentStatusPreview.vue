<template>
	<ul class="k-stripe-checkout-payment-status-preview k-tags-field-preview k-tags">
		<li v-for="status in value" :key="status.key">
			<k-tag
				element="div"
				:text="status.text"
				:theme="status.theme"
			/>
		</li>
	</ul>
</template>

<script>
export default {
	props: {
		value: {
			type: Array,
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
