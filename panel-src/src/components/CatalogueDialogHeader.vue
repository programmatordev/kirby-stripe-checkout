<template>
	<header class="k-pages-dialog-navbar k-stripe-checkout-catalogue-dialog-header">
		<!-- Kirby hides disabled back buttons but keeps their space, balancing refresh. -->
		<k-button
			:disabled="back === false"
			:title="$t('back')"
			icon="angle-left"
			@click="$emit('back')"
		/>
		<k-headline>{{ title }}</k-headline>
		<k-button
			:aria-label="refreshLabel"
			:disabled="refreshing || $panel.dialog.isLoading"
			:title="refreshLabel"
			:icon="refreshing ? 'loader' : 'refresh'"
			class="k-stripe-checkout-catalogue-dialog-header__refresh"
			variant="filled"
			@click="$emit('refresh')"
		/>
	</header>
</template>

<script>
export default {
	name: "CatalogueDialogHeader",
	props: {
		back: Boolean,
		refreshing: Boolean,
		refreshLabel: { type: String, required: true },
		title: { type: String, required: true }
	},
	emits: ["back", "refresh"]
};
</script>

<style>
.k-stripe-checkout-catalogue-dialog-header {
	padding-inline-end: 0;
}

.k-stripe-checkout-catalogue-dialog-header .k-stripe-checkout-catalogue-dialog-header__refresh[aria-disabled="true"] {
	opacity: 1;
}

/* Native Models Dialog does not forward class attributes; scope via its header slot. */
.k-models-dialog:has(.k-stripe-checkout-catalogue-dialog-header) .k-item[data-layout="list"] :is(.k-item-title, .k-item-info) {
	min-width: 0;
	max-width: 100%;
	white-space: normal;
	overflow-wrap: anywhere;
	overflow: visible;
}
</style>
