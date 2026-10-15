<script setup>
import { Head, Link } from '@statamic/cms/inertia';
import { toast } from '@statamic/cms/api';
import { Alert, Badge, Button, Header, Listing } from '@statamic/cms/ui';
import { ref } from 'vue';
import When from '../components/When.vue';
import { useRequests } from '../util.js';

const props = defineProps({
    listingUrl: { type: String, required: true },
    actionUrl: { type: String, required: true },
    createUrl: { type: String, required: true },
    exportUrl: { type: String, required: true },
    importUrl: { type: String, required: true },
    // Rules still read from the database, and rule files that can't be read or need a look.
    notices: { type: Array, default: () => [] },
});

const { busy, send } = useRequests(__('marketing-toolkit::cp.redirects.import_failed'));
const file = ref(null);
const listing = ref(null);

async function importCsv(event) {
    const chosen = event.target.files[0];
    event.target.value = '';
    if (!chosen) return;

    const form = new FormData();
    form.append('file', chosen);
    const data = await send('import', (axios) => axios.post(props.importUrl, form));
    if (!data) return;

    const summary = __('marketing-toolkit::cp.redirects.imported', { created: data.created, updated: data.updated });

    data.errors.length
        ? toast.error(__('marketing-toolkit::cp.redirects.skipped', { summary, count: data.errors.length, errors: data.errors.join(' ') }), { duration: 15000 })
        : toast.success(summary);
    listing.value?.refresh();
}
</script>

<template>
    <Head :title="__('marketing-toolkit::cp.redirects.title')" />

    <Header :title="__('marketing-toolkit::cp.redirects.title')" icon="moved">
        <input ref="file" type="file" accept=".csv,text/csv" class="hidden" @change="importCsv" />
        <Button :text="__('marketing-toolkit::cp.redirects.import')" :loading="busy === 'import'" @click="file.click()" />
        <Button :text="__('marketing-toolkit::cp.redirects.export')" :href="exportUrl" />
        <Button :text="__('marketing-toolkit::cp.redirects.create')" variant="primary" :href="createUrl" />
    </Header>

    <p class="mb-4 text-sm text-gray-600 dark:text-gray-400">{{ __('marketing-toolkit::cp.redirects.intro') }}</p>

    <div v-if="notices.length" class="mb-4 space-y-2">
        <Alert v-for="notice in notices" :key="notice.message" :variant="notice.level === 'error' ? 'error' : 'warning'" :text="notice.message" :live="false" />
    </div>

    <Listing
        ref="listing"
        :url="listingUrl"
        :action-url="actionUrl"
        :action-context="{ type: 'redirects' }"
        sort-column="source"
        sort-direction="asc"
        preferences-prefix="mt.redirects"
        :allow-presets="false"
    >
        <template #cell-source="{ row }">
            <Link v-if="row.edit_url" :href="row.edit_url" class="font-mono text-sm">{{ row.source }}</Link>
            <span v-else class="font-mono text-sm">{{ row.source }}</span>
            <Badge v-if="row.automatic" size="sm" class="ms-2" :text="__('marketing-toolkit::cp.redirects.automatic')" />
        </template>
        <template #cell-target="{ row }">
            <span class="font-mono text-sm">{{ row.target ?? '—' }}</span>
        </template>
        <template #cell-active="{ row }">
            <span :class="row.active ? 'text-(--theme-color-success)' : 'text-gray-500 dark:text-gray-400'">{{ row.active ? __('marketing-toolkit::cp.redirects.yes') : __('marketing-toolkit::cp.redirects.no') }}</span>
        </template>
        <template #cell-last_hit_at="{ row }">
            <When :value="row.last_hit_at" />
        </template>
    </Listing>
</template>
