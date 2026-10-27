<?php
// Session-local tables: tests never modify live order tracking or read receipts.
$db->exec('CREATE TEMPORARY TABLE order_status_observations(order_sn VARCHAR(100) PRIMARY KEY,status VARCHAR(50),status_since BIGINT,source_updated BIGINT,observed_at BIGINT)');
$db->exec('CREATE TEMPORARY TABLE order_stall_alerts(order_sn VARCHAR(100) PRIMARY KEY,status VARCHAR(50),status_since BIGINT,business_seconds BIGINT,checked_at BIGINT,episode_key CHAR(64))');
$db->exec('CREATE TEMPORARY TABLE order_stall_reads(reader_key VARCHAR(100),episode_key CHAR(64),read_at BIGINT,PRIMARY KEY(reader_key,episode_key))');
