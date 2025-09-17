## Python Coding Conventions

- Always use **double quotes** for strings.
- When generating Python, **follow these conventions** strictly.
- Use proper Python naming conventions: snake_case for variables and functions, PascalCase for classes.

## Data Access

All data must be stored in **OpenIAP's MongoDB database** using the official Python client library.

Start by declaring and connecting the client:

```python
from openiap import Client
import asyncio

client = Client()
await client.connect()
```

## Connection Lifecycle

If you're writing code that needs to reinitialize state when reconnecting (e.g., registering a queue or setting up a watch), wrap it in an `on_connected` function and hook into the client event stream:
This **MUST** be called after connect(), do not worry, you will still get the initial `Connected` and `SignedIn`

```python
await client.connect()

def on_client_event(event):
    if event and event.get("event") == "SignedIn":
        asyncio.create_task(on_connected())

client.on_client_event(on_client_event)

async def on_connected():
    try:
        queue_name = await client.register_queue(queue_name="", callback=handle_queue_message)
    except Exception as error:
        client.error(error)

async def handle_queue_message(event):
    # Handle incoming event
    pass
```

## API Reference

Use the following methods when interacting with the OpenIAP platform:

### Database Operations

```python
await client.query(collectionname="", query="", projection="", orderby="", skip=0, top=100, queryas="", explain=False)
await client.aggregate(collectionname="", aggregates=[], queryas="", hint="", explain=False)
await client.count(collectionname="", query="", queryas="", explain=False)
await client.distinct(collectionname="", field="", query="", queryas="", explain=False)
await client.insert_one(collectionname="", item={}, w=1, j=False)
await client.insert_many(collectionname="", items=[], w=1, j=False, skipresults=False)
await client.update_one(collectionname="", item={}, w=1, j=False)
await client.insert_or_update_one(collectionname="", item={}, uniqeness="_id", w=1, j=False)
await client.delete_one(collectionname="", id="")
await client.delete_many(collectionname="", query="")
```

### Collection & Index Management

```python
await client.list_collections()
await client.create_collection(collectionname="")
await client.drop_collection(collectionname="")
await client.get_indexes(collectionname="")
await client.create_index(collectionname="", index={}, options={}, name="")
await client.drop_index(collectionname="", indexname="")
```

### Authentication

```python
await client.signin(username="", password="", jwt="", agent="", version="", longtoken=False, validateonly=False, ping=False)
await client.connect()
await client.disconnect()
```

### File Transfer

```python
await client.upload(filepath="", filename="", mimetype="", metadata={}, collectionname="")
await client.download(collectionname="", id="", folder="", filename="")
```

### Work Items
Work item queues contains a list of "units of work", something that needs to be processed. Items start in state "new", and when we pop an item, the server updates its state to "processing", therefore it's VITAL that we always update the workitem's state to either "retry" if there was an error, or "successful" if there was no error and call update_workitem to save it.

```python
await client.push_workitem(wiq="", wiqid="", name="", payload="{}", nextrun=0, success_wiqid="", failed_wiqid="", success_wiq="", failed_wiq="", priority=2, files=[])
await client.pop_workitem(wiq="", wiqid="", downloadfolder=".")
await client.update_workitem(workitem={}, ignoremaxretries=False, files=[])
await client.delete_workitem(id="")
```

### Events & Messaging

```python
# Register a watch (change stream), and calls callback everytime an object is inserted, updated or deleted
await client.watch(collectionname="", paths=[], callback=watch_callback)  # callback({"id": "", "operation": "", "document": {}}, event_counter)
await client.unwatch(watchid="")

# Register a queue and handle incoming messages, returns queuename used for receiving messages
await client.register_queue(queuename="", callback=queue_callback)  # callback({"queuename": "", "correlation_id": "", "replyto": "", "routingkey": "", "exchangename": "", "data": {}})

# Register an exchange and handle incoming messages, returns queuename used for receiving messages
await client.register_exchange(exchangename="", algorithm="", routingkey="", addqueue=True, callback=exchange_callback)  # callback({"queuename": "", "correlation_id": "", "replyto": "", "routingkey": "", "exchangename": "", "data": {}})

await client.unregister_queue(queuename="")

# Sends a message to a message queue or exchange, queuename or exchangename is mandatory, so is data
await client.queue_message(queuename="", data={}, replyto="", exchangename="", correlation_id="", routingkey="", striptoken=False, expiration=0)

# Sends a message to a message queue or exchange, and waits for a reply, and returns it
await client.rpc(queuename="", data={}, striptoken=False)
```

### Helpers

```python
# call custom commands, not added to the official API yet, these might change over time and will not be backward compatible 
await client.custom_command(command="", id="", name="", data="")  # data must be a JSON string or ""

client.on_client_event(callback)
client.off_client_event(eventid)

client.uniqeid()
client.format_bytes(bytes_value, decimals=2)  # used to format a number to b/MB/GB etc.
client.stringify(obj)  # Better error messages than json.dumps when input is malformed

# Create an observable gauge, that can be used to create custom graphs in grafana, like keeping track of items processed or users online etc.
# the client will automatically keep reporting the last set name, until you call disable_observable_gauge
client.set_f64_observable_gauge(name="", value=0.0, description="")
client.set_u64_observable_gauge(name="", value=0, description="")
client.set_i64_observable_gauge(name="", value=0, description="")
client.disable_observable_gauge(name="")

client.enable_tracing("openiap=info")  # Always call this early to enable logging, other options are openiap=error, openiap=debug or openiap=trace
client.disable_tracing()

client.info(...)  # Use this instead of print()
client.error(...)  # Use this instead of print() for errors
client.verbose(...)  # Use this instead of print() for debug
client.trace(...)
```

## Logging

**Never use** `print()` or standard logging functions.

Instead, use the OpenIAP logging functions:

- `client.info(...)`
- `client.warn(...)`
- `client.error(...)`
- `client.verbose(...)`
- `client.trace(...)`

## Error Handling

Always wrap OpenIAP operations in try-except blocks:

```python
try:
    result = await client.query(collectionname="users", query="{}")
    client.info(f"Query returned {len(result)} results")
except Exception as error:
    client.error(f"Query failed: {error}")
    # Handle error appropriately
```

## Async/Await Patterns

All OpenIAP operations are asynchronous. Always use `await` and ensure your functions are marked as `async`:

```python
async def main():
    client = Client()
    try:
        await client.connect()
        # Your code here
    except Exception as error:
        client.error(f"Connection failed: {error}")
    finally:
        await client.disconnect()

if __name__ == "__main__":
    asyncio.run(main())
```

## Type Hints

Use type hints for better code documentation and IDE support:

```python
from typing import Dict, List, Any, Optional

async def process_data(items: List[Dict[str, Any]]) -> Optional[Dict[str, Any]]:
    try:
        result = await client.insert_many(collectionname="data", items=items)
        return result
    except Exception as error:
        client.error(f"Failed to process data: {error}")
        return None
```
