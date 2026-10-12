<script setup>
import { dateFormatter, numberFormatter } from '@statamic/cms/api';
import { Head } from '@statamic/cms/inertia';
import { Alert, Button, Card, Description, Header, Heading, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows } from '@statamic/cms/ui';
import Gauge from '../components/Gauge.vue';
import RecentNotFound from '../components/RecentNotFound.vue';
import When from '../components/When.vue';

// This joins tool names as "A and B", "A, B, and C", or however the control panel's language lists them.
const toolList = (tools) => new Intl.ListFormat(numberFormatter.locale, { type: 'conjunction' }).format(tools);
// This formats a day (2026-09-01) as the control panel shows dates. It reads the day as a local date,
// so it isn't a day out.
const formatDay = (value) => (value ? dateFormatter.format(new Date(`${value}T00:00:00`), { dateStyle: 'medium' }) : '');

defineProps({
    siteName: { type: String, required: true },
    global: { type: Object, required: true },
    report: { type: Object, default: null },
    redirects: { type: Object, default: null },
    notFound: { type: Object, default: null },
    search: { type: Object, default: null },
    searchConsole: { type: Object, default: null },
    files: { type: Array, required: true },
    cardsUnavailable: { type: Boolean, default: false },
    tracking: { type: Object, required: true },
});
</script>

