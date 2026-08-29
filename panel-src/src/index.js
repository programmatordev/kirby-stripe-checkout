import VariantField from "./components/VariantField.vue";
import VariantDrawer from "./components/VariantDrawer.vue";
import VariantTranslationValuesField from "./components/VariantTranslationValuesField.vue";
import VariantValuePreview from "./components/VariantValuePreview.vue";

panel.plugin("programmatordev/stripe-checkout", {
	components: {
		"k-stripe-checkout-variant-drawer": VariantDrawer,
		"k-stripe-checkout-variant-value-field-preview": VariantValuePreview
	},
	fields: {
		"stripe-checkout-variant-translation-values": VariantTranslationValuesField,
		"stripe-checkout-variants": VariantField
	}
});
