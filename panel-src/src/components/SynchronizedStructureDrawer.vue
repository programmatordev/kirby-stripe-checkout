<template>
	<k-form-drawer v-bind="$attrs" v-on="$listeners">
		<template #options>
			<k-button
				class="k-drawer-option"
				:disabled="prev === false"
				icon="angle-left"
				@click="$emit('prev')"
			/>
			<k-button
				class="k-drawer-option"
				:disabled="next === false"
				icon="angle-right"
				@click="$emit('next')"
			/>
			<k-button
				v-if="removable"
				class="k-drawer-option"
				icon="trash"
				@click="$emit('remove')"
			/>
		</template>
	</k-form-drawer>
</template>

<script>
export default {
	inheritAttrs: false,
	props: {
		next: Boolean,
		prev: Boolean,
		removable: Boolean
	}
};
</script>
