<template>
	<k-link v-if="value.kind === 'link'" :to="value.link">
		{{ value.text }}
	</k-link>
	<time v-else-if="value.kind === 'datetime'" :datetime="value.value">
		{{ formatDateTime(value.value) }}
	</time>
	<span v-else-if="value.kind === 'customer'" class="k-stripe-checkout-order-customer">
		<strong v-if="value.name">{{ value.name }}</strong>
		<span v-if="value.email">{{ value.email }}</span>
		<span v-if="!value.name && !value.email">
			{{ $t("programmatordev.stripe-checkout.orders.index.customerUnavailable") }}
		</span>
	</span>
	<k-tag
		v-else-if="value.kind === 'status'"
		element="span"
		:text="statusLabel(value)"
		:theme="value.theme"
	/>
	<span v-else-if="value.kind === 'attention'" class="k-stripe-checkout-order-attention">
		<k-tag
			v-for="badge in value.badges"
			:key="`${badge.group}:${badge.status}`"
			element="span"
			:text="statusLabel(badge)"
			:theme="badge.theme"
		/>
	</span>
</template>

<script>
export default {
	name: "OrderTableValuePreview",
	props: {
		value: { type: Object, required: true }
	},
	methods: {
		formatDateTime(value) {
			const date = new Date(value);

			if (Number.isNaN(date.getTime())) {
				return value;
			}

			return new Intl.DateTimeFormat(this.$panel.translation.code.replace("_", "-"), {
				dateStyle: "medium",
				timeStyle: "short"
			}).format(date);
		},
		statusLabel(value) {
			return this.$t(
				`programmatordev.stripe-checkout.orders.index.status.${value.group}.${value.status}`
			);
		}
	}
};
</script>

<style>
.k-stripe-checkout-order-customer {
	display: grid;
	gap: var(--spacing-1);
}

.k-stripe-checkout-order-customer span {
	color: var(--color-text-dimmed);
}

.k-stripe-checkout-order-attention {
	display: flex;
	flex-wrap: wrap;
	gap: var(--spacing-1);
}
</style>
