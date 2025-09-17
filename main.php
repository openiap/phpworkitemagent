#!/usr/bin/env php
<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(0);

// Minimal OpenIAP-like client shim for PHP to mirror the Node.js flow.
// Replace internals with real API calls if/when a PHP SDK is available.
class Client
{
    private array $eventHandlers = [];
    private bool $dummyWorkitemProvided = false;

    public function enable_tracing(string $level, string $tags = ''): void
    {
        $this->info("Tracing enabled: level={$level} tags={$tags}");
    }

    public function connect(): void
    {
        // Simulate immediate sign-in success
        $this->info('Connecting to OpenIAP...');
        $this->emitClientEvent(['event' => 'SignedIn']);
    }

    public function on_client_event(callable $handler): void
    {
        $this->eventHandlers[] = $handler;
    }

    public function register_queue(array $opts, callable $onMessage): string
    {
        $queue = $opts['queuename'] ?? 'default_queue';
        $this->info("Registered queue consumer for '{$queue}'");
        // In real implementation, this would subscribe and invoke $onMessage when messages arrive.
        return $queue;
    }

    public function pop_workitem(array $opts): ?array
    {
        // Placeholder: integrate with OpenIAP to pop a real workitem
        // For local smoke testing, provide a single dummy workitem when DUMMY_WORKITEM=1
        $wiq = $opts['wiq'] ?? 'default_queue';
        if (getenv('DUMMY_WORKITEM') === '1' && !$this->dummyWorkitemProvided) {
            $this->dummyWorkitemProvided = true;
            $this->info("Popping dummy workitem from {$wiq}");
            return [
                'id' => uniqid('wi_', true),
                'retries' => 0,
                'payload' => ['initial' => true],
                'state' => 'new',
                'name' => 'dummy'
            ];
        }
        return null;
    }

    public function update_workitem(array $opts): void
    {
        // Expecting shape: [ 'workitem' => array, 'files' => array<string>? ]
        $hasFiles = isset($opts['files']) && is_array($opts['files']) && count($opts['files']) > 0;
        $id = $opts['workitem']['id'] ?? '(unknown)';
        $state = $opts['workitem']['state'] ?? '(no state)';
        $this->info("Updated workitem {$id} with state '{$state}'" . ($hasFiles ? ' and files' : ''));
    }

    public function info(string $msg): void
    {
        fwrite(STDOUT, "[INFO] {$msg}\n");
    }

    public function error(string $msg): void
    {
        fwrite(STDERR, "[ERROR] {$msg}\n");
    }

    private function emitClientEvent(array $event): void
    {
        foreach ($this->eventHandlers as $handler) {
            try {
                $handler($event);
            } catch (\Throwable $e) {
                $this->error('Client event handler error: ' . $e->getMessage());
            }
        }
    }
}

// Utility: list files in current directory (files only)
function lstat_dir(string $dir): array
{
    $entries = @scandir($dir) ?: [];
    $files = [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        try {
            if (is_file($path)) {
                $files[] = $entry;
            }
        } catch (\Throwable $e) {
            // ignore
        }
    }
    return $files;
}

function cleanupFiles(array $originalFiles): void
{
    $dir = __DIR__;
    $currentFiles = lstat_dir($dir);
    $filesToDelete = array_values(array_diff($currentFiles, $originalFiles));
    foreach ($filesToDelete as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        // Only delete files we created in this run; in practice ensure safe patterns.
        try {
            @unlink($path);
        } catch (\Throwable $e) {
            // ignore
        }
    }
}

// Business logic equivalent to Node's ProcessWorkitem
function ProcessWorkitem(Client $client, array &$workitem): void
{
    $id = $workitem['id'] ?? '(unknown)';
    $retries = (int)($workitem['retries'] ?? 0);
    $client->info("Processing workitem id {$id}, retry #{$retries}");

    if (!isset($workitem['payload']) || !is_array($workitem['payload'])) {
        $workitem['payload'] = [];
    }
    $workitem['payload']['name'] = 'Hello kitty';
    $workitem['name'] = 'Hello kitty';

    // Simulate producing an output file
    file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'hello.txt', 'Hello kitty');

    // Simulate async processing delay
    usleep(2_000_000); // 2 seconds
}

function ProcessWorkitemWrapper(Client $client, array $originalFiles, array $workitem): void
{
    try {
        ProcessWorkitem($client, $workitem);
        $workitem['state'] = 'successful';
    } catch (\Throwable $error) {
        $workitem['state'] = 'retry';
        $workitem['errortype'] = 'application';
        $workitem['errormessage'] = $error->getMessage();
        $workitem['errorsource'] = $error->getTraceAsString();
        $client->error($error->getMessage());
    }

    $currentFiles = lstat_dir(__DIR__);
    $filesAdd = array_values(array_diff($currentFiles, $originalFiles));
    if (count($filesAdd) > 0) {
        $client->update_workitem(['workitem' => $workitem, 'files' => $filesAdd]);
    } else {
        $client->update_workitem(['workitem' => $workitem]);
    }
}

$originalFiles = [];
$working = false;

function on_queue_message(Client $client): void
{
    global $working, $originalFiles;
    if ($working) {
        return;
    }
    $working = true;
    try {
        $defaultwiq = 'default_queue';
        $wiq = getenv('wiq') ?: (getenv('SF_AMQPQUEUE') ?: $defaultwiq);
        $queue = getenv('queue') ?: $wiq;

        $counter = 0;
        do {
            $workitem = $client->pop_workitem(['wiq' => $wiq]);
            if ($workitem) {
                $counter++;
                ProcessWorkitemWrapper($client, $originalFiles, $workitem);
                cleanupFiles($originalFiles);
            }
        } while ($workitem);

        if ($counter > 0) {
            $client->info("No more workitems in {$wiq} workitem queue");
        }
        $vmid = getenv('SF_VMID');
        if (!empty($vmid)) {
            $client->info("Exiting application as running in serverless VM {$vmid}");
            // In serverless, exiting ends the VM lifecycle
            exit(0);
        }
    } catch (\Throwable $error) {
        $client->error($error->getMessage());
    } finally {
        cleanupFiles($originalFiles);
        $working = false;
    }
}

function onConnected(Client $client): void
{
    try {
        $defaultwiq = 'default_queue';
        $wiq = getenv('wiq') ?: (getenv('SF_AMQPQUEUE') ?: $defaultwiq);
        $queue = getenv('queue') ?: $wiq;
        $queuename = $client->register_queue(['queuename' => $queue], function () use ($client) {
            on_queue_message($client);
        });
        $client->info("Consuming message queue: {$queuename}");

        $vmid = getenv('SF_VMID');
        if (!empty($vmid)) {
            on_queue_message($client);
        }
    } catch (\Throwable $error) {
        $client->error($error->getMessage());
        exit(0);
    }
}

function main(): void
{
    global $originalFiles;
    $client = new Client();
    try {
        $client->enable_tracing('openiap=info', '');
        $originalFiles = lstat_dir(__DIR__);

        $client->on_client_event(function (?array $event) use ($client) {
            if ($event && ($event['event'] ?? '') === 'SignedIn') {
                onConnected($client);
            }
        });

        $client->connect();

        // Keep process alive if running as daemon (no SF_VMID)
        if (empty(getenv('SF_VMID'))) {
            // Simple idle loop; in real impl, the queue subscription would block/callback.
            while (true) {
                sleep(5);
            }
        }
    } catch (\Throwable $error) {
        $client->error($error->getMessage());
    }
}

main();
