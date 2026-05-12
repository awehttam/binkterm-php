# Matterbridge

Matterbridge support lets local BinktermPHP chat rooms relay outbound messages into other chat platforms through Matterbridge's API gateway.

## What It Covers

- Global Matterbridge API settings stored in `config/matterbridge.json`
- Per-room bridge settings on **Admin -> Chat Rooms**
- A reusable PHP service for sending local chat messages and relaying them through Matterbridge

This integration currently relays **outbound local room messages**. Direct messages are not bridged.

## Configure Matterbridge

1. Enable Matterbridge's API gateway in your Matterbridge config.
2. Note the API base URL and gateway name you want to target.
3. In BinktermPHP, open **Admin -> Chat Rooms**.
4. In the **Matterbridge Bridge Settings** panel:
   - enable Matterbridge relay
   - set the API base URL, for example `http://127.0.0.1:4240`
   - set the API token if your Matterbridge instance requires one
   - save the settings
5. Edit each local room that should relay outward:
   - enable Matterbridge for that room
   - set the room's Matterbridge gateway name
   - optionally set a username template

## Per-Room Settings

Each chat room can define:

- `matterbridge_enabled` - whether outbound room messages should be relayed
- `matterbridge_gateway` - the Matterbridge API gateway name for that room
- `matterbridge_options.username_template` - optional username formatting for remote messages

If no per-room username template is set, the global username suffix from `config/matterbridge.json` is appended to the local username.

## PHP Usage

Use `BinktermPHP\Chat\ChatMessageService` when application code needs to send local chat messages or talk to Matterbridge directly.

```php
$service = new \BinktermPHP\Chat\ChatMessageService();

// Send a local room message. If the room is bridged, it also relays through Matterbridge.
$service->sendMessage($fromUserId, $roomId, null, 'Hello from BinktermPHP');

// Send directly to a Matterbridge gateway without creating a local chat message.
$service->sendMatterbridgeMessage('discord-gateway', 'Maintenance starts in 10 minutes.', 'System');
```

## Matterbridge API Notes

This integration uses Matterbridge's API gateway `POST /api/message` endpoint. When a token is configured, BinktermPHP sends it as a bearer token.
