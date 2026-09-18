<template>
	<k-text-field-preview
		:column="column"
		:value="formattedValue"
	/>
</template>

<script>
import { formatAmount } from "../money.js";

export default {
	props: {
		column: {
			type: Object,
			default: () => ({})
		},
		value: {
			type: [Number, String],
			default: ""
		}
	},
	computed: {
		formattedValue() {
			return formatAmount(this.value, this.column.after);
		}
	}
};
</script>
