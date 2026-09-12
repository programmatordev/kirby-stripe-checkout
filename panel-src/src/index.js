import CustomFieldsField from "./components/CustomFieldsField.vue";
import OptionsField from "./components/OptionsField.vue";
import SynchronizedStructureDrawer from "./components/SynchronizedStructureDrawer.vue";
import SynchronizedStructureRowsField from "./components/SynchronizedStructureRowsField.vue";
import VariantValuePreview from "./components/VariantValuePreview.vue";
import StripePriceField from "./components/StripePriceField.vue";
import StripePriceDialog from "./components/StripePriceDialog.vue";

panel.plugin("programmatordev/stripe-checkout", {
	components: {
		"k-stripe-checkout-synchronized-structure-drawer": SynchronizedStructureDrawer,
		"k-stripe-checkout-price-dialog": StripePriceDialog,
		"k-stripe-checkout-variant-value-field-preview": VariantValuePreview
	},
	fields: {
		"stripe-checkout-custom-fields": CustomFieldsField,
		"stripe-checkout-synchronized-structure-rows": SynchronizedStructureRowsField,
		"stripe-checkout-options": OptionsField,
		"stripe-checkout-price": StripePriceField
	}
});
