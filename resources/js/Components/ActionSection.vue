<template>
    <section class="rounded-xl border border-slate-200 bg-slate-50/60 p-4 md:p-5">
        <h4 class="text-sm font-semibold text-slate-900">
            <slot name="title" />
        </h4>
        <p class="mt-0.5 text-xs text-slate-500">
            <slot name="description" />
        </p>

        <div class="mt-4">
            <slot name="content" />
        </div>
    </section>
</template>
