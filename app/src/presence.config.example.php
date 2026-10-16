<?php
// Copy to presence.config.php in this directory on BOTH applications.
// The directory must refer to the SAME private storage used by the Node service.
return [
    'enabled' => true,
    'directory' => 'D:/EIS-Presence/storage',
    'websocket_url' => 'wss://doc-eis.nmu.ac.th/eis-docs/presence',
];
