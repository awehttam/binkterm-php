# Joining and Configuring an FTN

This guide explains how to join a FidoNet Technology Network (FTN) and configure BinktermPHP to exchange netmail, echomail, and files with that network.

Most FTNs are coordinated by a hub or network coordinator. You generally request a node address from the coordinator, receive uplink connection details, then configure those details in BinktermPHP.

> **LovlyNet note:** LovelyNet uses its own joining and registration workflow. If you are joining LovelyNet, follow [LovlyNet Network Integration](LovlyNet.md) instead of the generic process below.

---

## Before You Join

Before contacting a coordinator, decide how your system will connect.

### Public Node

A public node accepts inbound binkp connections from the network hub.

Use this if:

- Your BBS has a stable public hostname or IP address.
- You can forward or expose the binkp port, usually TCP `24554`.
- Your firewall allows inbound binkp traffic.

Public nodes can receive mail as soon as the uplink connects, but they require working inbound network access.

### Poll-Only Node

A poll-only node does not accept inbound binkp connections. Instead, your system periodically connects outbound to the hub and collects waiting mail.

Use this if:

- Your BBS is behind NAT without port forwarding.
- Your IP address changes often.
- Your hosting provider or firewall blocks inbound ports.

Poll-only nodes are fully usable, but mail delivery depends on the polling schedule you configure.

---

## Information to Request

When joining a network, ask the hub or coordinator for these details:

| Item | Description |
|------|-------------|
| Your FTN address | The address assigned to your system, such as `21:1/123` or `1:153/757`. |
| Hub FTN address | The node address of your uplink or hub. |
| Hub hostname | DNS name or IP address used for binkp connections. |
| Hub binkp port | Usually `24554`, but some networks use a custom port. |
| Session password | The binkp password used when connecting to the hub. |
| Network domain | A short domain identifier for this FTN in BinktermPHP, such as `fidonet`, `fsxnet`, or another lowercase name. |
| AreaFix password | Password used to manage echomail subscriptions by netmail, if provided. |
| FileFix password | Password used to manage file-area subscriptions, if provided. |
| Available echo areas | Area tags and descriptions available from the network. |
| Available file areas | File area tags and descriptions, if the network distributes files. |

Keep passwords private. They allow another system to authenticate as your node or manage your subscriptions.

---

## Configure the Network

In BinktermPHP, each FTN should have a distinct network domain. The domain scopes echo areas and file areas so the same tag can exist independently on multiple networks.

1. Open **Admin -> Networks**.
2. Add or verify the network domain.
3. Use a short lowercase identifier, such as `fidonet`, `fsxnet`, or the network's preferred domain.
4. Save the network.

Avoid changing the domain after mail has started flowing. Echo areas and file areas are keyed by domain, so changing it later can make existing areas appear disconnected from the uplink.

---

## Configure the BinkP Uplink

Open **Admin -> BBS Settings -> Binkp Config** and add an uplink for the network.

Set these fields from the details provided by the hub:

| Field | Value |
|-------|-------|
| Local address / Me | Your assigned FTN address. |
| Remote address | The hub's FTN address. |
| Hostname | The hub hostname or IP address. |
| Port | The hub binkp port. |
| Password | Your binkp session password. |
| Domain | The network domain configured in **Admin -> Networks**. |
| Networks | Address patterns for the FTN, such as `21:*/*` or `1:*/*`. |
| Enabled | Turn this on when the settings are ready. |
| Poll schedule | A cron-style schedule if this system should poll automatically. |

If the network requires compression or encrypted sessions, enable those options only if the hub supports them and provided matching instructions.

After saving the uplink, restart or reload the relevant background services if your deployment does not do that automatically.

---

## Start or Verify Mailer Services

BinktermPHP uses the binkp server for inbound connections and the poller for outbound polling.

For public nodes:

1. Ensure `scripts/binkp_server.php` is running.
2. Confirm your firewall and router allow inbound TCP traffic to the configured binkp port.
3. Ask the hub to test a connection, or test from outside your local network.

For poll-only nodes:

1. Configure the uplink poll schedule in the BinkP settings, or run the poller from cron.
2. Poll often enough for the network's expected mail flow. Polling should generally run at least every 4 to 6 hours, but avoid polling more often than the hub recommends because frequent polls may trigger rate limiting.
3. Confirm outbound TCP connections to the hub are allowed.

