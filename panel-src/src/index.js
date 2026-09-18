import CustomFieldsField from "./components/CustomFieldsField.vue";
import OptionsField from "./components/OptionsField.vue";
import SynchronizedStructureDrawer from "./components/SynchronizedStructureDrawer.vue";
import SynchronizedStructureRowsField from "./components/SynchronizedStructureRowsField.vue";
import VariantValuePreview from "./components/VariantValuePreview.vue";
import StripePriceField from "./components/StripePriceField.vue";
import StripePriceDialog from "./components/StripePriceDialog.vue";
import ShippingZonesField from "./components/ShippingZonesField.vue";
import TaxCodeField from "./components/TaxCodeField.vue";
import TaxCodeDialog from "./components/TaxCodeDialog.vue";
import CatalogueDialogHeader from "./components/CatalogueDialogHeader.vue";
import MoneyPreview from "./components/MoneyPreview.vue";

panel.plugin("programmatordev/stripe-checkout", {
	components: {
		"k-stripe-checkout-catalogue-dialog-header": CatalogueDialogHeader,
		"k-stripe-checkout-money-field-preview": MoneyPreview,
		"k-stripe-checkout-synchronized-structure-drawer": SynchronizedStructureDrawer,
		"k-stripe-checkout-price-dialog": StripePriceDialog,
		"k-stripe-checkout-tax-code-dialog": TaxCodeDialog,
		"k-stripe-checkout-variant-value-field-preview": VariantValuePreview
	},
	fields: {
		"stripe-checkout-custom-fields": CustomFieldsField,
		"stripe-checkout-synchronized-structure-rows": SynchronizedStructureRowsField,
		"stripe-checkout-options": OptionsField,
		"stripe-checkout-price": StripePriceField,
		"stripe-checkout-shipping-zones": ShippingZonesField,
		"stripe-checkout-tax-code": TaxCodeField
	}
});
