#!/usr/bin/env php
<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(0);
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');

// Early boot log to verify container starts
fwrite(STDOUT, "[BOOT] Starting Php Workitem Agent\n");
fflush(STDOUT);

// Crash visibility
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, "[EXCEPTION] " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    fflush(STDERR);
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        fwrite(STDERR, "[FATAL] {$e['message']} in {$e['file']}:{$e['line']}\n");
        fflush(STDERR);
    }
});

// Load Composer dependencies
 require_once __DIR__ . '/vendor/autoload.php';

// OpenIAP client will be loaded from composer autoload

// Bind the OpenIAP PHP Client class name to `Client` to mirror Node.js usage
if (class_exists('OpenIAP\Client')) {
    class_alias('OpenIAP\Client', 'Client');
} elseif (class_exists('OpenIAP\SDK\Client')) {
    class_alias('OpenIAP\SDK\Client', 'Client');
} elseif (class_exists('openiap\client\Client')) {
    class_alias('openiap\client\Client', 'Client');
} else {
    throw new RuntimeException('OpenIAP PHP client not found (openiap/client).');
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

// Keep helpers minimal; mirror Node structure by inlining queue resolution and processing

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
        // Node semantics
        $wiq = getenv('wiq') ?: (getenv('SF_AMQPQUEUE') ?: 'default_queue');
        $queue = getenv('queue') ?: $wiq;
        $client->info("Resolved queues: wiq={$wiq}, queue={$queue}");

        $counter = 0;
        do {
            $workitem = $client->pop_workitem(['wiq' => $wiq]);
            if ($workitem) {
                $counter++;
                // Inline ProcessWorkitem + wrapper
                try {
                    $id = $workitem['id'] ?? '(unknown)';
                    $retries = (int)($workitem['retries'] ?? 0);
                    $client->info("Processing workitem id {$id}, retry #{$retries}");

                    if (!isset($workitem['payload']) || !is_array($workitem['payload'])) {
                        $workitem['payload'] = [];
                    }
                    $workitem['payload']['name'] = 'Hello kitty';
                    $workitem['name'] = 'Hello kitty';

                    file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'hello.txt', 'Hello kitty');
                    usleep(2_000_000); // 2s

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
        $wiq = getenv('wiq') ?: (getenv('SF_AMQPQUEUE') ?: 'default_queue');
        $queue = getenv('queue') ?: $wiq;
        $client->info("Resolved queues: wiq={$wiq}, queue={$queue}");
        $client->info("Registering queue consumer for: {$queue}");
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
