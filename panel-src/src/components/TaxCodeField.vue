<script>
import CataloguePickerField from "./CataloguePickerField.vue";

export default {
	name: "TaxCodeField",
	extends: CataloguePickerField,
	props: {
		translationPrefix: { type: String, default: "programmatordev.stripe-checkout.taxCodes" },
		queryParameter: { type: String, default: "taxCode" },
		dialogComponent: { type: String, default: "k-stripe-checkout-tax-code-dialog" },
		referenceIcon: { type: String, default: "tag" },
		type: { type: String, default: "stripe-checkout-tax-code" }
	}
};
</script>
