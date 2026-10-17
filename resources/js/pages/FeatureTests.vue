<script setup lang="ts">
import { Head, useHttp } from '@inertiajs/vue3';
import { useIntervalFn } from '@vueuse/core';
import { onUnmounted, ref } from 'vue';
import {
    destroyRecord,
    dispatchJob,
    jobStatus,
    schedulerHeartbeat,
    sendMail,
    storage,
    storeRecord,
    updateRecord,
} from '@/actions/App/Http/Controllers/FeatureTestController';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type TestStatus = 'idle' | 'running' | 'passed' | 'failed';

type TestRecord = {
    id: number;
    title: string;
    body: string | null;
    created_at: string;
    updated_at: string;
};

type SchedulerStatus = {
    last_run_at: string | null;
    healthy: boolean;
};

const props = defineProps<{
    environment: Record<string, string>;
    disks: string[];
    records: TestRecord[];
    scheduler: SchedulerStatus;
}>();

const statusLabels: Record<TestStatus, string> = {
    idle: 'Not run',
    running: 'Running',
    passed: 'Passed',
    failed: 'Failed',
};

function statusVariant(
    status: TestStatus,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'passed':
            return 'default';
        case 'failed':
            return 'destructive';
        case 'running':
            return 'secondary';
        default:
            return 'outline';
    }
}

function formatTime(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}

function errorMessage(data: unknown, fallback: string): string {
    if (data && typeof data === 'object' && 'error' in data) {
        return String((data as { error: unknown }).error);
    }

    if (data && typeof data === 'object' && 'message' in data) {
        return String((data as { message: unknown }).message);
    }

    return fallback;
}

/*
 * Database CRUD
 */
const records = ref<TestRecord[]>(props.records);
const databaseStatus = ref<TestStatus>('idle');
const databaseMessage = ref('');
const editingRecordId = ref<number | null>(null);

const createForm = useHttp({ title: '', body: '' });
const editForm = useHttp({ title: '', body: '' });
const deleteRequest = useHttp({});

function handleRecordsResponse(
    data: { records: TestRecord[] },
    message: string,
): void {
    records.value = data.records;
    databaseStatus.value = 'passed';
    databaseMessage.value = message;
}

function handleDatabaseFailure(response: { data: unknown }): void {
    databaseStatus.value = 'failed';
    databaseMessage.value = errorMessage(response.data, 'Database request failed.');
}

function createRecord(): void {
    databaseStatus.value = 'running';

    createForm.post(storeRecord.url(), {
        onSuccess: (data) => {
            handleRecordsResponse(
                data as { records: TestRecord[] },
                'Record created.',
            );
            createForm.reset();
        },
        onError: () => {
            databaseStatus.value = 'failed';
            databaseMessage.value = 'Validation failed.';
        },
        onHttpException: handleDatabaseFailure,
    });
}

function startEditing(record: TestRecord): void {
    editingRecordId.value = record.id;
    editForm.title = record.title;
    editForm.body = record.body ?? '';
    editForm.clearErrors();
}

function saveRecord(record: TestRecord): void {
    databaseStatus.value = 'running';

    editForm.put(updateRecord.url(record.id), {
        onSuccess: (data) => {
            handleRecordsResponse(
                data as { records: TestRecord[] },
                `Record #${record.id} updated.`,
            );
            editingRecordId.value = null;
        },
        onError: () => {
            databaseStatus.value = 'failed';
            databaseMessage.value = 'Validation failed.';
        },
        onHttpException: handleDatabaseFailure,
    });
}

function deleteRecord(record: TestRecord): void {
    databaseStatus.value = 'running';

    deleteRequest.delete(destroyRecord.url(record.id), {
        onSuccess: (data) =>
            handleRecordsResponse(
                data as { records: TestRecord[] },
                `Record #${record.id} deleted.`,
            ),
        onHttpException: handleDatabaseFailure,
    });
}

/*
 * Object storage
 */
