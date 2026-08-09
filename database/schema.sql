CREATE DATABASE IF NOT EXISTS paperbell CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE paperbell;

CREATE TABLE IF NOT EXISTS app_meta (meta_key VARCHAR(100) PRIMARY KEY, meta_value TEXT NOT NULL) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS orders (
  order_sn VARCHAR(100) PRIMARY KEY, status VARCHAR(80) NOT NULL DEFAULT '', create_time BIGINT NOT NULL DEFAULT 0,
  update_time BIGINT NOT NULL DEFAULT 0, buyer_username VARCHAR(255) NOT NULL DEFAULT '', raw_json LONGTEXT NOT NULL,
  packaged TINYINT(1) NOT NULL DEFAULT 0, packaged_at BIGINT NULL,
  INDEX ix_orders_created(create_time), INDEX ix_orders_status(status), INDEX ix_orders_buyer(buyer_username)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS order_process (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, order_sn VARCHAR(100) NOT NULL, order_item_id VARCHAR(150) NOT NULL, item_key VARCHAR(255) NOT NULL DEFAULT '',
  model_sku VARCHAR(255) NOT NULL DEFAULT '', item_sku VARCHAR(255) NOT NULL DEFAULT '', item_name TEXT NOT NULL, model_name TEXT NOT NULL,
  qty INT NOT NULL DEFAULT 0, status VARCHAR(80) NOT NULL DEFAULT '', create_time BIGINT NOT NULL DEFAULT 0, saved_at BIGINT NOT NULL DEFAULT 0,
  printed TINYINT(1) NOT NULL DEFAULT 0, printed_odd TINYINT(1) NOT NULL DEFAULT 0, printed_even TINYINT(1) NOT NULL DEFAULT 0, printed_at BIGINT NULL,
  UNIQUE KEY uq_order_line(order_sn,order_item_id), INDEX ix_lines_order(order_sn), INDEX ix_lines_item(item_key)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS order_resi (
  order_sn VARCHAR(100) PRIMARY KEY, pdf_path TEXT NOT NULL, tracking_number VARCHAR(150) NOT NULL DEFAULT '', fetched_at BIGINT NULL,
  resi_printed TINYINT(1) NOT NULL DEFAULT 0, resi_printed_at BIGINT NULL
) ENGINE=InnoDB;
ALTER TABLE order_resi ADD COLUMN IF NOT EXISTS tracking_number VARCHAR(150) NOT NULL DEFAULT '' AFTER pdf_path;
CREATE TABLE IF NOT EXISTS product_inventory (
  item_key VARCHAR(255) PRIMARY KEY, model_sku VARCHAR(255) NOT NULL DEFAULT '', item_sku VARCHAR(255) NOT NULL DEFAULT '',
  item_name TEXT NOT NULL, model_name TEXT NOT NULL, no_ref VARCHAR(255) NOT NULL DEFAULT '', sku_induk VARCHAR(255) NOT NULL DEFAULT '',
  qty INT NOT NULL DEFAULT 0, updated_at BIGINT NOT NULL DEFAULT 0, INDEX ix_inventory_qty(qty)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_movements (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, item_key VARCHAR(255) NOT NULL, movement_type VARCHAR(30) NOT NULL,
  qty_delta INT NOT NULL, qty_after INT NOT NULL, order_sn VARCHAR(100) NOT NULL DEFAULT '',
  note VARCHAR(500) NOT NULL DEFAULT '', created_by VARCHAR(100) NOT NULL DEFAULT '', created_at BIGINT NOT NULL,
  INDEX ix_inventory_movements_item(item_key,created_at), INDEX ix_inventory_movements_order(order_sn)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS data_mappings (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  sku_id VARCHAR(255) NOT NULL, product_name TEXT NOT NULL, variation_name TEXT NOT NULL,
  group_name VARCHAR(50) NOT NULL DEFAULT '', product_code VARCHAR(100) NOT NULL DEFAULT '',
  variant_1 VARCHAR(100) NOT NULL DEFAULT '', variant_2 VARCHAR(100) NOT NULL DEFAULT '',
  duplex VARCHAR(30) NOT NULL DEFAULT '', paper VARCHAR(30) NOT NULL DEFAULT '',
  page_from INT NOT NULL DEFAULT 1, page_to INT NOT NULL DEFAULT 1, copies INT NOT NULL DEFAULT 1,
  file_path TEXT NOT NULL, parent_sku VARCHAR(255) NOT NULL DEFAULT '', variation VARCHAR(255) NOT NULL DEFAULT '',
  printer VARCHAR(255) NOT NULL DEFAULT '', search_product VARCHAR(255) NOT NULL DEFAULT '',
  search_variant VARCHAR(255) NOT NULL DEFAULT '', search_alias VARCHAR(255) NOT NULL DEFAULT '',
  imported_at BIGINT NOT NULL, UNIQUE KEY uq_mapping_sku(sku_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS mapping_aliases (
  alias_key VARCHAR(510) PRIMARY KEY, mapping_id BIGINT NOT NULL,
  INDEX ix_mapping_alias_mapping(mapping_id),
  CONSTRAINT fk_mapping_alias_mapping FOREIGN KEY(mapping_id) REFERENCES data_mappings(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS print_jobs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, job_type VARCHAR(30) NOT NULL, order_sn VARCHAR(100) NOT NULL DEFAULT '',
  order_process_id BIGINT NULL, file_path TEXT NOT NULL, printer VARCHAR(255) NOT NULL,
  print_settings VARCHAR(500) NOT NULL, copies INT NOT NULL DEFAULT 1,
  status VARCHAR(30) NOT NULL DEFAULT 'queued', message TEXT NOT NULL, error TEXT NOT NULL,
  created_by VARCHAR(100) NOT NULL DEFAULT '', created_at BIGINT NOT NULL, started_at BIGINT NULL,
  completed_at BIGINT NULL, submitted_at BIGINT NULL, spooler_job_id INT NULL, attempts INT NOT NULL DEFAULT 0,
  INDEX ix_print_jobs_status(status,id), INDEX ix_print_jobs_order(order_sn)
) ENGINE=InnoDB;
ALTER TABLE print_jobs ADD COLUMN IF NOT EXISTS submitted_at BIGINT NULL AFTER completed_at;
ALTER TABLE print_jobs ADD COLUMN IF NOT EXISTS spooler_job_id INT NULL AFTER submitted_at;

CREATE TABLE IF NOT EXISTS printer_incidents (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  incident_key CHAR(64) NOT NULL, active_key CHAR(64) NULL,
  incident_type VARCHAR(50) NOT NULL, severity VARCHAR(20) NOT NULL DEFAULT 'error',
  printer VARCHAR(255) NOT NULL DEFAULT '', print_job_id BIGINT NULL, spooler_job_id INT NULL,
  title VARCHAR(255) NOT NULL, technical_message TEXT NOT NULL, guidance TEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending', observed_count INT NOT NULL DEFAULT 1,
  healthy_count INT NOT NULL DEFAULT 0, acknowledged_at BIGINT NULL, acknowledged_by VARCHAR(100) NOT NULL DEFAULT '',
  host_notified_at BIGINT NULL, first_seen_at BIGINT NOT NULL, last_seen_at BIGINT NOT NULL, resolved_at BIGINT NULL,
  UNIQUE KEY uq_printer_incident_active(active_key),
  INDEX ix_printer_incidents_status(status,last_seen_at), INDEX ix_printer_incidents_job(print_job_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS manual_pdfs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, original_name VARCHAR(255) NOT NULL, file_path TEXT NOT NULL,
  file_size BIGINT NOT NULL DEFAULT 0, page_count INT NOT NULL DEFAULT 0, source_type VARCHAR(30) NOT NULL DEFAULT 'manual',
  summary TEXT NOT NULL, created_by VARCHAR(100) NOT NULL DEFAULT '', created_at BIGINT NOT NULL,
  INDEX ix_manual_pdfs_created(created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS printer_settings (
  setting_key VARCHAR(100) PRIMARY KEY,
  setting_value LONGTEXT NOT NULL,
  updated_at BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS oauth_connections (
  provider VARCHAR(30) PRIMARY KEY,
  config_encrypted LONGTEXT NOT NULL,
  access_token_encrypted LONGTEXT NOT NULL,
  refresh_token_encrypted LONGTEXT NOT NULL,
  account_id VARCHAR(255) NOT NULL DEFAULT '',
  account_name VARCHAR(255) NOT NULL DEFAULT '',
  metadata_encrypted LONGTEXT NOT NULL,
  access_expires_at BIGINT NOT NULL DEFAULT 0,
  refresh_expires_at BIGINT NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'disconnected',
  last_error TEXT NOT NULL,
  connected_at BIGINT NOT NULL DEFAULT 0,
  updated_at BIGINT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS oauth_states (
  state_hash CHAR(64) PRIMARY KEY,
  provider VARCHAR(30) NOT NULL,
  redirect_uri TEXT NOT NULL,
  created_by VARCHAR(100) NOT NULL DEFAULT '',
  expires_at BIGINT NOT NULL,
  created_at BIGINT NOT NULL,
  INDEX ix_oauth_states_expiry(expires_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shopee_escrow_details (
  order_sn VARCHAR(64) PRIMARY KEY,
  order_create_time BIGINT NOT NULL,
  order_status VARCHAR(50) NOT NULL DEFAULT '',
  currency VARCHAR(10) NOT NULL DEFAULT 'IDR',
  gross_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  payout_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
  commission_fee DECIMAL(18,2) NOT NULL DEFAULT 0,
  service_fee DECIMAL(18,2) NOT NULL DEFAULT 0,
  processing_fee DECIMAL(18,2) NOT NULL DEFAULT 0,
  ads_fee DECIMAL(18,2) NOT NULL DEFAULT 0,
  campaign_fee DECIMAL(18,2) NOT NULL DEFAULT 0,
  shipping_adjustment DECIMAL(18,2) NOT NULL DEFAULT 0,
  total_marketplace_fee DECIMAL(18,2) NOT NULL DEFAULT 0,
  raw_json LONGTEXT NOT NULL,
  synced_at BIGINT NOT NULL,
  INDEX ix_shopee_escrow_created(order_create_time),
  INDEX ix_shopee_escrow_synced(synced_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shopee_shop_stats_monthly (
  month_start DATE PRIMARY KEY,
  month_end DATE NOT NULL,
  order_status VARCHAR(50) NOT NULL DEFAULT 'Pesanan Dibuat',
  source_file VARCHAR(255) NOT NULL,
  sales DECIMAL(18,2) NOT NULL DEFAULT 0,
  orders_count INT NOT NULL DEFAULT 0,
  aov DECIMAL(18,2) NOT NULL DEFAULT 0,
  clicks INT NOT NULL DEFAULT 0,
  visitors INT NOT NULL DEFAULT 0,
  conversion_rate DECIMAL(9,6) NOT NULL DEFAULT 0,
  cancelled_orders INT NOT NULL DEFAULT 0,
  cancelled_sales DECIMAL(18,2) NOT NULL DEFAULT 0,
  returned_orders INT NOT NULL DEFAULT 0,
  returned_sales DECIMAL(18,2) NOT NULL DEFAULT 0,
  buyers INT NOT NULL DEFAULT 0,
  new_buyers INT NOT NULL DEFAULT 0,
  existing_buyers INT NOT NULL DEFAULT 0,
  potential_buyers INT NOT NULL DEFAULT 0,
  repeat_rate DECIMAL(9,6) NOT NULL DEFAULT 0,
  product_page_sales DECIMAL(18,2) NOT NULL DEFAULT 0,
  live_sales DECIMAL(18,2) NOT NULL DEFAULT 0,
  video_sales DECIMAL(18,2) NOT NULL DEFAULT 0,
  affiliate_sales DECIMAL(18,2) NOT NULL DEFAULT 0,
  ads_attributed_sales DECIMAL(18,2) NOT NULL DEFAULT 0,
  ads_name VARCHAR(255) NOT NULL DEFAULT '',
  ads_spend DECIMAL(18,2) NOT NULL DEFAULT 0,
  ads_roas DECIMAL(12,4) NOT NULL DEFAULT 0,
  ads_impressions INT NOT NULL DEFAULT 0,
  ads_orders DECIMAL(12,2) NOT NULL DEFAULT 0,
  ads_conversion_rate DECIMAL(9,6) NOT NULL DEFAULT 0,
  imported_at BIGINT NOT NULL,
  INDEX ix_shopee_shop_stats_period(month_end)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shopee_shop_stats_daily (
  stat_date DATE PRIMARY KEY,
  month_start DATE NOT NULL,
  source_file VARCHAR(255) NOT NULL,
  sales DECIMAL(18,2) NOT NULL DEFAULT 0,
  orders_count INT NOT NULL DEFAULT 0,
  aov DECIMAL(18,2) NOT NULL DEFAULT 0,
  clicks INT NOT NULL DEFAULT 0,
  visitors INT NOT NULL DEFAULT 0,
  conversion_rate DECIMAL(9,6) NOT NULL DEFAULT 0,
  cancelled_orders INT NOT NULL DEFAULT 0,
  cancelled_sales DECIMAL(18,2) NOT NULL DEFAULT 0,
  returned_orders INT NOT NULL DEFAULT 0,
  returned_sales DECIMAL(18,2) NOT NULL DEFAULT 0,
  buyers INT NOT NULL DEFAULT 0,
  new_buyers INT NOT NULL DEFAULT 0,
  existing_buyers INT NOT NULL DEFAULT 0,
  potential_buyers INT NOT NULL DEFAULT 0,
  repeat_rate DECIMAL(9,6) NOT NULL DEFAULT 0,
  imported_at BIGINT NOT NULL,
  INDEX ix_shopee_shop_daily_month(month_start),
  CONSTRAINT fk_shopee_shop_daily_month FOREIGN KEY(month_start) REFERENCES shopee_shop_stats_monthly(month_start) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shopee_shop_stats_products (
  month_start DATE NOT NULL,
  product_code VARCHAR(50) NOT NULL,
  product_name VARCHAR(255) NOT NULL,
  sales DECIMAL(18,2) NOT NULL DEFAULT 0,
  sales_share DECIMAL(9,6) NOT NULL DEFAULT 0,
  orders_attributed DECIMAL(12,2) NOT NULL DEFAULT 0,
  units INT NOT NULL DEFAULT 0,
  clicks INT NOT NULL DEFAULT 0,
  conversion_rate DECIMAL(9,6) NOT NULL DEFAULT 0,
  aov DECIMAL(18,2) NOT NULL DEFAULT 0,
  rank_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY(month_start,product_code),
  INDEX ix_shopee_shop_products_rank(month_start,rank_order),
  CONSTRAINT fk_shopee_shop_products_month FOREIGN KEY(month_start) REFERENCES shopee_shop_stats_monthly(month_start) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shopee_shop_stats_traffic_sources (
  month_start DATE NOT NULL,
  source_name VARCHAR(100) NOT NULL,
  sales DECIMAL(18,2) NOT NULL DEFAULT 0,
  sales_share DECIMAL(9,6) NOT NULL DEFAULT 0,
  clicks INT NOT NULL DEFAULT 0,
  orders_attributed DECIMAL(12,2) NOT NULL DEFAULT 0,
  conversion_rate DECIMAL(9,6) NOT NULL DEFAULT 0,
  aov DECIMAL(18,2) NOT NULL DEFAULT 0,
  rank_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY(month_start,source_name),
  INDEX ix_shopee_shop_traffic_rank(month_start,rank_order),
  CONSTRAINT fk_shopee_shop_traffic_month FOREIGN KEY(month_start) REFERENCES shopee_shop_stats_monthly(month_start) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS shopee_shop_stats_attribution (
  month_start DATE NOT NULL,
  channel_name VARCHAR(100) NOT NULL,
  sales DECIMAL(18,2) NOT NULL DEFAULT 0,
  rank_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY(month_start,channel_name),
  INDEX ix_shopee_shop_attribution_rank(month_start,rank_order),
  CONSTRAINT fk_shopee_shop_attribution_month FOREIGN KEY(month_start) REFERENCES shopee_shop_stats_monthly(month_start) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sync_runs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  provider VARCHAR(30) NOT NULL,
  status VARCHAR(30) NOT NULL,
  orders_count INT NOT NULL DEFAULT 0,
  message TEXT NOT NULL,
  created_by VARCHAR(100) NOT NULL DEFAULT '',
  started_at BIGINT NOT NULL,
  completed_at BIGINT NULL,
  INDEX ix_sync_runs_started(started_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS label_fetch_jobs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, order_sn VARCHAR(100) NOT NULL, provider VARCHAR(30) NOT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'queued', message TEXT NOT NULL, error TEXT NOT NULL,
  created_by VARCHAR(100) NOT NULL DEFAULT '', created_at BIGINT NOT NULL, available_at BIGINT NOT NULL,
  started_at BIGINT NULL, completed_at BIGINT NULL, attempts INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_label_fetch_order(order_sn), INDEX ix_label_fetch_queue(status,available_at,id)
) ENGINE=InnoDB;
