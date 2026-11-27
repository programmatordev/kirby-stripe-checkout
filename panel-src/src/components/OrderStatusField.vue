<script>
export default {
	name: "OrderStatusField",
	props: {
		help: String,
		id: [Number, String],
		label: [String, Boolean],
		name: [Number, String],
		reports: { type: Array, default: () => [] },
		size: { type: String, default: "large" }
	}
};
</script>

<template>
	<k-field
		:id="id"
		:help="help"
		:input="false"
		:label="label"
		:name="name"
		type="stripe-checkout-order-status"
	>
		<!-- Match Kirby's System view so each native report theme colors its label. -->
		<k-stats class="k-system-info" :reports="reports" :size="size" />
	</k-field>
</template>