type StorageResult = {
    success: boolean;
    disk: string;
    path?: string;
    checks?: Record<string, boolean>;
    error?: string;
};

const storageForm = useHttp({
    disk: props.disks.includes('s3') ? 's3' : props.environment.filesystem,
});
const storageStatus = ref<TestStatus>('idle');
const storageResult = ref<StorageResult | null>(null);

function runStorageTest(): void {
    storageStatus.value = 'running';
    storageResult.value = null;

    storageForm.post(storage.url(), {
        onSuccess: (data) => {
            storageResult.value = data as StorageResult;
            storageStatus.value = storageResult.value.success
                ? 'passed'
                : 'failed';
        },
        onError: () => {
            storageStatus.value = 'failed';
        },
        onHttpException: (response) => {
            storageResult.value = response.data as unknown as StorageResult;
            storageStatus.value = 'failed';
        },
    });
}

/*
 * Queue
 */
type QueueResult = {
    token: string;
    connection?: string;
    dispatched_at?: string;
    processed_at: string | null;
};

const queueDispatch = useHttp({});
const queuePoll = useHttp({});
const queueStatus = ref<TestStatus>('idle');
const queueResult = ref<QueueResult | null>(null);
const queueMessage = ref('');
let queuePollTimer: ReturnType<typeof setInterval> | null = null;
const queuePollLimit = 30;

function stopQueuePolling(): void {
    if (queuePollTimer) {
        clearInterval(queuePollTimer);
        queuePollTimer = null;
    }
}

function runQueueTest(): void {
    stopQueuePolling();
    queueStatus.value = 'running';
    queueResult.value = null;
    queueMessage.value = 'Dispatching job…';

    queueDispatch.post(dispatchJob.url(), {
        onSuccess: (data) => {
            const result = data as QueueResult;
            queueResult.value = result;

            if (result.processed_at) {
                queueStatus.value = 'passed';
                queueMessage.value = 'Job processed immediately (sync driver).';

                return;
            }

            queueMessage.value = 'Waiting for a worker to process the job…';
            pollQueueStatus(result.token);
        },
        onHttpException: (response) => {
            queueStatus.value = 'failed';
            queueMessage.value = errorMessage(response.data, 'Dispatch failed.');
        },
    });
}

function pollQueueStatus(token: string): void {
    let attempts = 0;

    queuePollTimer = setInterval(() => {
        attempts++;

        if (attempts > queuePollLimit) {
            stopQueuePolling();
            queueStatus.value = 'failed';
            queueMessage.value = `Job not processed after ${queuePollLimit * 2}s. Is a queue worker running?`;

            return;
        }

        queuePoll.get(jobStatus.url(token), {
            onSuccess: (data) => {
                const result = data as QueueResult;

                if (result.processed_at && queueResult.value) {
                    stopQueuePolling();
                    queueResult.value.processed_at = result.processed_at;
                    queueStatus.value = 'passed';
                    queueMessage.value = 'Job processed by a queue worker.';
                }
            },
        });
    }, 2000);
}

onUnmounted(stopQueuePolling);

/*
 * Scheduler
 */
const scheduler = ref<SchedulerStatus>(props.scheduler);
const schedulerRequest = useHttp({});

function refreshScheduler(): void {
    schedulerRequest.get(schedulerHeartbeat.url(), {
        onSuccess: (data) => {
            scheduler.value = data as SchedulerStatus;
        },
    });
}

useIntervalFn(refreshScheduler, 15000);

/*
 * Email
 */
const mailForm = useHttp({ email: '', queued: false });
const mailStatus = ref<TestStatus>('idle');
const mailMessage = ref('');

