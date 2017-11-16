# lightwebsocket
Lightweight WebSocket library based on Ratchet
### How it works

It simply creates a WebSocket server listening on port 8087, (should make this configurable).
This Server waits for incoming messages with pre-defined patterns.

### Supported Messages
#### createChannel
This message tells the server to manage a new channel (all users subscribing to this channel will receive message sent to this channel)

#### subscribe
Subscribes a resourceId to a channel

#### unsubscribe
Unsubscribes a resourceId from a channel (or all channels if '*' is specified)

#### communication
Base message which is just propagated to the destintation channel

### TODO
- log method should be fixed, it now uses an absolute path.
- port should be configurable
