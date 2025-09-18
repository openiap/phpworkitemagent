const { Client } = require("openiap");
const fs = require("fs");

const client = new Client();
client.enable_tracing("openiap=info", "");

// Default workitem queue
const defaultwiq = "default_queue";

function cleanupFiles(originalFiles) {
    const currentFiles = lstat();
    const filesToDelete = currentFiles.filter(file => !originalFiles.includes(file));
    filesToDelete.forEach(file => fs.unlinkSync(file));
}

async function ProcessWorkitem(workitem) {
    client.info(`Processing workitem id ${workitem.id}, retry #${workitem.retries}`);
    if (!workitem.payload) workitem.payload = {};
    workitem.payload.name = "Hello kitty";
    workitem.name = "Hello kitty";
    fs.writeFileSync("hello.txt", "Hello kitty");
    await new Promise(resolve => setTimeout(resolve, 2000)); // Simulate async processing
}

async function ProcessWorkitemWrapper(originalFiles, workitem) {
    try {
        await ProcessWorkitem(workitem);
        workitem.state = "successful";
    } catch (error) {
        workitem.state = "retry";
        workitem.errortype = "application"; // Retryable error
        workitem.errormessage = error.message || error;
        workitem.errorsource = error.stack || "Unknown source";
        client.error(error.message || error);
    }
    const currentFiles = lstat();
    const filesAdd = currentFiles.filter(file => !originalFiles.includes(file));
    if (filesAdd.length > 0) {
        client.update_workitem({ workitem, files: filesAdd });
    } else {
        client.update_workitem({ workitem });
    }
}
function lstat() {
    const originalFiles = fs.readdirSync(__dirname).filter(file => {
        let result = false;
        try {
            result = fs.lstatSync(file).isFile();            
        } catch (error) {            
        }
        return result;
    });
    return originalFiles;
}
let originalFiles = [];
let working = false;
async function on_queue_message() {
    if(working) {
        return;
    }
    try {
        let wiq = (process.env.wiq || process.env.SF_AMQPQUEUE) || defaultwiq;
        let queue = process.env.queue  || wiq;
        working = true;
        let workitem;
        let counter = 0;
        do {
            workitem = client.pop_workitem({ wiq });
            if (workitem) {
                counter++;
                await ProcessWorkitemWrapper(originalFiles, workitem);
                cleanupFiles(originalFiles);                        
            }
        } while (workitem);
        
        if (counter > 0) {
            client.info(`No more workitems in ${wiq} workitem queue`);
        }
        if (process.env.SF_VMID != null && process.env.SF_VMID != "") {
            client.info(`Exiting application as running in serverless VM ${process.env.SF_VMID}`);
            process.exit(0);
        }
    } catch (error) {
        client.error(error.message || error);
    } finally {
        cleanupFiles(originalFiles);
        working = false;
    }
}
async function onConnected() {
    try {
        let wiq = (process.env.wiq || process.env.SF_AMQPQUEUE) || defaultwiq;
        let queue = process.env.queue  || wiq;
        const queuename = client.register_queue({ queuename: queue }, on_queue_message);
        client.info(`Consuming message queue: ${queuename}`);
        if (process.env.SF_VMID != null && process.env.SF_VMID != "") {
            on_queue_message().catch(client.error);
        }
    } catch (error) {
        client.error(error.message || error);
        process.exit(0);
    }
}

async function main() {
    try {
        originalFiles = lstat();
        await client.connect();
        client.on_client_event(event => {
            if (event && event.event === "SignedIn") {
                onConnected().catch(client.error);
            }
        });
    } catch (error) {
        client.error(error.message || error);
    }
}

main();