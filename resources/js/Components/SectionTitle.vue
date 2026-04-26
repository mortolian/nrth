<template>
    <div class="flex justify-between gap-4 md:col-span-1">
        <div class="min-w-0 flex-1">
            <h3 class="text-base font-semibold text-slate-900">
                <slot name="title" />
            </h3>

            <p class="mt-1 text-sm text-slate-500">
                <slot name="description" />
            </p>
        </div>

        <div class="shrink-0">
            <slot name="aside" />
        </div>
    </div>
</template>