<template>
    <Head :title="__('marketing-toolkit::cp.seo')" />

    <div class="max-w-page mx-auto space-y-6">
        <Header :title="__('marketing-toolkit::cp.seo')" icon="search-magnifying-glass" />

        <Card v-if="tracking.overlap.length" class="flex flex-wrap items-center justify-between gap-3 border-amber-400 p-4">
            <div class="space-y-1">
                <Heading size="lg">{{ __('marketing-toolkit::cp.tracking.overlap_title') }}</Heading>
                <Description>{{ __n('marketing-toolkit::cp.tracking.overlap', tracking.overlap.length, { tools: toolList(tracking.overlap) }) }}</Description>
            </div>
            <Button v-if="tracking.url" :href="tracking.url" :text="__('marketing-toolkit::cp.tracking.edit')" />
        </Card>

        <div class="grid gap-6 md:grid-cols-2">
            <Card v-if="report" class="flex flex-col gap-3 p-4">
                <Heading size="lg">{{ __('marketing-toolkit::cp.overview.report.title') }}</Heading>
                <div v-if="report.latest" class="flex flex-wrap items-center gap-6">
                    <Gauge :value="report.latest.score" :label="__('marketing-toolkit::cp.gauge.label')" />
                    <div class="min-w-48 flex-1 space-y-2">
                        <Description>{{ __n('marketing-toolkit::cp.overview.report.pages', report.latest.pages, { count: report.latest.pages }) }} <When :value="report.latest.finished_at" /></Description>
                        <ul v-if="report.latest.checks.length" class="space-y-1 text-sm">
                            <li v-for="check in report.latest.checks" :key="check.label" class="flex justify-between gap-4 border-b border-dotted border-gray-300 pb-1 dark:border-gray-700">
                                <span class="truncate">{{ check.label }}</span>
                                <span class="shrink-0 tabular-nums font-semibold text-(--theme-color-danger)">{{ check.fail }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
                <Description v-else>{{ __('marketing-toolkit::cp.overview.report.none') }}</Description>
                <div class="mt-auto flex flex-wrap gap-2">
                    <Button v-if="report.latest" :href="report.latest.url" :text="__('marketing-toolkit::cp.overview.report.open_latest')" />
                    <Button :href="report.url" :text="__('marketing-toolkit::cp.overview.report.all')" />
                    <Button v-if="report.settings_url" :href="report.settings_url" :text="__('marketing-toolkit::cp.overview.report.settings')" variant="ghost" />
                </div>
            </Card>

            <Card v-if="notFound" class="flex flex-col gap-3 p-4">
                <Heading size="lg">{{ __('marketing-toolkit::cp.overview.not_found.title') }}</Heading>
                <Description v-if="!notFound.paths">{{ __('marketing-toolkit::cp.overview.not_found.none') }}</Description>
                <template v-else>
                    <Description>{{ __n('marketing-toolkit::cp.overview.not_found.count', notFound.paths, { count: notFound.paths }) }}</Description>
                    <RecentNotFound :rows="notFound.recent" />
                </template>
                <div class="mt-auto"><Button :href="notFound.url" :text="__('marketing-toolkit::cp.overview.not_found.all')" /></div>
            </Card>

            <Card v-if="redirects" class="flex flex-col gap-3 p-4">
                <Heading size="lg">{{ __('marketing-toolkit::cp.overview.redirects.title') }}</Heading>
                <Description>
                    {{ __n('marketing-toolkit::cp.overview.redirects.active', redirects.active, { count: redirects.active }) }}
                    <template v-if="redirects.automatic">{{ __n('marketing-toolkit::cp.overview.redirects.automatic', redirects.automatic, { count: redirects.automatic }) }}</template>
                    {{ __('marketing-toolkit::cp.overview.redirects.only_404') }}
                </Description>
                <div class="mt-auto"><Button :href="redirects.url" :text="__('marketing-toolkit::cp.overview.redirects.manage')" /></div>
            </Card>

            <Card class="flex flex-col gap-3 p-4">
                <Heading size="lg">{{ __('marketing-toolkit::cp.overview.brand.title') }}</Heading>
                <Description v-if="!global.exists">{{ __('marketing-toolkit::cp.overview.brand.missing', { command: 'php please mt:install' }) }}</Description>
                <template v-else>
                    <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('marketing-toolkit::cp.overview.brand.site_name') }}</dt>
                        <dd>{{ siteName }}</dd>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('marketing-toolkit::cp.overview.brand.titles') }}</dt>
                        <dd>{{ __('marketing-toolkit::cp.overview.brand.page_title') }}<template v-if="global.separator">{{ global.separator }}{{ global.titleName }}</template></dd>
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('marketing-toolkit::cp.overview.brand.default_description') }}</dt>
                        <dd>{{ global.description ?? __('marketing-toolkit::cp.overview.brand.no_description') }}</dd>
                    </dl>
                    <div class="mt-auto"><Button v-if="global.url" :href="global.url" :text="__('marketing-toolkit::cp.overview.brand.edit')" /></div>
                </template>
            </Card>
        </div>

        <Card v-if="search" class="space-y-3 p-4">
            <div class="flex items-center justify-between gap-3">
                <Heading size="lg">{{ __('marketing-toolkit::cp.overview.search.title') }}</Heading>
                <Button size="sm" variant="ghost" :href="searchConsole.url" :text="__('marketing-toolkit::cp.overview.search.manage')" />
            </div>
            <Description v-if="!search.fetched_at">{{ __('marketing-toolkit::cp.overview.search.not_imported') }}</Description>
            <template v-else>
                <Description>
                    {{ __('marketing-toolkit::cp.overview.search.summary', { clicks: numberFormatter.format(search.clicks), impressions: numberFormatter.format(search.impressions), from: formatDay(search.from), to: formatDay(search.to) }) }}
                    {{ __('marketing-toolkit::cp.overview.search.updated') }} <When :value="search.fetched_at" />.
                </Description>
                <Table v-if="search.top.length" class="overflow-x-auto">
                    <TableColumns>
                        <TableColumn>{{ __('marketing-toolkit::cp.overview.search.page') }}</TableColumn>
                        <TableColumn>{{ __('marketing-toolkit::cp.overview.search.clicks') }}</TableColumn>
                        <TableColumn>{{ __('marketing-toolkit::cp.overview.search.impressions') }}</TableColumn>
                        <TableColumn>{{ __('marketing-toolkit::cp.overview.search.position') }}</TableColumn>
                    </TableColumns>
                    <TableRows>
                        <TableRow v-for="(row, index) in search.top" :key="`${index}:${row.path}`">
                            <TableCell><div class="max-w-48 truncate font-mono text-xs sm:max-w-md" :title="row.path">{{ row.path }}</div></TableCell>
                            <TableCell class="tabular-nums">{{ numberFormatter.format(row.clicks) }}</TableCell>
                            <TableCell class="tabular-nums">{{ numberFormatter.format(row.impressions) }}</TableCell>
                            <TableCell class="tabular-nums">{{ numberFormatter.format(row.position, { maximumFractionDigits: 1 }) }}</TableCell>
                        </TableRow>
                    </TableRows>
                </Table>
            </template>
        </Card>

        <Card v-else-if="searchConsole" class="flex flex-wrap items-center justify-between gap-3 p-4">
            <div class="space-y-1">
                <Heading size="lg">{{ __('marketing-toolkit::cp.overview.search.title') }}</Heading>
                <Description>{{ __('marketing-toolkit::cp.overview.search.connect_body') }}</Description>
            </div>
            <Button :href="searchConsole.url" :text="__('marketing-toolkit::cp.overview.search.connect')" icon-append="arrow-right" />
        </Card>

        <Card class="space-y-2 p-4">
            <Heading size="lg">{{ __('marketing-toolkit::cp.tracking.title') }}</Heading>
            <Alert v-for="message in tracking.invalid" :key="message" variant="warning" :text="message" :live="false" />
            <Description v-if="!tracking.tools.length">{{ __('marketing-toolkit::cp.tracking.none') }}</Description>
            <template v-else>
                <ul class="space-y-1 text-sm">
                    <li v-for="tool in tracking.tools" :key="tool.name">
                        {{ tool.name }}: <span class="font-mono text-xs">{{ tool.id }}</span>
                        <span v-if="tool.from_env" class="text-xs text-gray-500 dark:text-gray-400">({{ __('marketing-toolkit::cp.tracking.from_env') }})</span>
                    </li>
                </ul>
                <Description v-if="tracking.off_here">{{ __('marketing-toolkit::cp.tracking.off_here') }}</Description>
                <Description v-else>{{ __('marketing-toolkit::cp.tracking.live_only') }} <template v-if="tracking.consent">{{ __('marketing-toolkit::cp.tracking.consent') }}</template></Description>
            </template>
            <Button v-if="tracking.url" size="sm" :href="tracking.url" :text="__('marketing-toolkit::cp.tracking.edit')" />
        </Card>

        <Card class="space-y-2 p-4">
            <Heading size="lg">{{ __('marketing-toolkit::cp.overview.files.title') }}</Heading>
            <ul class="space-y-1 text-sm">
                <li v-for="file in files" :key="file.label">
                    {{ file.label }}:
                    <a :href="file.url" target="_blank" rel="noopener" class="font-mono text-xs break-all underline">{{ file.url }}</a>
                    <span v-if="file.public" class="ml-1 text-xs text-amber-700 dark:text-amber-400">{{ __('marketing-toolkit::cp.overview.files.public') }}</span>
                </li>
            </ul>
            <p v-if="cardsUnavailable" role="status" class="text-sm text-amber-700 dark:text-amber-400">{{ __('marketing-toolkit::cp.overview.files.no_cards') }}</p>
        </Card>
    </div>
</template>
