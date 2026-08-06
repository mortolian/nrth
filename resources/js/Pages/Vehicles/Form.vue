<script setup lang="ts">
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useForm } from 'vee-validate';
import { z } from 'zod';
import AppLayout from '@/Layouts/AppLayout.vue';
import FormValidationBanner from '@/Components/FormValidationBanner.vue';
import { useFieldErrors } from '@/Composables/useFieldErrors';
import { useToast } from '@/Composables/useToast';

const props = defineProps<{
    isEditing: boolean;
    vehicle: null | {
        id: number;
        name: string;
        make: string | null;
        model: string | null;
        year: number | null;
        registration_number: string | null;
        vin: string | null;
        license_disk_expires_on: string | null;
        starting_odometer_km: number | null;
        notes: string | null;
        is_active: boolean;
    };
}>();

const toast = useToast();
const saving = ref(false);
const { fieldErrors, setFromZod, setFromServer, clear, clearField, messages: clientErrorMessages } = useFieldErrors();

const emptyToNull = (value: unknown) =>
    value === '' || value === null || value === undefined || (typeof value === 'number' && Number.isNaN(value))
        ? null
        : value;

const optionalInt = (min: number, max: number, message: string) =>
    z.preprocess(
        emptyToNull,
        z.number({ invalid_type_error: message }).int().min(min, message).max(max, message).nullable(),
    );

const optionalNonNegative = (message: string) =>
    z.preprocess(
        emptyToNull,
        z.number({ invalid_type_error: message }).min(0, message).nullable(),
    );

const { values, setFieldValue: setVeeFieldValue } = useForm({
    initialValues: {
        name: props.vehicle?.name ?? '',
        make: props.vehicle?.make ?? '',
        model: props.vehicle?.model ?? '',
        year: props.vehicle?.year ?? (null as number | null),
        registration_number: props.vehicle?.registration_number ?? '',
        vin: props.vehicle?.vin ?? '',
        license_disk_expires_on: props.vehicle?.license_disk_expires_on ?? '',
        starting_odometer_km: props.vehicle?.starting_odometer_km ?? (null as number | null),
        notes: props.vehicle?.notes ?? '',
        is_active: props.vehicle?.is_active ?? true,
    },
});

const setFieldValue = (path: string, value: unknown) => {
    setVeeFieldValue(path, value);
    clearField(path);
};

const formValues = computed<Record<string, any>>(() => ((values as any)?.value ?? values) as Record<string, any>);

const schema = z.object({
    name: z.string().trim().min(1, 'Name is required'),
    make: z.string().optional(),
    model: z.string().optional(),
    year: optionalInt(1900, 2100, 'Enter a year between 1900 and 2100'),
    registration_number: z.string().trim().min(1, 'Registration is required'),
    vin: z.string().optional(),
    license_disk_expires_on: z.preprocess(
        emptyToNull,
        z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Enter a valid expiry date').nullable(),
    ),
    starting_odometer_km: optionalNonNegative('Starting odometer cannot be negative'),
    notes: z.string().optional(),
    is_active: z.boolean(),
});

const submit = () => {
    if (saving.value) return;

    const parsed = schema.safeParse({
        ...formValues.value,
        year: emptyToNull(formValues.value.year),
        license_disk_expires_on: emptyToNull(formValues.value.license_disk_expires_on),
        starting_odometer_km: emptyToNull(formValues.value.starting_odometer_km),
    });

    if (!parsed.success) {
        setFromZod(parsed.error);
        return;
    }

    clear();

    const payload = {
        name: parsed.data.name,
        make: parsed.data.make?.trim() || null,
        model: parsed.data.model?.trim() || null,
        year: parsed.data.year,
        registration_number: parsed.data.registration_number,
        vin: parsed.data.vin?.trim() || null,
        license_disk_expires_on: parsed.data.license_disk_expires_on,
        starting_odometer_km: parsed.data.starting_odometer_km,
        notes: parsed.data.notes?.trim() || null,
        is_active: parsed.data.is_active,
    };

    const visitOptions = {
        onStart: () => {
            saving.value = true;
        },
        onSuccess: () => {
            toast.success(props.isEditing ? 'Vehicle updated.' : 'Vehicle created.');
        },
        onError: (errors: Record<string, string>) => {
            setFromServer(errors);
            if (!Object.keys(errors).length) {
                toast.error('Could not save this vehicle.');
            }
        },
        onFinish: () => {
            saving.value = false;
        },
    };

    if (props.isEditing && props.vehicle) {
        router.put(route('vehicles.update', props.vehicle.id), payload, visitOptions);
        return;
    }

    router.post(route('vehicles.store'), payload, visitOptions);
};
</script>

