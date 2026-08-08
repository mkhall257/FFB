# Draft room uses short-polling, not WebSockets

The shared, near-real-time draft room is built on HTTP short-polling (clients poll a PHP endpoint every 1–2s against MySQL), not WebSockets or any persistent-connection push. WebSockets would give true instant push, but reliable WebSocket/persistent-process support is not confirmed on the ICDSoft plan (see ADR-0002), whereas polling works within plain PHP + MySQL. For a single league of 4–10 Managers drafting together, polling feels live enough. The accepted trade-off is more DB load during the Draft and no instant-push guarantee.
