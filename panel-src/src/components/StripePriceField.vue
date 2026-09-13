<script>
import CataloguePickerField from "./CataloguePickerField.vue";

export default {
	name: "StripePriceField",
	extends: CataloguePickerField,
	props: {
		translationPrefix: { type: String, default: "programmatordev.stripe-checkout.prices" },
		queryParameter: { type: String, default: "price" },
		dialogComponent: { type: String, default: "k-stripe-checkout-price-dialog" },
		referenceIcon: { type: String, default: "money" },
		configurationErrorCode: { type: String, default: "prices.configuration_invalid" },
		currency: String,
		type: { type: String, default: "stripe-checkout-price" }
	}
};
</script>