<template>
    <AppLayout
        :title="isEditing ? 'Edit vehicle' : 'New vehicle'"
        :breadcrumbs="[
            { label: 'Travel', href: route('vehicles.trips.index') },
            { label: 'Vehicles', href: route('vehicles.index') },
            { label: isEditing ? 'Edit' : 'Create' },
        ]"
    >
        <PageHeader
            :title="isEditing ? 'Edit vehicle' : 'New vehicle'"
            subtitle="Basic details for a vehicle used in your business"
        />

        <FormValidationBanner class="mt-5" :errors="clientErrorMessages" />

        <AppCard class="mt-5">
            <form @submit.prevent="submit">
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">
                            Name <span class="text-rose-600">*</span>
                        </label>
                        <AppInput
                            :model-value="formValues.name"
                            placeholder="e.g. Work bakkie"
                            required
                            @update:model-value="setFieldValue('name', $event)"
                        />
                        <p v-if="fieldErrors.name" class="mt-1 text-xs text-rose-600">{{ fieldErrors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">
                            Registration <span class="text-rose-600">*</span>
                        </label>
                        <AppInput
                            :model-value="formValues.registration_number"
                            placeholder="e.g. ABC 123 GP"
                            required
                            @update:model-value="setFieldValue('registration_number', $event)"
                        />
                        <p v-if="fieldErrors.registration_number" class="mt-1 text-xs text-rose-600">
                            {{ fieldErrors.registration_number }}
                        </p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">VIN</label>
                        <AppInput
                            :model-value="formValues.vin"
                            placeholder="Optional"
                            @update:model-value="setFieldValue('vin', $event)"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Licence disc expiry</label>
                        <AppInput
                            :model-value="formValues.license_disk_expires_on"
                            type="date"
                            @update:model-value="setFieldValue('license_disk_expires_on', $event)"
                        />
                        <p class="mt-1 text-xs text-slate-500">
                            nrth emails a reminder about one month before this date.
                        </p>
                        <p v-if="fieldErrors.license_disk_expires_on" class="mt-1 text-xs text-rose-600">
                            {{ fieldErrors.license_disk_expires_on }}
                        </p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Status</label>
                        <AppSelect
                            :model-value="formValues.is_active ? 'active' : 'inactive'"
                            :options="[
                                { label: 'Active', value: 'active' },
                                { label: 'Inactive', value: 'inactive' },
                            ]"
                            @update:model-value="setFieldValue('is_active', $event === 'active')"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Make</label>
                        <AppInput
                            :model-value="formValues.make"
                            placeholder="e.g. Toyota"
                            @update:model-value="setFieldValue('make', $event)"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Model</label>
                        <AppInput
                            :model-value="formValues.model"
                            placeholder="e.g. Hilux"
                            @update:model-value="setFieldValue('model', $event)"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Year</label>
                        <AppInput
                            :model-value="formValues.year == null ? '' : String(formValues.year)"
                            type="number"
                            min="1900"
                            max="2100"
                            placeholder="Optional"
                            @update:model-value="
                                setFieldValue('year', $event === '' ? null : Number($event))
                            "
                        />
                        <p v-if="fieldErrors.year" class="mt-1 text-xs text-rose-600">{{ fieldErrors.year }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-500">Starting odometer (km)</label>
                        <AppInput
                            :model-value="
                                formValues.starting_odometer_km == null
                                    ? ''
                                    : String(formValues.starting_odometer_km)
                            "
                            type="number"
                            step="0.1"
                            min="0"
                            placeholder="At purchase"
                            @update:model-value="
                                setFieldValue('starting_odometer_km', $event === '' ? null : Number($event))
                            "
                        />
                        <p class="mt-1 text-xs text-slate-500">Odometer reading when the vehicle was purchased.</p>
                        <p v-if="fieldErrors.starting_odometer_km" class="mt-1 text-xs text-rose-600">
                            {{ fieldErrors.starting_odometer_km }}
                        </p>
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-slate-500">Notes</label>
                        <textarea
                            :value="formValues.notes"
                            rows="3"
                            class="min-h-20 w-full rounded-md border border-slate-300 px-3 py-2 text-sm"
                            @input="setFieldValue('notes', ($event.target as HTMLTextAreaElement).value)"
                        />
                    </div>
                </div>

                <FormActions bordered>
                    <AppButton type="submit" variant="primary" :loading="saving" :disabled="saving">
                        {{ saving ? 'Saving…' : isEditing ? 'Update vehicle' : 'Create vehicle' }}
                    </AppButton>
                    <AppButton
                        type="button"
                        variant="secondary"
                        @click="
                            router.visit(
                                isEditing && vehicle
                                    ? route('vehicles.show', vehicle.id)
                                    : route('vehicles.index'),
                            )
                        "
                    >
                        Cancel
                    </AppButton>
                </FormActions>
            </form>
        </AppCard>
    </AppLayout>
</template>