You can manually poll from the command line:

```bash
php scripts/binkp_poll.php
```

Check `data/logs/binkp_poll.log`, `data/logs/binkp_server.log`, and `data/logs/packets.log` when troubleshooting mail flow.

---

## Subscribe to Echo Areas

Networks usually use AreaFix to manage echomail subscriptions.

If the hub provided an AreaFix password:

1. Send netmail to `AreaFix` at the hub's FTN address.
2. Put your AreaFix password in the subject.
3. Put one command per line in the message body.

Common AreaFix commands:

```text
%HELP
%LIST
%QUERY
+AREA_TAG
-AREA_TAG
%RESCAN AREA_TAG
```

Some networks automatically subscribe new nodes to default areas. Others require you to request every area manually.

When echomail arrives for an area that does not yet exist, BinktermPHP can auto-create the echo area using the incoming area tag and the uplink's domain. You can later edit descriptions, colors, local settings, and access controls in **Admin -> Echo Areas**.

See [Echo Areas](EchoAreas.md) and [AreaFix / FileFix](AreaFix.md) for more detail.

---

## Configure File Areas

If the FTN distributes files, ask the hub whether it uses FileFix or another file-area management process.

Typical setup:

1. Create or verify the file areas in **Admin -> File Areas**.
2. Use the same network domain assigned to the uplink.
3. Subscribe through FileFix if the hub provides FileFix access.
4. Confirm inbound files are processed into the expected file areas.

File distribution support varies by network. Some FTNs carry echomail only and do not operate a filebone.

See [File Areas](FileAreas.md), [FREQ](FREQ.md), and [AreaFix / FileFix](AreaFix.md) for related configuration.

---

## Send Test Traffic

After the uplink and subscriptions are configured:

1. Poll the hub manually.
2. Check for inbound netmail, echomail, or files.
3. Post a short test message in the network's test echo area, if one exists.
4. Poll again so the message is sent.
5. Ask another node or the hub to confirm receipt if needed.

For netmail testing, send a message to the coordinator or to a test address the network provides.

---

## Operating Multiple FTNs

BinktermPHP can participate in multiple FTNs at the same time.

Use a separate uplink and domain for each network:

- `fidonet` for a FidoNet uplink.
- `fsxnet` for an fsxNet uplink.
- `lovlynet` for LovlyNet.
- Any other domain requested or recommended by the network.

The domain is important because it keeps echo areas, file areas, and routing distinct. For example, `GENERAL@fidonet` and `GENERAL@anothernet` are separate areas even though the tag is the same.

---

## Troubleshooting

### No Incoming Mail

- Confirm the uplink is enabled.
- Confirm the configured domain matches the network and area setup.
- Check the binkp session password.
- Poll manually and inspect `data/logs/binkp_poll.log`.
- For public nodes, confirm the binkp server is reachable from the internet.
- Verify you are subscribed to at least one echo area.

### Outbound Messages Do Not Leave

- Confirm the echo area is not local-only.
- Confirm the echo area domain matches the uplink domain.
- Confirm the uplink is active and polling.
- Check `data/logs/packets.log` for packet processing errors.

### Authentication Fails

- Re-enter the binkp session password exactly as provided.
- Confirm your local FTN address matches the address assigned by the hub.
- Confirm the remote hub address is correct.
- Ask the hub sysop whether your node record is active.

### AreaFix Does Not Respond

- Confirm the netmail is addressed to `AreaFix` at the hub address.
- Confirm the subject contains the AreaFix password.
- Check whether the network uses different command names or a different robot address.
- Poll again after sending the request.

---

## Related Documentation

- [LovlyNet Network Integration](LovlyNet.md) — LovelyNet's self-service joining and management workflow.
- [Configuration Reference](CONFIGURATION.md) — Core environment and application configuration.
- [Echo Areas](EchoAreas.md) — Echomail area management.
- [File Areas](FileAreas.md) — File area management.
- [AreaFix / FileFix](AreaFix.md) — Subscription management by netmail.
- [FREQ](FREQ.md) — File request serving and requesting.
