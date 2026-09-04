import OptionsField from "./components/OptionsField.vue";
import OptionsDrawer from "./components/OptionsDrawer.vue";
import OptionTranslationValuesField from "./components/OptionTranslationValuesField.vue";
import VariantValuePreview from "./components/VariantValuePreview.vue";
import StripePriceField from "./components/StripePriceField.vue";

panel.plugin("programmatordev/stripe-checkout", {
	components: {
		"k-stripe-checkout-options-drawer": OptionsDrawer,
		"k-stripe-checkout-variant-value-field-preview": VariantValuePreview
	},
	fields: {
		"stripe-checkout-option-translation-values": OptionTranslationValuesField,
		"stripe-checkout-options": OptionsField,
		"stripe-checkout-price": StripePriceField
	}
});