function runMailTest(): void {
    mailStatus.value = 'running';
    mailMessage.value = '';

    mailForm.post(sendMail.url(), {
        onSuccess: (data) => {
            const result = data as { email: string; mailer: string; queued: boolean };
            mailStatus.value = 'passed';
            mailMessage.value = result.queued
                ? `Email queued for ${result.email} via "${result.mailer}". A worker must process it.`
                : `Email sent to ${result.email} via "${result.mailer}".`;
        },
        onError: () => {
            mailStatus.value = 'failed';
            mailMessage.value = 'Validation failed.';
        },
        onHttpException: (response) => {
            mailStatus.value = 'failed';
            mailMessage.value =
                response.status === 429
                    ? 'Too many emails. Try again in a minute.'
                    : errorMessage(response.data, 'Sending failed.');
        },
    });
}
</script>

<template>
    <Head title="Feature Tests" />

    <div class="min-h-screen bg-background px-4 py-10 text-foreground">
        <div class="mx-auto flex max-w-5xl flex-col gap-6">
            <header class="flex flex-col gap-3">
                <h1 class="text-2xl font-semibold">Laravel Feature Tests</h1>
                <p class="text-sm text-muted-foreground">
                    Check that the database, object storage, scheduler, queue
                    and mail are wired up in this environment.
                </p>
                <div class="flex flex-wrap gap-2">
                    <Badge
                        v-for="(value, key) in environment"
                        :key="key"
                        variant="outline"
                    >
                        <span class="text-muted-foreground">{{ key }}:</span>
                        {{ value }}
                    </Badge>
                </div>
            </header>

            <!-- Database CRUD -->
            <Card>
                <CardHeader>
                    <div class="flex items-center justify-between gap-2">
                        <CardTitle>Database CRUD</CardTitle>
                        <Badge :variant="statusVariant(databaseStatus)">
                            {{ statusLabels[databaseStatus] }}
                        </Badge>
                    </div>
                    <CardDescription>
                        Create, read, update and delete rows in the
                        <code>test_records</code> table on the
                        <strong>{{ environment.database }}</strong> connection.
                    </CardDescription>
                </CardHeader>
                <CardContent class="flex flex-col gap-4">
                    <form
                        class="grid gap-3 sm:grid-cols-[1fr_2fr_auto] sm:items-start"
                        @submit.prevent="createRecord"
                    >
                        <div class="grid gap-1">
                            <Label for="record-title">Title</Label>
                            <Input
                                id="record-title"
                                v-model="createForm.title"
                                placeholder="Record title"
                            />
                            <InputError :message="createForm.errors.title" />
                        </div>
                        <div class="grid gap-1">
                            <Label for="record-body">Body</Label>
                            <Input
                                id="record-body"
                                v-model="createForm.body"
                                placeholder="Optional body"
                            />
                            <InputError :message="createForm.errors.body" />
                        </div>
                        <Button
                            type="submit"
                            class="sm:mt-5"
                            :disabled="createForm.processing"
                        >
                            Create
                        </Button>
                    </form>

                    <p
                        v-if="databaseMessage"
                        class="text-sm text-muted-foreground"
                    >
                        {{ databaseMessage }}
                    </p>

                    <div class="overflow-x-auto rounded-md border">
                        <table class="w-full text-sm">
                            <thead class="bg-muted/50 text-left">
                                <tr>
                                    <th class="px-3 py-2 font-medium">ID</th>
                                    <th class="px-3 py-2 font-medium">Title</th>
                                    <th class="px-3 py-2 font-medium">Body</th>
                                    <th class="px-3 py-2 font-medium">
                                        Updated
                                    </th>
                                    <th class="px-3 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="records.length === 0">
                                    <td
                                        colspan="5"
                                        class="px-3 py-6 text-center text-muted-foreground"
                                    >
                                        No records yet. Create one above.
                                    </td>
                                </tr>
                                <tr
                                    v-for="record in records"
                                    :key="record.id"
                                    class="border-t align-top"
                                >
                                    <td class="px-3 py-2">#{{ record.id }}</td>
                                    <template
                                        v-if="editingRecordId === record.id"
                                    >
                                        <td class="px-3 py-2">
                                            <Input v-model="editForm.title" />
                                            <InputError
                                                :message="editForm.errors.title"
                                            />
                                        </td>
                                        <td class="px-3 py-2">
                                            <Input v-model="editForm.body" />
                                            <InputError
                                                :message="editForm.errors.body"
                                            />
                                        </td>
                                    </template>
                                    <template v-else>
                                        <td class="px-3 py-2">
                                            {{ record.title }}
                                        </td>
                                        <td
                                            class="px-3 py-2 text-muted-foreground"
                                        >
                                            {{ record.body || '—' }}
                                        </td>
                                    </template>
                                    <td
                                        class="px-3 py-2 whitespace-nowrap text-muted-foreground"
                                    >
                                        {{ formatTime(record.updated_at) }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <div class="flex justify-end gap-2">
                                            <template
                                                v-if="
                                                    editingRecordId ===
                                                    record.id
                                                "
                                            >
                                                <Button
                                                    size="sm"
                                                    :disabled="
                                                        editForm.processing
                                                    "
                                                    @click="saveRecord(record)"
                                                >
                                                    Save
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    @click="
                                                        editingRecordId = null
                                                    "
                                                >
                                                    Cancel
                                                </Button>
                                            </template>
                                            <template v-else>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    @click="
                                                        startEditing(record)
                                                    "
                                                >
                                                    Edit
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="destructive"
                                                    :disabled="
                                                        deleteRequest.processing
                                                    "
                                                    @click="
                                                        deleteRecord(record)
                                                    "
                                                >
                                                    Delete
                                                </Button>
                                            </template>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>

            <div class="grid gap-6 md:grid-cols-2">
                <!-- Object storage -->
                <Card>
                    <CardHeader>
                        <div class="flex items-center justify-between gap-2">
                            <CardTitle>Object Storage</CardTitle>
                            <Badge :variant="statusVariant(storageStatus)">
                                {{ statusLabels[storageStatus] }}
                            </Badge>
                        </div>
                        <CardDescription>
                            Uploads a temporary file, checks it exists, reads
                            it back and deletes it.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <form
                            class="flex items-end gap-3"
                            @submit.prevent="runStorageTest"
                        >
                            <div class="grid flex-1 gap-1">
                                <Label for="storage-disk">Disk</Label>
                                <select
                                    id="storage-disk"
                                    v-model="storageForm.disk"
                                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                >
                                    <option
                                        v-for="disk in disks"
                                        :key="disk"
                                        :value="disk"
                                    >
                                        {{ disk }}
                                    </option>
                                </select>
                            </div>
                            <Button
                                type="submit"
                                :disabled="storageForm.processing"
                            >
                                Run test
                            </Button>
                        </form>
                        <InputError :message="storageForm.errors.disk" />

                        <div v-if="storageResult" class="grid gap-2 text-sm">
                            <div
                                v-if="storageResult.checks"
                                class="flex flex-wrap gap-2"
                            >
                                <Badge
                                    v-for="(passed, check) in storageResult.checks"
                                    :key="check"
                                    :variant="
                                        passed ? 'secondary' : 'destructive'
                                    "
                                >
                                    {{ passed ? '✓' : '✗' }} {{ check }}
                                </Badge>
                            </div>
                            <p
                                v-if="storageResult.path"
                                class="break-all text-muted-foreground"
                            >
                                {{ storageResult.disk }}:{{ storageResult.path }}
                            </p>
                            <p
                                v-if="storageResult.error"
                                class="break-all text-red-600 dark:text-red-500"
                            >
                                {{ storageResult.error }}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Queue -->
                <Card>
                    <CardHeader>
                        <div class="flex items-center justify-between gap-2">
                            <CardTitle>Queue</CardTitle>
                            <Badge :variant="statusVariant(queueStatus)">
                                {{ statusLabels[queueStatus] }}
                            </Badge>
                        </div>
                        <CardDescription>
                            Dispatches a job on the
                            <strong>{{ environment.queue }}</strong>
                            connection and waits for a worker to process it.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <Button
                            class="self-start"
                            :disabled="queueStatus === 'running'"
                            @click="runQueueTest"
                        >
                            Dispatch job
                        </Button>
                        <p
                            v-if="queueMessage"
                            class="text-sm text-muted-foreground"
                        >
                            {{ queueMessage }}
                        </p>
                        <dl
                            v-if="queueResult"
                            class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm"
                        >
                            <dt class="text-muted-foreground">Token</dt>
                            <dd class="break-all">{{ queueResult.token }}</dd>
                            <dt class="text-muted-foreground">Dispatched</dt>
                            <dd>
                                {{ formatTime(queueResult.dispatched_at ?? null) }}
                            </dd>
                            <dt class="text-muted-foreground">Processed</dt>
                            <dd>{{ formatTime(queueResult.processed_at) }}</dd>
                        </dl>
                        <p class="text-xs text-muted-foreground">
                            Requires <code>php artisan queue:work</code> unless
                            the connection is <code>sync</code>.
                        </p>
                    </CardContent>
                </Card>

                <!-- Scheduler -->
                <Card>
                    <CardHeader>
                        <div class="flex items-center justify-between gap-2">
                            <CardTitle>Scheduler</CardTitle>
                            <Badge
                                :variant="
                                    scheduler.healthy
                                        ? 'default'
                                        : scheduler.last_run_at
                                          ? 'destructive'
                                          : 'outline'
                                "
                            >
                                {{
                                    scheduler.healthy
                                        ? 'Running'
                                        : scheduler.last_run_at
                                          ? 'Stale'
                                          : 'Never run'
                                }}
                            </Badge>
                        </div>
                        <CardDescription>
                            The <code>test-scheduler</code> task runs every
                            minute and records a heartbeat. It is healthy if it
                            ran in the last 2 minutes.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <dl
                            class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm"
                        >
                            <dt class="text-muted-foreground">Last run</dt>
                            <dd>{{ formatTime(scheduler.last_run_at) }}</dd>
                        </dl>
                        <Button
                            variant="outline"
                            class="self-start"
                            :disabled="schedulerRequest.processing"
                            @click="refreshScheduler"
                        >
                            Refresh
                        </Button>
                        <p class="text-xs text-muted-foreground">
                            Requires <code>php artisan schedule:work</code> or a
                            cron entry running <code>schedule:run</code>.
                            Auto-refreshes every 15s.
                        </p>
                    </CardContent>
                </Card>

                <!-- Email -->
                <Card>
                    <CardHeader>
                        <div class="flex items-center justify-between gap-2">
                            <CardTitle>Email</CardTitle>
                            <Badge :variant="statusVariant(mailStatus)">
                                {{ statusLabels[mailStatus] }}
                            </Badge>
                        </div>
                        <CardDescription>
                            Sends a test email using the
                            <strong>{{ environment.mailer }}</strong> mailer.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <form
                            class="flex flex-col gap-3"
                            @submit.prevent="runMailTest"
                        >
                            <div class="grid gap-1">
                                <Label for="mail-email">Recipient</Label>
                                <Input
                                    id="mail-email"
                                    v-model="mailForm.email"
                                    type="email"
                                    placeholder="you@example.com"
                                />
                                <InputError :message="mailForm.errors.email" />
                            </div>
                            <label class="flex items-center gap-2 text-sm">
                                <input
                                    v-model="mailForm.queued"
                                    type="checkbox"
                                    class="size-4"
                                />
                                Send via queue
                            </label>
                            <Button
                                type="submit"
                                class="self-start"
                                :disabled="mailForm.processing"
                            >
                                Send email
                            </Button>
                        </form>
                        <p
                            v-if="mailMessage"
                            class="text-sm"
                            :class="
                                mailStatus === 'failed'
                                    ? 'text-red-600 dark:text-red-500'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{ mailMessage }}
                        </p>
                    </CardContent>
                </Card>
            </div>
        </div>
    </div>
</template>
